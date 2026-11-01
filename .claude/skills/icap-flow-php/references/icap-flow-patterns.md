# icap-flow-spezifische Invarianten

Die verbindlichen Arbeitsregeln stehen in [AGENTS.md](../../../../AGENTS.md); diese Referenz beschreibt bestehende Muster.

## Fail-Secure-Statuscode-Auswertung

**Regel:** Jeder Statuscode, der nicht eindeutig „Clean“ bedeutet, wird zu einer typisierten Exception. Niemals stiller Fallback auf „Clean“.

Standardregeln (ohne `CicapVerdict`):
- `204 No Content` → clean.
- `200 OK` / `206` ohne Header der Virus-Header-Liste → clean, mit Header → `ScanResult` infiziert.
- `100 Continue` außerhalb des Preview-Flows → `IcapProtocolException` (Finding G der v2-Konsolidierung).
- `4xx` → `IcapClientException`, `5xx` → `IcapServerException`, jeweils mit dem Status als Code.
- Alles andere → `IcapProtocolException`.

Im verifizierten Modus entscheidet `CicapVerdict`: clean ist nur ein `204` auf die ganz gesendete Datei, jede Antwort ohne Scan-Beleg ist `IcapProtocolException` ([`docs/cicap-clamav.md`](../../../../docs/cicap-clamav.md)).

Die Logik lebt in `IcapClient::interpretResponse()`; `options()` hat `assertOptionsStatus()` (nur 200). Der Preview-Flow nutzt `executeRaw()` ohne Interpretation, weil `100 Continue` dort legitim ist: Die Antwort auf ein Teil-Preview entscheidet `previewResult()` (Strategie oder Verdikt), jede finale Antwort `interpretResponse()`. `executeRaw()` ist `protected` (seit 3.0.0), `IcapClient` ist `final`.

```php
// Falsch — stille Klassifikation
if ($code !== 204 && $code !== 200) {
    return new ScanResult(false, null, $response);   // ❌ Fail-Open
}

// Richtig — typisierte Exception
throw new IcapProtocolException('Unexpected ICAP status: ' . $code, $code);
```

## RFC 3507 §4.5 Preview-Continue

`IcapClient::scanFileWithPreview*()` verlangt einen `SessionAwareTransport` (`AsyncAmpTransport`, `SynchronousStreamTransport`); jeder andere ist am Aufruf eine `LogicException`, vor Datei und Verbindung. Preview + Continuation laufen in `scanFileWithPreviewStrict()` auf demselben Socket, **ein** logischer ICAP-Request, kein zweiter HTTP-Head; die Continuation sind nur die restlichen Chunks. Einen Zwei-Request-Fallback gibt es nicht. Tests: `tests/PreviewContinueStrictTest.php` (async, Socketpaar), `tests/Transport/SynchronousStreamTransportTest.php` (synchron, echte Sockets im Kindprozess).

Das Preview wird über `readPreview()` gelesen: 0..1 MiB (`MAX_PREVIEW_SIZE`), die Größe der offenen Datei (`LocalFile`), `ieof` erst nach beobachtetem EOF und passender Länge. Der Rest der Datei nach dem Preview und der Vollbody (`scanFile()`) gehen mit genau der angekündigten `Content-Length` raus; Unter- und Überlänge werfen vor dem Abschlusschunk (nicht bei einem Teil-Preview: `Preview` ohne `ieof`, seine `Content-Length` beschreibt den ganzen Body). `Preview: 0` geht mit `res-body` und `0\r\n\r\n` raus (`RequestFormatter::isEmptyPreview()`), nie als `null-body`. Ohne Größe nimmt der Client das `Preview` einer gecachten OPTIONS-Antwort (Dezimalzahl, auf 1 MiB gekappt), sonst 1024; ein Eintrag mit ungültigem `Preview` ist ein Miss.

## Header- und URI-Injection-Schutz

`RequestFormatter::format()` prüft jeden Request, bevor der erste Chunk entsteht: ICAP-Methode, URI (`IcapUri::authority()`), ICAP- und gekapselte HTTP-Header sowie HTTP-Request- und Statuszeile. Ungültige Metadaten werfen `\InvalidArgumentException`; kein Byte erreicht das Socket. Finding H der v2-Konsolidierung.

