# icap-flow-spezifische Invarianten

Die folgenden Regeln sind sicherheitskritisch oder protokoll-blockierend. Wer sie aufweicht, bricht entweder eine Audit-Erkenntnis aus `docs/review/review_v2-1/` oder eine RFC-3507-Vorschrift. Sie sind die Stellen, an denen Reviewer als erstes hinschauen.

## Fail-Secure-Statuscode-Auswertung

**Regel:** Jeder Statuscode, der nicht eindeutig „Clean" bedeutet, wird zu einer typisierten Exception. Niemals stiller Fallback auf „Clean".

Was als Clean zählt:
- `204 No Content` — explizit „keine Modifikation, kein Befund".
- `200 OK` / `206 Partial Content` **ohne** Vendor-Virus-Header → Clean. Mit Header → `ScanResult(isInfected=true, virusName=…)`.

Was als Fehler zählt:
- `100 Continue` außerhalb des Preview-Flows → `IcapProtocolException`. (Finding G der v2-Konsolidierung.)
- `4xx` → `IcapClientException` mit Status als Code.
- `5xx` → `IcapServerException` mit Status als Code.
- Alles andere → `IcapResponseException`.

Die Logik lebt in **einer** Methode: `IcapClient::interpretResponse()`. Der Preview-Flow umgeht sie absichtlich (`executeRaw()`), weil `100 Continue` dort legitim ist — die Interpretation passiert nach dem Preview erneut über `interpretResponse()`.

```php
// Falsch — stille Klassifikation
if ($code !== 204 && $code !== 200) {
    return new ScanResult(false, null, $response);   // ❌ Fail-Open
}

// Richtig — typisierte Exception
throw new IcapResponseException('Unexpected ICAP status: ' . $code, $code);
```

## Preview-Flow — `executeRaw()` ist intern

