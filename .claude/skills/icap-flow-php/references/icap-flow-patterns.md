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

## Header- und URI-Injection-Schutz

`validateServicePath()` und `validateIcapHeaders()` rejecten CR/LF/NUL/Steuerzeichen, **bevor** der erste Byte das Socket erreicht. Findings H der v2-Konsolidierung.

```php
private function validateServicePath(string $service): void
{
    if (preg_match('/[\x00-\x20\x7F]/', $service) === 1) {
        throw new \InvalidArgumentException(
            'Service path contains control characters, whitespace or NUL: '
            . var_export($service, true),
        );
    }
}

private function validateIcapHeaders(array $headers): void
{
    foreach ($headers as $name => $value) {
        if (preg_match('/[\x00-\x1F\x7F:]/', $name) === 1) {
            throw new \InvalidArgumentException(
                'ICAP header name contains control characters or separator: '
                . var_export($name, true),
            );
        }
        foreach ((array) $value as $v) {
            if (preg_match('/[\x00\r\n]/', $v) === 1) {
                throw new \InvalidArgumentException(
                    sprintf('ICAP header %s carries CR/LF/NUL.', $name),
                );
            }
        }
    }
}
```

**Korrektur zu Jules' Befund:** Das `foreach ((array) $value as $v)` iteriert pro Array-Element. Jules' Behauptung, dass Array-Werte umgangen werden, ist faktisch falsch — **nicht** in den Plan aufnehmen.

**v2.2-Item (#22):** Header-Namen strenger gegen RFC-7230-§3.2.6-Token-Set prüfen. Nice-to-have, nicht sicherheitsblockierend.

## Header-Merge — Library gewinnt immer

`mergeHeaders($caller, $managed)` schreibt zuerst Caller-Werte, dann Library-Werte. Library-Header können nicht überschrieben werden:

- `Encapsulated` — wird vom Formatter gerechnet.
- `Host` — wird aus `Config::host:port` gesetzt.
- `Connection` — Library steuert Keep-Alive.
- Im Preview-Flow zusätzlich: `Preview`, `Allow`.

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

## Connection-Pool — Pool-Key (v2.1.1 P0)

Heute (verbuggt):
```php
private function key(Config $config): string
{
    return $config->host . ':' . $config->port;
}
```

Multi-Tenant mit verschiedenen TLS-Configs teilen sich denselben Stack — das ist Cross-Tenant-Leakage. Übergangs-Fix für v2.1.1:
```php
private function key(Config $config): string
{
    $key = $config->host . ':' . $config->port;
    $tls = $config->getTlsContext();
    if ($tls !== null) {
        $key .= ':' . spl_object_hash($tls);
    }
    return $key;
}
```

`spl_object_hash` ist ein bewusster Übergang — `AmpConnectionPool` muss in v2.2 auf einen deterministischen Hash umsteigen (Cert-PEM oder Konfigfingerprint). Der Cross-TLS-Isolations-Test in `tests/Transport/AmpConnectionPoolTest.php` muss **vor** dem Fix rot, **nach** dem Fix grün sein.

## Socket-Disposal nach Austausch

```php
try {
    $session->write($rawRequest);
    $response = $session->readResponse();

    if ($this->serverWantsClose($response)) {
        $closeForced = true;
    }

    return $response;
} catch (\Throwable $e) {
    $closeForced = true;
    throw $e;
} finally {
    if ($closeForced) {
        $session->close();
    } else {
        $session->release();
    }
}
```

Auslöser für hartes `close()`:
- `Connection: close` im ICAP-Head.
- Beliebige `\Throwable` während `write()` oder `readResponse()`.
- Framing-Fehler (`ResponseFrameReader` wirft).

Alles andere → `release()` — der Socket geht zurück in den Pool.

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

`ResponseFrameReader` erkennt das Ende einer Response anhand des `Encapsulated`-Headers, **nicht** anhand `Connection: close`. Damit funktioniert Keep-Alive auch gegen Server, die den Socket weiter offen halten.

**v2.2-Item (#21):** obs-fold (RFC 7230 — Header über mehrere Zeilen mit Whitespace-Continuation) wird im Encapsulated-Header derzeit nicht beachtet. Wer den Frame-Reader anfasst, sollte auch das mitnehmen.

## Stale-Claims, die zu fixen sind (v2.1.1)

Aus der Task-Liste:
- `SECURITY.md:73-75` behauptet, Cache/Pool/Retry seien geplant — sind seit v2.0/2.1 implementiert. Item #1.
- `examples/cookbook/03-options-request.php:11-13` referenziert „next milestone after v2.0.0" — zu entfernen. Item #5.
- `examples/cookbook/02-custom-preview-strategy.php` zeigt eine McAfee-Strategy, die das 200-Verhalten nicht abdeckt — Item #6.
- `src/Transport/ConnectionPoolInterface.php:36` Phpdoc verweist auf `NullConnectionPool`, das es noch nicht gibt — Item #4. Phpdoc-Drop oder Klasse anlegen (letzteres ist Item #14, v2.2).