- `HeaderFields::validated()` — Namen sind Token (RFC 7230 §3.2.6), Werte Strings ohne Steuerzeichen außer HTAB. CR/LF werden abgewiesen, nicht „escaped“. `IcapClient::validateIcapHeaders()` wendet dieselbe Prüfung in den Scan-Helfern schon vor dem Öffnen der Datei an.
- `IcapUri::forService()` — Host, Port und Service-Pfad bleiben getrennte Komponenten: Host als Hostname oder IP-Adresse (IPv6 in Klammern), Service als absoluter Pfad mit optionaler Query, ohne Fragment. `IcapUri::tcp()` prüft den Host der Socket-Adresse genauso.

**Korrektur zu Jules' Befund:** `HeaderFields::validated()` prüft jeden Eintrag eines Array-Werts einzeln. Jules' Behauptung, dass Array-Werte umgangen werden, ist faktisch falsch — **nicht** in den Plan aufnehmen.

## Verwaltete Header — Library gewinnt immer

Caller-Werte für verwaltete Header fallen in jeder Schreibweise weg (`HeaderFields::without()`):

- `Host` und `Encapsulated` — setzt immer der Formatter, aus dem URI und aus den berechneten Offsets.
- `Preview` und `Allow` — im Preview-Request setzt `IcapClient` `Preview: <n>` und `Allow: 204` über `mergeHeaders()`; die Continuation nach `100 Continue` hat keinen Kopf und trägt keinen der beiden.
- `scanFile()` sendet ohne Preview-Handshake und verwirft nur `Preview`; `Allow` und die übrigen `extraHeaders` gehen mit.
- Ausnahme im verifizierten Modus: `scanFile()`, der Request, der die ganze Datei ohne Preview sendet, trägt `Allow: 204` (`wholeFileHeaders()`).

Wer einen `extraHeaders`-Param mit `Encapsulated` ankommt, sieht seinen Wert nicht im Wire — das ist **gewollt**.

## DoS-Limits

Drei Werte der `Config`, durchgesetzt vom `ResponseDecoder` (Transporte über `ResponseFrameReader`, Strings über `ResponseParser`) für jeden Zeilenblock (Kopf, Headersegmente, Chunkzeilen, Trailer):

- `maxResponseSize` (Default 10 MiB) — Bytes einer Antwort; die verwerfenden Scans ersetzen ihn durch ihr Pflichtbudget `$maxResponseBytes`.
- `maxHeaderCount` (Default 100) — Anzahl Headerzeilen.
- `maxHeaderLineLength` (Default 8192) — Bytes pro Zeile.

Wer den `ResponseParser` per Hand instanziiert, übergibt die Limits **aus derselben Config**, die der Transport sieht — sonst hängen Transport- und Parser-Limits auseinander:

```php
$parser = new ResponseParser(
    maxHeaderCount: $config->getMaxHeaderCount(),
    maxHeaderLineLength: $config->getMaxHeaderLineLength(),
);
```

Tests in `tests/Security/ParserDosLimitsTest.php` prüfen die Grenzen.

## Virus-Header — geordnete Liste (Standardregeln)

`Config::getVirusFoundHeaders()` liefert `list<string>` (Default `['X-Virus-Name']`). `IcapClient::extractVirusName()` und `DefaultPreviewStrategy` nehmen den ersten Header der Liste, der in der Antwort steht, in jeder Schreibweise (`HeaderFields::first()`). Welcher Header zu welchem Produkt gehört, ist nicht verifiziert; der verifizierte Modus für c-icap nutzt die Liste nicht. Weitere Header gehören in die Liste der `Config`, **nicht** als Sonderfall in `extractVirusName()`.

## Connection-Pool — Pool-Key

`AmpConnectionPool::bucketOf()` bildet `host:port` und, mit TLS, `:tls:<token>` (der Endpoint für die Caps ist `host:port` ohne Token, `endpointOf()`). Das Token ist eine pro Pool vergebene, nur wachsende Nummer je `ClientTlsContext`-Objekt (`\WeakMap<ClientTlsContext, int>`), die nie ein zweites Mal ausgegeben wird:

```php
$key .= ':tls:' . ($this->tlsTokens[$tls] ??= ++$this->lastTlsToken);
```