`executeRaw()` ist heute `public`, weil der Preview-Flow ihn braucht. Externe Aufrufer rufen ihn **nicht** — sie nehmen `request()`, `scanFile()`, `scanFileWithPreview()`, `options()`. v3.0.0 hebt ihn entweder ins Interface oder macht ihn `protected` (Item #34 der v2.1-Liste). Bis dahin: in jedem PR, der `executeRaw()` von außen aufruft, einen Skill-Check auslösen.

## Strict RFC 3507 §4.5 vs. Legacy-Approximation

| Pfad | Wann | Wo |
|---|---|---|
| `scanFileWithPreviewStrict` | `transport instanceof SessionAwareTransport` | `IcapClient.php:332-415` |
| `scanFileWithPreviewLegacy` | sonst (synchroner Transport, Custom-Impl) | `IcapClient.php:426-495` |

Strict-Pfad: Preview + Continuation auf demselben Socket, **ein** logischer ICAP-Request, kein zweiter HTTP-Head. Legacy-Pfad: zwei Requests, Vollkörper im zweiten Request — funktioniert gegen permissive Server, kostet einen TCP/TLS-Handshake.

Die beiden Pfade nicht zusammenlegen. Tests in `tests/PreviewContinueStrictTest.php` (Strict) und `tests/IcapClientTest.php` (Legacy) prüfen sie unabhängig.

Beide lesen das Preview über `readPreview()`: 0..1 MiB (`MAX_PREVIEW_SIZE`), Größe aus `fstat()` am Handle, `ieof` erst nach beobachtetem EOF und passender Länge. Der Rest (Strict) und der Vollbody (`scanFile()`, Legacy-Vollrequest) gehen mit genau der angekündigten `Content-Length` raus: Der Formatter reicht sie an `ChunkedBodyEncoder::encode()` weiter, Unter- und Überlänge werfen vor dem Abschlusschunk (nicht bei einem Teil-Preview: `Preview` ohne `ieof`, seine `Content-Length` beschreibt den ganzen Body). `Preview: 0` ist gültig und geht mit `res-body` und `0\r\n\r\n` raus (`RequestFormatter::isEmptyPreview()`), nie als `null-body`. Gecachte `Preview`-Werte: Dezimalprüfung vor dem Cast, gültig und zu groß → Cap, ungültig → `IcapMalformedResponseException`, fehlend → 1024.

## Header- und URI-Injection-Schutz

`RequestFormatter::format()` prüft jeden Request, bevor der erste Chunk entsteht: ICAP-Methode, URI (`IcapUri::authority()`), ICAP- und gekapselte HTTP-Header sowie HTTP-Request- und Statuszeile. Ungültige Metadaten werfen `\InvalidArgumentException`; kein Byte erreicht das Socket. Finding H der v2-Konsolidierung.

- `HeaderFields::validated()` — Namen sind Token (RFC 7230 §3.2.6), Werte Strings ohne Steuerzeichen außer HTAB. CR/LF werden abgewiesen, nicht „escaped“. `IcapClient::validateIcapHeaders()` wendet dieselbe Prüfung in den Scan-Helfern schon vor dem Öffnen der Datei an.
- `IcapUri::forService()` — Host, Port und Service-Pfad bleiben getrennte Komponenten: Host als Hostname oder IP-Adresse (IPv6 in Klammern), Service als absoluter Pfad mit optionaler Query, ohne Fragment. `IcapUri::tcp()` prüft den Host der Socket-Adresse genauso.

**Korrektur zu Jules' Befund:** `HeaderFields::validated()` prüft jeden Eintrag eines Array-Werts einzeln. Jules' Behauptung, dass Array-Werte umgangen werden, ist faktisch falsch — **nicht** in den Plan aufnehmen.

## Verwaltete Header — Library gewinnt immer

Caller-Werte für verwaltete Header fallen in jeder Schreibweise weg (`HeaderFields::without()`):

- `Host` und `Encapsulated` — setzt immer der Formatter, aus dem URI und aus den berechneten Offsets.
- `Preview` und `Allow` — im Preview-Request setzt `IcapClient` `Preview: <n>` und `Allow: 204` über `mergeHeaders()`; der Legacy-Vollrequest nach `100 Continue` trägt keinen der beiden.
- `scanFile()` sendet ohne Preview-Handshake und verwirft nur `Preview`; `Allow` und die übrigen `extraHeaders` gehen mit.

Wer einen `extraHeaders`-Param mit `Encapsulated` ankommt, sieht seinen Wert nicht im Wire — das ist **gewollt**.

## DoS-Limits

Drei Konfigurationswerte in `Config`, durchgesetzt von `ResponseParser` und `AsyncAmpTransport`:

- `maxResponseSize` (Default 10 MiB) — Gesamt-Bytes pro ICAP-Response.
- `maxHeaderCount` (Default 100) — Anzahl Header-Lines.
- `maxHeaderLineLength` (Default 8192) — Bytes pro Line.

Wer den `ResponseParser` per Hand instanziiert, übergibt die Limits **aus derselben Config**, die der Transport sieht — sonst hängen Transport- und Parser-Limits auseinander:

```php
$parser = new ResponseParser(
    maxHeaderCount: $config->getMaxHeaderCount(),
    maxHeaderLineLength: $config->getMaxHeaderLineLength(),
);
```

Tests in `tests/Security/ParserDosLimitsTest.php` prüfen die Grenzen.

## Vendor-Virus-Header — geordnete Liste

`Config::getVirusFoundHeaders()` liefert `list<string>`, `IcapClient::extractVirusName()` liefert den ersten in der Response vorhandenen. Default ist `['X-Virus-Name']` für Back-Compat; Production wird typisch auf

```php
$config->withVirusFoundHeaders([
    'X-Virus-Name',          // ClamAV / c-icap
    'X-Infection-Found',     // ISS Proventia
    'X-Violations-Found',    // Trend Micro
    'X-Virus-ID',            // Symantec
]);
```

konfiguriert. Neue Vendor-Header **immer** in die Liste, **nicht** als Sonderfall in `extractVirusName()`.

## Connection-Pool — Pool-Key

`AmpConnectionPool::key()` bildet `host:port` und, mit TLS, `:tls:<token>`. Das Token ist eine pro Pool vergebene, nur wachsende Nummer je `ClientTlsContext`-Objekt (`\WeakMap<ClientTlsContext, int>`), die nie ein zweites Mal ausgegeben wird:

```php
$key .= ':tls:' . ($this->tlsTokens[$tls] ??= ++$this->lastTlsToken);
```

Kein `spl_object_hash()`/Objektadresse als Identität: `connectTls()` verbindet bei leerem Peer-Namen mit einem Klon, das Original kann vor dem Idle-Socket freigegeben werden, und sein Hash gehört dann dem nächsten Kontext (B07). Der Test in `tests/Transport/AmpConnectionPoolTest.php` läuft mit echtem Default-Connector und lokalem TLS-Server.

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

- `AsyncAmpTransport::request()` liefert einen ungeprüften String und schließt deshalb immer.
- Exceptions, unerlaubte Status (100 nach `ieof`, 3xx, 4xx/5xx) und Framing-Fehler schließen.
- Wiederverwendung mit vollständigem Lease-/Restbyte-Vertrag folgt mit AP-10/AP-11.

## OPTIONS-Cache

`OptionsCacheInterface` lebt in `src/Cache/`, Default-Impl ist `InMemoryOptionsCache`. TTL kommt aus dem `Options-TTL`-Header (`RFC 3507 §4.10.2`); fehlt der Header, kein Caching:

```php
$ttl = (int) ($response->headers['Options-TTL'][0] ?? '0');
$this->optionsCache->set($cacheKey, $response, $ttl);
```

v2.2-Erweiterungen aus der Task-Liste:
- ISTag-Invalidation (`OptionsCacheInterface::set(?string $istag = null)`) — Item #12.
- PSR-20 `ClockInterface` für deterministische TTL-Tests — Item #13.
- PSR-6/PSR-16-Adapter als Optional-Deps — Item #15.

## DefaultPreviewStrategy — der 200/206-Branch (v2.1.1 P0)

Heute:
```php
return match ($previewResponse->statusCode) {
    204     => PreviewDecision::ABORT_CLEAN,
    100     => PreviewDecision::CONTINUE_SENDING,
    default => throw new IcapResponseException('Unexpected preview status code: ' . $previewResponse->statusCode),
};
```

Problem: c-icap antwortet auf einen Eicar-Treffer im Preview mit `200 OK + X-Virus-Name` statt erst `100 Continue` zu geben. Heute fliegt das als uncatchable `IcapResponseException` raus, statt als `ScanResult(isInfected=true)`. Der `ABORT_INFECTED`-Branch in `IcapClient.php:388` ist deshalb toter Code.

Fix für v2.1.1 (Item #3):
```php
return match (true) {
    $previewResponse->statusCode === 204 => PreviewDecision::ABORT_CLEAN,
    $previewResponse->statusCode === 100 => PreviewDecision::CONTINUE_SENDING,
    $previewResponse->statusCode === 200 || $previewResponse->statusCode === 206
        => $this->classifyPossiblyInfected($previewResponse),
    default => throw new IcapResponseException(
        'Unexpected preview status code: ' . $previewResponse->statusCode,
    ),
};
```

`classifyPossiblyInfected()` schaut die `Config::getVirusFoundHeaders()`-Liste durch — Hit → `ABORT_INFECTED`, sonst `ABORT_CLEAN`. Damit das funktioniert, braucht die Strategy einen Verweis auf `Config` (oder die Headerliste wird in den Konstruktor reingereicht — letzteres ist BC-freundlicher, weil es das `PreviewStrategyInterface` nicht ändert).

## Encapsulated-Aware Response-Framing

`ResponseFrameReader` (Transporte) und `ResponseParser` lesen mit demselben internen `ResponseDecoder`: Das Ende einer Response ergibt sich aus ihrem `Encapsulated`-Layout (eine der Antwortformen aus RFC 3507 §4.4.1, Headersegmente exakt so lang wie angegeben, Body als Chunks bis Abschlusschunk und Trailer), **nicht** aus `Connection: close` und nicht aus einer Suche nach `0\r\n\r\n`. Damit funktioniert Keep-Alive auch gegen Server, die den Socket weiter offen halten, und Bytes im Payload bleiben Nutzbytes. obs-fold im `Encapsulated`-Header wird über `HeaderFields::parse()` aufgelöst. Finale Antworten brauchen `Encapsulated`; nur 100, 204 und 4xx/5xx dürfen ohne enden, und 100/204 tragen keine Nachricht (Layout höchstens `null-body=0`). Headerzeilen werden vor trim/Unfold geprüft (Name ein Token, Wert ohne Steuerzeichen). Bytes hinter einer Antwort gehören der Session: Schreiben darauf wirft, `release()` schließt statt zu poolen.

## Stale-Claims, die zu fixen sind (v2.1.1)

Aus der Task-Liste:
- `SECURITY.md:73-75` behauptet, Cache/Pool/Retry seien geplant — sind seit v2.0/2.1 implementiert. Item #1.
- `examples/cookbook/03-options-request.php:11-13` referenziert „next milestone after v2.0.0" — zu entfernen. Item #5.
- `examples/cookbook/02-custom-preview-strategy.php` zeigt eine McAfee-Strategy, die das 200-Verhalten nicht abdeckt — Item #6.
- `src/Transport/ConnectionPoolInterface.php:36` Phpdoc verweist auf `NullConnectionPool`, das es noch nicht gibt — Item #4. Phpdoc-Drop oder Klasse anlegen (letzteres ist Item #14, v2.2).