Kein `spl_object_hash()`/Objektadresse als Identität: `connectTls()` verbindet bei leerem Peer-Namen mit einem Klon, das Original kann vor dem Idle-Socket freigegeben werden, und sein Hash gehört dann dem nächsten Kontext (B07). Der Test in `tests/Transport/AmpConnectionPoolTest.php` läuft mit echtem Default-Connector und lokalem TLS-Server. Der OPTIONS-Cache trennt TLS-Profile dagegen nach ihren Einstellungen, nicht nach dem Objekt (`OptionsCacheKey::forService()`).

## Socket-Disposal nach Austausch

Standard ist `close()`. Zurück in den Pool geht ein Socket nur über eine Session, nachdem der Client Frame, Status und Verdikt geprüft hat und keine Antwort des Austauschs `close` in `Connection` trug (Tokenliste, jede Schreibweise: `HeaderFields::hasToken()`):

```php
$reusable = false;
try {
    // write(), readResponse(), parse(), interpretResponse() ...
    $reusable = !$serverCloses;
    return $result;
} finally {
    $reusable ? $session->release() : $session->close();
}
```

- `AsyncAmpTransport::request()` liefert einen ungeprüften String und schließt deshalb immer; wiederverwendet wird nur im Strict-Preview-Flow.
- Exceptions, unerlaubte Status (100 nach `ieof`, 3xx, 4xx/5xx) und Framing-Fehler schließen.
- Bytes hinter einer Antwort sind `IcapMalformedResponseException` (eine ausstehende Anfrage je Verbindung, RFC 3507 §4.1): die Session schließt, nichts geht in den Pool. Der Pool nimmt nur Sockets zurück, die er verliehen hat.

## OPTIONS-Cache

Ein Eintrag ist eine geprüfte OPTIONS-Antwort je Endpoint, Dienst und TLS-Profil (`OptionsCacheKey::forService()`, `OptionsEntry`). Die TTL ist die `Options-TTL` des Servers als Dezimalzahl, nie gecastet, höchstens `OptionsCacheInterface::MAX_TTL_SECONDS`, ab Eingang der Antwort gezählt; ohne brauchbare TTL wird nicht gespeichert:

```php
$ttl = OptionsEntry::ttl(HeaderFields::single($response->headers, 'Options-TTL')) - $elapsedSeconds;
if ($ttl > 0 && OptionsEntry::keeps($response)) {
    $this->optionsCache->set($cacheKey, $response, $ttl, $istag);
}
```

Ein Treffer zählt nur nach `OptionsEntry::keeps()` und `ResponseDecoder::admits()` (Limits des lesenden Clients), sonst ist er ein Miss (`IcapClient::cachedOptions()`). Das ISTag invalidiert nichts; die Konsistenz ist die TTL. Vertrag: `OptionsCacheInterface`.

## DefaultPreviewStrategy

`100` → `CONTINUE_SENDING`, `204` → `ABORT_CLEAN`, `200`/`206` → `ABORT_INFECTED` mit einem Header der Virus-Header-Liste, sonst `ABORT_CLEAN`; `4xx`/`5xx` werden wie bei jeder Antwort `IcapClientException`/`IcapServerException`, alles andere `IcapProtocolException`. Eine eigene Strategie lehnen der verifizierte Modus (`\InvalidArgumentException` im Konstruktor) und `scanFileWithPreviewDiscardingBody()` (`\LogicException`) ab.

## Encapsulated-Aware Response-Framing

`ResponseFrameReader` (Transporte) und `ResponseParser` lesen mit demselben internen `ResponseDecoder`: Das Ende einer Response ergibt sich aus ihrem `Encapsulated`-Layout (eine der Antwortformen aus RFC 3507 §4.4.1, Headersegmente exakt so lang wie angegeben, Body als Chunks bis Abschlusschunk und Trailer), **nicht** aus `Connection: close` und nicht aus einer Suche nach `0\r\n\r\n`. Damit funktioniert Keep-Alive auch gegen Server, die den Socket weiter offen halten, und Bytes im Payload bleiben Nutzbytes. obs-fold im `Encapsulated`-Header wird über `HeaderFields::parse()` aufgelöst. Finale Antworten brauchen `Encapsulated`; nur 100, 204 und 4xx/5xx dürfen ohne enden, und 100/204 tragen keine Nachricht (Layout höchstens `null-body=0`). Headerzeilen werden vor trim/Unfold geprüft (Name ein Token, Wert ohne Steuerzeichen). Bytes hinter einer Antwort sind in beiden Modi ein Framingfehler; die Session schließt, statt zu poolen.
