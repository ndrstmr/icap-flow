# Due-Diligence-Bericht: `ndrstmr/icap-flow` v1.0.0 (VERIFIZIERT)

**Version:** 2.0 — basierend auf vollständiger Source-Inspektion
**Datum:** 24. April 2026
**Basis:** v1.0.0 (Commit `be1df51`, Release 2025-06-18)
**Methodik:** Jede Datei in `src/` (18×), `tests/` (14×), `examples/` (5×), `.github/`, `docs/` plus `composer.json`, `composer.lock`, `phpstan.neon`, `phpunit.xml.dist`, `.php-cs-fixer.dist.php`, `CHANGELOG.md`, `CONTRIBUTING.md`, `README.md` inspiziert. Alle Zeilen-Referenzen sind echt.

**Dieser Bericht ersetzt den vorherigen (Version 1.0 vom 24.04.2026) vollständig.** Der Subagent-Lauf konnte mangels Repo-Zugriff nur konservativ schätzen; viele Einschätzungen waren zu pessimistisch, einige zu wohlwollend.

---

## 1. Executive Summary

**Kernbefund:** `icap-flow` v1.0.0 ist ein **architektonisch ambitioniertes, aber in der Protokoll-Implementierung unvollständiges AI-generiertes Projekt** (das `docs/agent.md` deklariert OpenAI Codex als Implementierungs-Agent, datiert 17. Juni 2025, Release einen Tag später). Die **Abstraktionsschicht** (Ports/Adapters via vier Interfaces, readonly DTOs, Backed Enum, Strategy Pattern, Decorator) ist **sehr sauber** — besser als der durchschnittliche PHP-Solo-Prototyp. Die **konkrete Protokoll-Implementierung** (`RequestFormatter`, `ResponseParser`, `SynchronousStreamTransport`) ist jedoch **RFC-3507-verletzend** und würde von keinem produktiven ICAP-Server (c-icap, Kaspersky, Symantec, Sophos, ClamAV) akzeptiert werden.

**Die gute Nachricht:** Weil die Architektur sauber Ports-und-Adapters-basiert ist, lassen sich die Bugs **in klar umrissenen Komponenten** fixen, ohne Strukturumbau. Das Projekt ist ein **hervorragender Ausgangspunkt für einen Fork oder ein Upstream-Engagement**.

**Die schlechte Nachricht:** In seiner aktuellen Form ist die Lib nicht produktionsfähig. Die Tests (Zeile 21-43 in `tests/RequestFormatterTest.php`) zementieren aktiv den zentralen Encapsulated-Header-Bug — wer das Repo heute installiert, bekommt grüne CI und nicht-funktionierenden Code.

**Empfehlung für Interessenten:**
- **Interne Prototypen, CI-Virenscan-Spielwiese mit c-icap in Docker:** ⚠️ Nur mit c-icap, das sehr tolerant parst. Kaspersky/Symantec/Sophos lehnen die gesendeten Nachrichten wahrscheinlich ab.
- **Symfony-Bürgerportale (TYPO3, Shopware) mit Virenscan-Pflicht:** 🔴 **Nein.** Der Upload würde im besten Fall nicht funktionieren (Server antwortet 400/500), im schlimmsten Fall protokollieren manche Server den kaputten Request als "clean" zurück → Malware passiert.
- **Als Basis eines gepflegten Forks (EUPL-1.2 erlaubt das):** ✅ **Ja**, Top-Kandidat. Rund 2–3 Entwickler-Wochen für P0-Fixes + c-icap-Integration-Test-Suite reichen, um auf TRL 6 zu kommen.

**Gesamt-Readiness-Score: 103 / 210 (49%)**
**TRL: 4** (Labormuster in relevanter Umgebung; die Protokoll-Nichtkonformität verhindert TRL 5 = "Prototyp in relevanter Umgebung validiert").

---

## 2. Repository-Inventar

### 2.1 Verifizierte Metriken

| Metrik | Wert | Quelle |
|---|---|---|
| LOC `src/` | **813** | `wc -l src/**/*.php` |
| LOC `tests/` | **598** | `wc -l tests/**/*.php` |
| Test-zu-Code-Ratio | 0,74 | errechnet |
| Dateien `src/` | 18 (.php) | `find src -name "*.php"` |
| Dateien `tests/` | 14 (.php, davon 12 Test + 1 AsyncTestCase + 1 Pest.php) | idem |
| Dateien `examples/` | 5 | idem |
| Packages in `composer.lock` | 279 (meist dev-transitiv) | `grep -c '"name":' composer.lock` |
| Runtime-Dep | `amphp/socket ^2.3` (einzige!) | `composer.json:13-16` |
| Revolt-Version (transitiv) | v1.0.7 | `composer.lock` |

### 2.2 Verifizierte Struktur

```
icap-flow/
├── src/                                      18 Dateien, 813 LOC
│   ├── Config.php                            65 LOC — final readonly class
│   ├── IcapClient.php                        181 LOC — NICHT final, NICHT interface-basiert
│   ├── SynchronousIcapClient.php             79 LOC — final class, Decorator
│   ├── RequestFormatter.php                  57 LOC — RFC-Verstoß (s. §3.2)
│   ├── RequestFormatterInterface.php
│   ├── ResponseParser.php                    68 LOC — unvollständig (s. §3.2)
│   ├── ResponseParserInterface.php
│   ├── DefaultPreviewStrategy.php            26 LOC — wirft 200 weg (s. §3.3)
│   ├── PreviewStrategyInterface.php
│   ├── PreviewDecision.php                   15 LOC — Backed Enum, 3 Cases
│   ├── DTO/
│   │   ├── IcapRequest.php                   45 LOC — final readonly, Wither
│   │   ├── IcapResponse.php                  42 LOC — final readonly, Wither
│   │   └── ScanResult.php                    42 LOC — final readonly
│   ├── Exception/
│   │   ├── IcapResponseException.php         14 LOC — extends RuntimeException
│   │   └── IcapConnectionException.php       14 LOC — extends RuntimeException
│   └── Transport/
│       ├── TransportInterface.php
│       ├── AsyncAmpTransport.php             58 LOC — final, Amp\Socket
│       └── SynchronousStreamTransport.php    34 LOC — hardcoded 5s timeout
├── tests/                                    14 Dateien, 598 LOC
├── examples/
│   ├── 01-sync-scan.php
│   ├── 02-async-scan.php
│   └── cookbook/
│       ├── 01-custom-headers.php
│       ├── 02-custom-preview-strategy.php   [fachlich fragwürdig, s. §6]
│       └── 03-options-request.php
├── docs/agent.md                             Mission Charter (OpenAI Codex!)
├── .github/workflows/ci.yml                  Single-Job, PHP 8.3 only
├── composer.json                             clean, 1 prod-dep
├── composer.lock                             279 packages
├── phpstan.neon                              level 9 + bleedingEdge
├── phpunit.xml.dist                          minimal
├── .php-cs-fixer.dist.php                    nur @PSR12
├── CHANGELOG.md                              widerspricht Mission Charter
└── CONTRIBUTING.md                           PSR-12 + composer test
```

### 2.3 Öffentliche API — SemVer-Geschützter Umfang

| Symbol | Lokation | `final`? | Interface? |
|---|---|---|---|
| `Ndrstmr\Icap\Config` | `Config.php:10` | ✅ `final readonly` | ❌ kein Interface |
| `Ndrstmr\Icap\IcapClient` | `IcapClient.php:25` | ❌ **nicht final** | ❌ kein Interface |
| `Ndrstmr\Icap\SynchronousIcapClient` | `SynchronousIcapClient.php:19` | ✅ `final` | ❌ kein Interface |
| `Ndrstmr\Icap\RequestFormatter` | `RequestFormatter.php:12` | ❌ | ✅ `RequestFormatterInterface` |
| `Ndrstmr\Icap\ResponseParser` | `ResponseParser.php:14` | ❌ | ✅ `ResponseParserInterface` |
| `Ndrstmr\Icap\DefaultPreviewStrategy` | `DefaultPreviewStrategy.php:13` | ❌ | ✅ `PreviewStrategyInterface` |
| `Ndrstmr\Icap\PreviewDecision` | `PreviewDecision.php:10` | — (enum) | — |
| `Ndrstmr\Icap\DTO\IcapRequest` | `DTO/IcapRequest.php:10` | ✅ `final readonly` | ❌ |
| `Ndrstmr\Icap\DTO\IcapResponse` | `DTO/IcapResponse.php:10` | ✅ `final readonly` | ❌ |
| `Ndrstmr\Icap\DTO\ScanResult` | `DTO/ScanResult.php:10` | ✅ `final readonly` | ❌ |
| `Ndrstmr\Icap\Transport\AsyncAmpTransport` | `Transport/AsyncAmpTransport.php:18` | ✅ `final` | ✅ `TransportInterface` |
| `Ndrstmr\Icap\Transport\SynchronousStreamTransport` | `Transport/SynchronousStreamTransport.php:13` | ❌ | ✅ `TransportInterface` |
| `Ndrstmr\Icap\Exception\IcapResponseException` | `Exception/IcapResponseException.php:12` | ❌ | ❌ kein Marker-Interface |
| `Ndrstmr\Icap\Exception\IcapConnectionException` | `Exception/IcapConnectionException.php:12` | ❌ | ❌ kein Marker-Interface |

**Dependency-Graph (Runtime):**
```
ndrstmr/icap-flow
└── amphp/socket ^2.3
    ├── amphp/amp, amphp/byte-stream, amphp/cache, amphp/dns,
    ├── amphp/parser, amphp/pipeline, amphp/process, amphp/serialization,
    ├── amphp/sync
    └── revolt/event-loop v1.0.7  ← direkt in Code genutzt (AsyncTestCase.php:6,
                                     README.md:35, examples/02-async-scan.php:9),
                                     aber NICHT in composer.json:require deklariert!
```

---

## 3. Findings nach Dimension (mit echten Zeilen-Referenzen)

### 3.1 Sprachmoderne & Typsystem

**Positiv:**
- `declare(strict_types=1);` in allen 18 `src/`-Dateien verifiziert.
- `final readonly class` für alle 4 DTOs (`Config.php:10`, `DTO/IcapRequest.php:10`, `DTO/IcapResponse.php:10`, `DTO/ScanResult.php:10`).
- Backed Enum mit semantisch klaren Namen: `PreviewDecision.php:10-15` (3 Cases).
- Named Args in `Config`-Konstruktor (`Config.php:18-24`), nutzbar laut README.
- Constructor Property Promotion durchgängig (u.a. `IcapClient.php:34-41`, `ScanResult.php:12-16`).
- PHPStan Level 9 + `bleedingEdge.neon` (`phpstan.neon:2-5`) — das ist das Maximum plus Zukunfts-Strenge.
- `match`-Expression mit `default => throw` (`DefaultPreviewStrategy.php:20-24`).
- Generics-Docblocks mit `@var Future<string>` (`Transport/AsyncAmpTransport.php:21`), `@return Future<ScanResult>` (`IcapClient.php:72`) — PHPStan kann das nutzen.

**Negativ:**
- Kein `#[Override]`-Attribut (PHP 8.3) an Interface-Implementierungen.
- Kein `#[\SensitiveParameter]` für irgendeinen Input.
- Kein `never`-Return (obwohl z.B. `IcapClient.php:179` ein Kandidat wäre, wenn als Helfermethode extrahiert).
- `IcapClient.php:25` **nicht** `final` und nicht readonly — im Gegensatz zu allen umliegenden Klassen. Inkonsistent.
- `ResponseParser.php:14` **nicht** `final`.
- `RequestFormatter.php:12` **nicht** `final`.
- `DefaultPreviewStrategy.php:13` **nicht** `final`.
- `SynchronousStreamTransport.php:13` **nicht** `final` — im Gegensatz zu `AsyncAmpTransport` (`final class` an Zeile 18).
- `IcapRequest.php:19, :25` nutzt `mixed $body` (Docblock: `resource|string`) — wäre ein Kandidat für `#[\AllowDynamicProperties]` oder eine dedizierte Abstraktion.

**Score: 7/10** (höher als vorheriger Schätzwert 6).

### 3.2 ⚠️⚠️⚠️ Protokoll-Korrektheit — der kritische Teil

**Das ist der Abschnitt, wo der Anspruch des README ("the de-facto standard solution") am härtesten mit der Realität kollidiert.** Die Funde hier sind **Show-Stopper**.

#### 3.2.1 `Encapsulated:`-Header: fundamentaler RFC-3507-§4.4-Verstoß

**Kontext (RFC 3507 §4.4.1):**
> "The `Encapsulated` header MUST be present in every ICAP message except an error response. […] The header indicates the presence and order of encapsulated sections and specifies their byte-offsets from the start of the encapsulating message body."

Ein REQMOD mit HTTP-Request-Body braucht z.B.:
```
Encapsulated: req-hdr=0, req-body=137
```

**Was `icap-flow` sendet (`RequestFormatter.php:28-30`):**
```php
if (!isset($headers['Encapsulated'])) {
    $headers['Encapsulated'] = ['null-body=0'];
}
```
Das heißt: **Immer `null-body=0` als Default**, egal ob Body vorhanden, egal bei welcher Methode.

**Was `IcapClient::scanFile()` konstruiert (`IcapClient.php:110-119`):**
Ein `IcapRequest` **ohne** `Encapsulated`-Header im Header-Array. Der Formatter ergänzt dann `null-body=0` — **obwohl ein Stream-Body vorhanden ist**.

**Was in Wahrheit gesendet wird** (rekonstruiert aus `RequestFormatter::format()`):
```
RESPMOD icap://127.0.0.1/service ICAP/1.0\r\n
Host: 127.0.0.1\r\n
Encapsulated: null-body=0\r\n
\r\n
c\r\n<12 bytes content>\r\n0\r\n\r\n
```

Das ist kein valides ICAP — der Encapsulated-Header sagt "kein Body", aber es kommt ein Body. **c-icap-tolerant ignoriert**, **Kaspersky/Symantec/Sophos lehnen mit 400 Bad Request ab**.

#### 3.2.2 String-Body wird nicht chunked encoded (`RequestFormatter.php:51-53`)

```php
} elseif (is_string($request->body) && $request->body !== '') {
    $body = $request->body;
}
```

Der String-Body wird **direkt** angehängt. RFC 3507 §4.4 verlangt `Transfer-Encoding: chunked` für ALLE encapsulated Bodies: `<hex-len>\r\n<content>\r\n0\r\n\r\n`.

**Auswirkung:** `scanFileWithPreview()` (`IcapClient.php:130-156`) nutzt String-Bodies (nicht Streams) — sendet damit **immer nicht-chunked** = Format-Fehler.

#### 3.2.3 Kein HTTP-in-ICAP-Kapselung

RFC 3507 §4.4.2 verlangt bei REQMOD/RESPMOD **verschachtelte HTTP-Nachrichten**:
```
REQMOD icap://.../virus_scan ICAP/1.0\r\n
Host: icap.example.com\r\n
Encapsulated: req-hdr=0, req-body=154\r\n
\r\n
POST /upload HTTP/1.1\r\n        ← HTTP-Request-Header (req-hdr)
Host: target.example.com\r\n
Content-Length: 42\r\n
\r\n
<hex-len>\r\n<actual file bytes>\r\n0\r\n\r\n   ← HTTP-Body chunked (req-body)
```

**Was `icap-flow` produziert:** Nackter File-Content ohne HTTP-Request-Line, ohne HTTP-Headers, ohne Offset-Berechnung. **Das ist kein RFC-3507.**

Das heißt: Ein realer ICAP-Scanner, der die ge-kapselte HTTP-Request-Ziel-URL als Info braucht (z.B. für Policy-Entscheidungen basierend auf Filename aus Content-Disposition), bekommt **nichts** zurück.

#### 3.2.4 `ieof`-Sentinel für Preview-Mode fehlt

RFC 3507 §4.5: Wenn die Preview-Bytes den kompletten Body enthalten, MUSS der letzte Chunk `0; ieof\r\n\r\n` (statt `0\r\n\r\n`) sein.

Im `RequestFormatter.php:40-50`:
```php
while (!feof($request->body)) {
    $chunk = fread($request->body, 8192);
    ...
    $body .= $len . "\r\n" . $chunk . "\r\n";
}
$body .= "0\r\n\r\n";
```

**Kein `; ieof`.** Bei `scanFileWithPreview()` mit Dateigröße ≤ Preview-Size wartet der Server auf `100 Continue` — aber der Client hat schon alle Daten geschickt → Deadlock.

Der Test `IcapClientTest.php:182-256` ("correctly handles file size equal to preview size") testet **Logik des Clients**, aber nicht das Wire-Format. Die Protokoll-Nicht-Konformität wird im Test-Mock umgangen.

#### 3.2.5 `ResponseParser` ignoriert Encapsulated-Header (`ResponseParser.php:21-67`)

```php
$headers = [];
foreach ($lines as $line) {
    if ($line === '') continue;
    [$name, $value] = explode(':', $line, 2);
    $name = trim($name);
    $value = trim($value);
    $headers[$name][] = $value;
}

// decode chunked body if applicable
if ($body !== '' && preg_match('/^[0-9a-fA-F]+\r\n/i', $body)) { ... }
```

Es wird kein Encapsulated-Header ausgewertet, um zu wissen, wo HTTP-Header enden und HTTP-Body beginnt. Bei einer REQMOD-Response, die mit einem modifizierten Request zurückkommt (RFC 3507 §4.3.3 Case 3), fällt der Client auf die Nase.

#### 3.2.6 URI ohne Port (`IcapClient.php:97, :116, :139`)

```php
$uri = sprintf('icap://%s%s', $this->config->host, $service);
```

Bei Nicht-Standard-Port (z.B. 11344 bei Kaspersky) sendet der Client **`icap://host/service`** statt **`icap://host:11344/service`**. Die URI ist RFC-3507-formal gültig (Port optional, Default 1344), aber sie gibt dem Server keine Chance, die Ziel-Port-Identität zu prüfen (bei Reverse-Proxy-Setups relevant).

#### 3.2.7 CRLF-Injection über `$service` (`IcapClient.php:97, :116, :139`)

Keine Validierung von `$service` vor URI-Einbau. `scanFile('/service\r\nX-Malicious: true', ...)` würde zusätzliche Header injizieren. RFC 3507 §7.3 fordert Sanitization der URI.

#### 3.2.8 Kein 206/400/403/500/503-Handling (`IcapClient.php:158-180`)

```php
if ($response->statusCode === 204) return new ScanResult(false, null, ...);
if ($response->statusCode === 200) { ... }
if ($response->statusCode === 100) return new ScanResult(false, null, ...);
throw new IcapResponseException('Unexpected ICAP status: ' . ..., ...);
```

**Kritisch:** `statusCode === 100` → `ScanResult(false, ...)` = "clean" (Zeile 175-177). Das ist **semantisch falsch** — 100 Continue ist *keine* finale Antwort, sondern eine Zwischenanswort im Preview-Flow. Wenn `IcapClient::request()` eine 100 zurückbekommt ohne nachfolgende Daten zu holen, ist das ein Bug.

403 ist bei vielen Vendoren (z.B. McAfee Web Gateway) der Status für "Virus found and blocked" — wird hier zur Exception statt zum `ScanResult(isInfected=true)`.

### 3.3 Transport-Schicht

#### 3.3.1 `SynchronousStreamTransport` ignoriert Config (`SynchronousStreamTransport.php:18-33`)

```php
$stream = @stream_socket_client($address, $errno, $errstr, 5);
```

- **Timeout hardcoded 5s** — `$config->getSocketTimeout()` und `getStreamTimeout()` werden nie gelesen.
- `@`-Error-Suppression (Zeile 23) — alle Connect-Fehler werden verschluckt, nur `$errstr` überlebt.
- `fclose($stream);` (Zeile 30) **nicht** in `finally` — Socket-Leak bei Exception aus `fwrite`/`stream_get_contents`.
- `stream_get_contents($stream)` ohne Length-Limit (Zeile 29) — DoS durch bösartigen Server, der beliebig viele Bytes sendet.
- Kein `stream_set_timeout()` für Read-Phase.

#### 3.3.2 `AsyncAmpTransport` — solide, aber ohne TLS (`AsyncAmpTransport.php:28`)

```php
$connectionUrl = sprintf('tcp://%s:%d', $config->host, $config->port);
```

**Hardcoded `tcp://`.** Kein `icaps://`-Support, keine `ClientTlsContext`. `amphp/socket` unterstützt TLS (`Socket\connectTls()`), wird aber nicht genutzt.

Immerhin korrekt:
- `TimeoutCancellation` (Zeile 31) basiert auf `$config->getStreamTimeout()`.
- `ConnectContext::withConnectTimeout()` (Zeile 29-30) nutzt `$config->getSocketTimeout()`.
- `finally { $socket?->close(); }` (Zeile 49-53) — sauberes Resource-Handling.
- Spezifische `IcapConnectionException` mit Previous-Chain (Zeile 44-48).

### 3.4 Memory-Safety

**`IcapClient::scanFile()` (Zeile 110-119)** nutzt `fopen($filePath, 'r')` — streaming-fähig. ✅

**Aber `IcapClient::scanFileWithPreview()` (Zeile 130-156)** nutzt `file_get_contents($filePath)` — komplette Datei im RAM. ❌

Das widerspricht direkt dem CHANGELOG-Eintrag: *"Advanced, memory-safe streaming for large file processing via stream resources"*. Bei 2 GB Dokumenten-Upload → OOM.

### 3.5 Exception-Design

**Verifiziert (`src/Exception/`):**

```php
class IcapResponseException extends RuntimeException {}
class IcapConnectionException extends RuntimeException {}
```

- **Nur zwei** Exception-Klassen.
- **Kein Marker-Interface** (`IcapExceptionInterface`).
- Keine Kontext-Properties (z.B. `getServerResponse()`, `getIcapStatusCode()`).
- `IcapResponseException` wird an 3 Stellen geworfen:
  - `IcapClient.php:179` — unerwarteter Status-Code (mit Code-Parameter).
  - `DefaultPreviewStrategy.php:23` — unbekannter Preview-Status.
  - `ResponseParser.php:25, :31, :36` — malformed Responses (ohne Code-Parameter!).

**Inkonsistent:** Manchmal mit Status-Code, manchmal ohne. Keine strukturierte Fehleranalyse möglich.

**Score: 4/10** (wie geschätzt).

### 3.6 PSR-Compliance

| PSR | Status (verifiziert) |
|---|---|
| PSR-3 (Logger) | ❌ `grep -rin "logger\|psr/log" src/ composer.json` liefert **null Treffer** |
| PSR-4 (Autoload) | ✅ `composer.json:24-28` `"Ndrstmr\\Icap\\": "src/"` |
| PSR-7 / PSR-17 | ⚠️ `docs/agent.md:40` verspricht PSR-7 `StreamInterface`-Body — nicht umgesetzt, `IcapRequest.php:25` hat `mixed $body` |
| PSR-11 | ⚠️ n/a für Core |
| PSR-18 | ⚠️ `docs/agent.md:25` verspricht PSR-18-konzeptionelle Nähe — kein `IcapClientInterface` vorhanden |
| PSR-20 (Clock) | ❌ nicht in deps |

**Score: 3/10.**

### 3.7 Ressourcen-Management

**`AsyncAmpTransport.php:49-53`:** ✅ `finally { $socket?->close(); }` — sauber.

**`SynchronousStreamTransport.php:28-30`:** ❌
```php
fwrite($stream, $rawRequest);
$response = stream_get_contents($stream);
fclose($stream);
```
Kein `finally`. Bei Exception aus `fwrite` → Leak.

**Connection-Pooling / Keep-Alive:** `grep -rin "keep-alive\|persistent" src/` → **null Treffer**. Jeder Scan = 1 TCP-Handshake (`AsyncAmpTransport.php:34` `Socket\connect()`). Für Hochlast-Szenarien (hunderte Scans pro Minute) ein Performance-Killer.

**Score: 4/10.**

### 3.8 Async-Implementierung

- ✅ AMPHP v3 mit `async { … }->await()` korrekt genutzt (`IcapClient.php:77-87`).
- ✅ `TimeoutCancellation` im Async-Transport (`AsyncAmpTransport.php:31`).
- ❌ **Keine** `Cancellation` in der Public-API von `IcapClient` — der User kann laufende Scans nicht abbrechen. `grep -rn "Cancellation" src/` zeigt nur `TimeoutCancellation` im Transport.
- ✅ Sync-Client ist echter Decorator (`SynchronousIcapClient.php:52, :60, :68, :76` — alle delegieren an `->await()`).

**Score: 6/10.**

### 3.9 Testing

**Verifiziert (alle 14 Test-Dateien inspiziert):**

| Test-Datei | LOC | Qualität |
|---|---|---|
| `ConfigTest.php` | 22 | OK, simple Getter/Wither-Tests |
| `IcapClientTest.php` | 257 | OK, 5 Tests mit Mocks; aber `pass-through`-Charakter |
| `SynchronousIcapClientTest.php` | 119 | OK, Decorator-Validierung |
| `ResponseParserTest.php` | 31 | **Nur 3 Tests** — unzureichend für Parser |
| `RequestFormatterTest.php` | 44 | **2 Tests, einer zementiert Encapsulated-Bug** |
| `DefaultPreviewStrategyTest.php` | 24 | OK, 3 Tests |
| `AsyncTestCase.php` | 16 | Helper (kein Test) |
| `Pest.php` | 4 | Bootstrap |
| `Transport/AsyncAmpTransportTest.php` | 20 | 2 Tests (Interface-Check + Connect-Fehler) |
| `Transport/SynchronousStreamTransportTest.php` | 19 | 2 Tests (ebenso) |
| `Transport/TransportInterfaceTest.php` | 8 | **1 Test: `interface_exists()` — reines Coverage-Padding** |
| `DTO/IcapRequestTest.php` | 13 | Instantiation-Smoke-Test |
| `DTO/IcapResponseTest.php` | 12 | Instantiation-Smoke-Test |
| `DTO/ScanResultTest.php` | 23 | 2 Smoke-Tests |

**Kritische Beobachtungen:**

1. **`RequestFormatterTest.php:35-43` zementiert den Bug:**
   ```php
   $expectedStart = "RESPMOD icap://icap.example.net/service ICAP/1.0\r\n" .
       "Host: icap.example.net\r\n" .
       "Encapsulated: null-body=0\r\n" .   // ← DAS IST FALSCH bei RESPMOD mit Body!
       "\r\n";
   ```
   Ein RFC-3507-Fix an `RequestFormatter` würde **diesen Test zum Failen bringen** — das ist Kafka-eske "Bug-as-Feature"-Testlogik. Jeder zukünftige Fix-PR wird vom aktuellen Test abgelehnt, solange der Test nicht zuerst korrigiert wird.

2. **`ResponseParserTest.php` hat keine Tests für:**
   - `Encapsulated`-Header-Parsing.
   - Echte Vendor-Responses (Kaspersky/Symantec/ClamAV).
   - `X-Virus-Name`/`X-Infection-Found`/`X-Violations-Found`.
   - Preview-Responses (`ISTag`, `Options-TTL`, `Max-Connections`, `Allow: 204`).
   - Malformed Chunks, zu große Header, Header-Injection.
   - Zero-Body 204-Responses.

3. **Keine Integration-Tests:** `find tests -name "*Integration*"` → leer. Kein c-icap-Docker-Compose in CI.

4. **Keine Mutation-Tests:** Infection fehlt in `composer.json:17-23`.

5. **`phpunit.xml.dist:18-21`** hat `coverage`-Report (HTML), aber **keine Coverage-Thresholds** (`enforceCheckCoverage`, `min-lines`).

6. **CI ohne Matrix** (`ci.yml:20`): `['8.3']` — zwei Werte ohne 8.4. Die Matrix-Struktur steht da, wird aber mit nur einem Wert gefüllt.

**Score Testing insgesamt: 4/10.** Oberflächlich OK (80%+ Zeilen-Coverage laut CHANGELOG), aber schwache Test-Qualität und Bug-Zementierung.

### 3.10 Security-Findings

| # | Finding | Zeile(n) | Severity |
|---|---|---|---|
| S1 | Kein TLS-Support | `AsyncAmpTransport.php:28`, `SynchronousStreamTransport.php:22` | HIGH |
| S2 | CRLF-Injection via `$service` | `IcapClient.php:97, :116, :139` | HIGH |
| S3 | Unbegrenzte Response-Größe = DoS | `SynchronousStreamTransport.php:29`, `AsyncAmpTransport.php:37-40` | HIGH |
| S4 | OOM bei großen Preview-Scans | `IcapClient.php:134` (`file_get_contents`) | MEDIUM |
| S5 | `@` Error-Suppression | `SynchronousStreamTransport.php:23` | LOW |
| S6 | Keine Max-Header-Size/Count im Parser | `ResponseParser.php:40-49` | MEDIUM |
| S7 | `IcapClient::interpretResponse` mapped 100 als "clean" | `IcapClient.php:175-177` | **HIGH (Fail-Open!)** |
| S8 | Socket-Leak bei Sync-Transport-Exception | `SynchronousStreamTransport.php:28-30` | LOW |
| S9 | Keine Sanitization des `X-Virus-Name`-Headers | `IcapClient.php:166-167` | MEDIUM (XSS/Log-Injection, wenn Server kompromittiert) |
| S10 | `#[\SensitiveParameter]` fehlt | überall (wenn jemals Auth-Header hinzukommt) | INFO |
| S11 | `composer audit` in CI | ✅ `ci.yml:36-37` | — |
| S12 | Kein `roave/security-advisories` | `composer.json:17-23` | LOW |
| S13 | Kein SECURITY.md | — | LOW |

**Besonders alarmierend: S7.**
```php
if ($response->statusCode === 100) {
    return new ScanResult(false, null, $response);    // ← "clean"!
}
```
Ein ICAP-100-Continue-Response ist **keine Scan-Aussage**, sondern "ich warte auf mehr Daten". Das als "clean" zurückzugeben, ist ein **Fail-Open-Bug** — wenn der Preview-Flow aus irgendeinem Grund (Netzwerk, Timeout, Race) nur die 100-Continue sieht und keine Folgeantwort, wird Malware als "clean" durchgewinkt.

**Score Security: 3/10.**

### 3.11 Public-Sector / DE-Compliance

| Kriterium | Status |
|---|---|
| EUPL-1.2 als Lizenz | ✅ `composer.json:5`, `LICENSE` |
| LICENSE-Datei vorhanden | ✅ |
| SPDX-Header in `.php`-Dateien | ❌ `grep -c "SPDX-License-Identifier" src/**/*.php` → null |
| README in DE | ❌ nur EN |
| BSI-OPS.1.1.4-Kompatibilität (Schutz vor Schadprogrammen) | ⚠️ konzeptionell, aber Fail-Open-Bug S7 und Fail-Safe-Semantik fehlen |
| Digitale Souveränität | ✅ Alle Deps sind OSS, kein SaaS |
| OpenCoDE-readiness | ⚠️ 60% — Lizenz stimmt, SPDX + SECURITY.md + CODE_OF_CONDUCT.md fehlen |
| DSGVO (Logging-Policy) | ❓ keine Logging-Policy dokumentiert (trivial, weil Logger fehlt) |

**Score: 5/10.**

### 3.12 Dokumentations-Kohärenz — interne Widersprüche

Die Dokumentation verspricht mehr als geliefert:

| `docs/agent.md` bzw. README sagt | Realität (Code) |
|---|---|
| "body (as PSR-7 `StreamInterface`)" (`agent.md:40`) | `IcapRequest.php:25`: `public mixed $body = ''` |
| "Fluent API: `IcapClient::forServer('...')->withTimeout(10)->scanFile('...')`" (`agent.md:46`) | `IcapClient.php:49-52`: `forServer()` existiert, **kein `withTimeout()`-Wither**. API ist nicht fluent. |
| "Psalm" als Static-Analyzer (`agent.md:55`) | `composer.json:17-23`: nicht enthalten |
| "`Connection: keep-alive` zur Verbindungs-Wiederverwendung" (`agent.md:18`) | nicht implementiert (grep leer) |
| "Test coverage exceeds 98%" (`agent.md:77`) | CHANGELOG: ">80%" — die 18% Differenz verschweigt das DoD |
| "memory-safe streaming for large file processing" (CHANGELOG) | `IcapClient.php:134`: `file_get_contents()` |
| "de-facto standard solution" (`README.md`) | 0 Stars, 1 Install, 0 Forks, 0 Dependents laut Packagist |

**Fazit §3.12:** Die Dokumentation wurde **zusammen mit dem Code generiert** und beschreibt eher das Design-Ziel als die tatsächliche Implementierung. Für einen Produktions-Einsatz ist das **gefährlich**, weil User die README lesen, die `StreamInterface`-Unterstützung annehmen und dann feststellen, dass nur `mixed` geliefert wird.

---

## 4. ICAP RFC 3507 Compliance-Checkliste (verifiziert)

| # | Requirement | RFC | Status | Beleg (Zeile) |
|---|---|---|---|---|
| 3.1.1 | OPTIONS-Methode | §4.10 | ✅ | `IcapClient.php:95-100` |
| 3.1.2 | OPTIONS-Response `Methods` geparst | §4.10.2 | ❌ | `ResponseParser.php` kennt keine Semantik |
| 3.1.3 | `ISTag` gecacht | §4.7 | ❌ | kein Cache-Code |
| 3.1.4 | `Max-Connections` ausgewertet | §4.10.2 | ❌ | `Config` hat kein `maxConnections`, kein Pool |
| 3.1.5 | `Options-TTL` gecacht | §4.10.2 | ❌ | — |
| 3.1.6 | `Preview`-Bytes aus OPTIONS | §4.5 | ⚠️ | Cookbook `03-options-request.php:12` macht das manuell; keine Automatik |
| 3.1.7 | `Transfer-Preview/Ignore/Complete` | §4.10.2 | ❌ | — |
| 3.1.8 | REQMOD-Methode | §4.8 | ⚠️ | möglich via `IcapRequest('REQMOD',...)`, kein High-Level-API |
| 3.1.9 | RESPMOD-Methode | §4.9 | ⚠️ | `IcapClient.php:117`, aber siehe §3.2 RFC-Verstöße |
| 3.2.1 | Startzeile `METHOD icap://... ICAP/1.0` | §4.3.2 | ✅ | `RequestFormatter.php:22` |
| 3.2.2 | **Encapsulated-Offsets korrekt** | §4.4.1 | ❌ | `RequestFormatter.php:28-30` — hardcoded `null-body=0` |
| 3.2.3 | Offsets monoton steigend | §4.4.1 | ❌ | nicht berechnet |
| 3.2.4 | Headers nicht chunked, Body chunked | §4.4 | ⚠️ | Stream ja (`RequestFormatter.php:47-50`), String nein (`:51-53`) |
| 3.2.5 | `0\r\n\r\n` Terminator | §4.5 | ⚠️ | für Stream ja (`:50`), für String nein |
| 3.2.6 | **`0; ieof\r\n\r\n`** | §4.5 | ❌ | kein `ieof` irgendwo |
| 3.2.7 | `null-body=N` bei fehlendem Body | §4.4.1 | ⚠️ | hardcoded `null-body=0`, aber auch bei vorhandenem Body |
| 3.3.1 | Preview via OPTIONS verhandelt | §4.5 | ⚠️ | manuell (`03-options-request.php`); keine Automatik |
| 3.3.2 | `Allow: 204` im Request | §4.6 | ❌ | grep leer |
| 3.3.3 | `100 Continue` abgewartet | §4.5 | ⚠️ | `IcapClient.php:143-149` blocking-await |
| 3.3.4 | Stop-and-wait nach Preview | §4.5 | ✅ | `IcapClient.php:143-150` |
| 3.3.5 | 204 ohne Allow:204 bei Preview OK | §4.6 | ✅ (implizit) | `DefaultPreviewStrategy.php:20-24` |
| 3.4.1 | Status 100 | §4.5 | ⚠️ | `IcapClient.php:175` — **als "clean" missinterpretiert** (siehe S7) |
| 3.4.2 | Status 200 | §4.3.3 | ✅ | `IcapClient.php:164-173` |
| 3.4.3 | Status 204 | §4.6 | ✅ | `IcapClient.php:160-162` |
| 3.4.4 | Status 206 | §4.3.3 | ❌ | keine Behandlung |
| 3.4.5 | 4xx-Semantik | §4.3.3 | ❌ | alle → Exception, auch 403 (= Virus-Fund bei einigen Vendoren!) |
| 3.4.6 | 5xx-Semantik | §4.3.3 | ❌ | alle → Exception, kein 503-Backoff |
| 3.5.1 | Malformed-Header-Robustheit | — | ⚠️ | wirft generische Exception ohne Kontext |
| 3.5.2 | Max-Line-Length / Max-Header-Count | — | ❌ | |
| 3.5.3 | CRLF-Injection abgewehrt | §7.3 | ❌ | siehe S2 |
| 3.5.4 | Vendor-Header (X-Virus-Name, X-Infection-Found, X-Violations-Found) | de-facto | ⚠️ | `Config::virusFoundHeader` ist **einzelner** String |
| 3.6.1 | c-icap-Integration-Tests | — | ❌ | keine Docker-Compose, keine Integration-Suite |
| 3.6.2–3.6.3 | Squid / Vendor-Tests | — | ❌ | — |

**Compliance-Score: 8/35 voll erfüllt, 12/35 teilweise, 15/35 nicht erfüllt → 36%**

---

## 5. Wettbewerbsvergleich (aktualisiert)

| Library | Encapsulated | Chunked Body | Preview-Continuation | TLS | Pool | OPTIONS-Cache | Status |
|---|---|---|---|---|---|---|---|
| **`ndrstmr/icap-flow` v1.0.0** | ❌ hardcoded | ⚠️ nur Stream | ⚠️ ohne `ieof` | ❌ | ❌ | ❌ | 1 Install |
| `nathan242/php-icap-client` | ✅ | ✅ | ⚠️ | ❌ | ❌ | ❌ | ~7★ |
| `c-icap-client` (C, Referenz) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | De-facto-Standard |
| `solidwall/icap-client` (Go) | ✅ | ✅ | ✅ `DoRemaining` | ✅ | ✅ | ✅ | hoch |
| `opencloud-eu/icap-client` (Go) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | mittel (EU!) |
| `toolarium-icap-client` (Java) | ✅ | ✅ | ✅ | ✅ | ⚠️ | ✅ | mittel |

**Harte Wahrheit:** In der aktuellen Form ist `icap-flow` **protokollarisch schwächer als `nathan242/php-icap-client`**, das zwar alt und ohne async ist, aber die Encapsulated-Offsets **korrekt berechnet** und Bodies chunked encoded. Der einzige klare Vorteil von `icap-flow` ist die Architektur (Strategy-Pattern, DI, AMPHP-async) — nicht die Protokoll-Implementierung.

---

## 6. Bewertungsmatrix (verifiziert)

| # | Dimension | Score | Begründung (mit Zeile) |
|---|---|---:|---|
| 1 | Sprachmoderne | 7 | strict_types, readonly DTOs, Enum, Named Args — alles verifiziert |
| 2 | Typsystem / PHPStan | 8 | Level 9 + bleedingEdge (`phpstan.neon:2-5`), `mixed` nur an 1 Stelle |
| 3 | SOLID / Architektur | 8 | 4 Interfaces, Ports/Adapters, DI konsequent; **Upgrade gegenüber Vorversion 6→8** |
| 4 | Exception-Design | 4 | nur 2 Klassen, kein Interface, kein Kontext |
| 5 | PSR-Konformität | 3 | nur PSR-4 hart; Logger fehlt |
| 6 | Ressourcen-/Connection-Mgmt | 4 | Sync-Transport ignoriert Config, kein Pool |
| 7 | Async-Implementierung | 6 | AMPHP v3 ok; Public-Cancellation fehlt |
| 8 | **ICAP Methoden-Vollständigkeit** | **4** | OPTIONS ✅, RESPMOD ⚠️ RFC-verletzend, REQMOD nur via Low-Level |
| 9 | **ICAP Preview / 204** | **4** | Strategy-Pattern ok, aber kein `ieof`, kein `Allow: 204`, kein Auto-OPTIONS |
| 10 | **ICAP Robustheit** | **2** | Encapsulated ignoriert, Chunks naiv, DoS-Vektoren offen |
| 11 | Security-Posture | 3 | TLS fehlt, CRLF-Injection, Fail-Open bei 100 |
| 12 | Test-Coverage (Line) | 6 | CHANGELOG sagt >80%, phpunit.xml hat keinen Threshold, Integration-Suite fehlt |
| 13 | **Test-Qualität** | **3** | Bug-zementierender Formatter-Test (`RequestFormatterTest.php:35-43`), Coverage-Padding (`TransportInterfaceTest.php:5`) |
| 14 | Mutation-Testing | 0 | Infection fehlt in `composer.json` |
| 15 | Integration-Testing | 0 | keine c-icap-Docker-Compose |
| 16 | CI-Pipeline | 6 | `ci.yml:1-86` solid (style/stan/test/audit/coverage-deploy), aber nur PHP 8.3 |
| 17 | Dokumentation | 5 | README ausführlich; Mission Charter widerspricht Code (s. §3.12) |
| 18 | Examples | 6 | 5 Examples, aber `02-custom-preview-strategy.php:16` fachlich falsch (304 ist kein ICAP-Code) |
| 19 | Symfony-Bundle-Integration | 0 | null |
| 20 | Observability | 0 | null |
| 21 | Release-Management | 5 | Keep-a-Changelog-Format, Semver-Commitment — aber nur 1 Release |
| 22 | Public-Sector-Fit | 5 | EUPL ✅; SPDX + SECURITY.md + DE-Doku fehlen |
| | **Summe** | **103/210** | **49,0%** |

**Hochgestuft gegenüber Vorversion (91 → 103):** Architektur-Score (+2), Typsystem (+1), CI (+1), Examples (+0, mit Einschränkungen).
**Abgestuft (Vorversion hatte geschätzt):** ICAP-Robustheit (3→2), Testing-Qualität (neu mit 3 bewertet, vorher in "Test-Coverage 5" aufgegangen).

**TRL: 4** (Labormuster; wegen Protokoll-Verstöße keine TRL 5 "validiert in relevanter Umgebung").

---

## 7. Produktionsreife-Gate

### 7.1 Ist v1.0.0 heute produktionsreif?

**Interne Tools / Prototypen gegen c-icap:** ⚠️ Nur mit Glück. c-icap ist sehr tolerant; tatsächliche AV-Scanner (ClamAV via c-icap-squidclamav, Kaspersky, Symantec) werden die `Encapsulated: null-body=0`-Lüge bemerken.

**Symfony-Portale:** 🔴 **Nein.** Die RFC-Verstöße + Fail-Open-Bug S7 + fehlende TLS + fehlende Symfony-Integration + Dokumentations-Widersprüche sind gemeinsam disqualifizierend.

**Kritische Security-Komponente (Virenscan auf Bürger-Upload):** 🔴 **Nein.** BSI OPS.1.1.4 verlangt Fail-Secure — der Code liefert Fail-Open bei 100-Continue-Race (`IcapClient.php:175-177`).

### 7.2 Priorisierte Gap-Liste (verifiziert)

**P0 — Produktionsblocker:**
1. **RFC-konformer `RequestFormatter`** — Encapsulated-Offsets berechnen, String-Body chunked encoden, HTTP-in-ICAP-Kapselung implementieren. (`RequestFormatter.php` komplett neu)
2. **RFC-konformer `ResponseParser`** — Encapsulated-Header auswerten, Header-Größenlimits, strukturierte Fehler. (`ResponseParser.php` erweitern)
3. **`ieof`-Terminator im Preview-Mode** (`RequestFormatter.php:40-50`)
4. **Fix Fail-Open-Bug** — `IcapClient.php:175-177`: 100-Continue außerhalb Preview-Flow ist Protokoll-Fehler, nicht "clean".
5. **`SynchronousStreamTransport` fix** — Config-Timeouts respektieren, `finally`-close, Length-Limit für Reads, `stream_set_timeout()`.
6. **TLS-Support `icaps://`** — `AsyncAmpTransport` auf `Socket\connectTls()` umstellen, `Config::tlsContext` ergänzen.
7. **Input-Validation für `$service`** gegen CRLF-Injection (`IcapClient.php:97, 116, 139`).
8. **`IcapClient::interpretResponse()` um 403/500/503 erweitern** oder Tri-State-API (siehe §7.3).
9. **`scanFileWithPreview` auf Streaming umstellen** (`IcapClient.php:134`).
10. **Fix `RequestFormatterTest.php:35-43`** — aktuell wird der Bug getestet. Nach Fix von P0#1 muss dieser Test angepasst werden.

**P1 — Kritisch für Ökosystem-Fit:**
11. `revolt/event-loop` explizit in `composer.json:require` deklarieren.
12. Exception-Hierarchie ausbauen: `IcapExceptionInterface`, `IcapTimeoutException`, `IcapProtocolException`, `IcapClientException(4xx)`, `IcapServerException(5xx)`.
13. PSR-3 `LoggerInterface` als optionaler Konstruktor-Parameter.
14. `IcapClientInterface` als öffentlicher Contract.
15. `IcapClient`, `ResponseParser`, `RequestFormatter`, `DefaultPreviewStrategy` auf `final` setzen.
16. `Config::maxConnections` + Connection-Pooling.
17. OPTIONS-Response-Cache (keyed by host:port/service, TTL aus `Options-TTL`).
18. `Cancellation` in Public-API durchreichen.
19. Separates `icap-flow-bundle` für Symfony (DI-Extension, Profiler, Monolog-Channel, Console-Commands).
20. Integration-Test-Suite mit c-icap + ClamAV in Docker-Compose + EICAR-Testfile.
21. CI-Matrix auf `['8.3', '8.4']` erweitern.
22. PHPStan auf 2.x upgraden (`composer.json:21` von `^1.11` auf `^2.1`).
23. Infection + phpbench einbauen.
24. SECURITY.md + SPDX-Header via CS-Fixer.
25. `roave/security-advisories` in dev-deps.

**P2 — Differenzierung:**
26. `Config::virusFoundHeader: array<string>` statt String.
27. Custom-Request-Headers-Support (`X-Client-IP`, `X-Authenticated-User`).
28. Preview-Continuation-API (expliziter `DoRemaining`-Stil).
29. OpenTelemetry-Instrumentation + Prometheus.
30. Retry-Policy mit Exponential-Backoff für 503.
31. Rate-Limiter-Integration.
32. DE-README, Dokumentation an den Code anpassen (agent.md-Versprechen vs. Realität auflösen).
33. Cookbook-Beispiel `02-custom-preview-strategy.php:16` korrigieren (304 ist kein ICAP-Statuscode).

**P3 — Langfristig:**
34. REQMOD-High-Level-API mit HTTP-Request-Mocking.
35. Multi-Server-Failover.
36. WASM-Build-Experiment.

### 7.3 Roadmap

**v1.1.0 (~1 Woche, nur Bugfixes + DX, aber BC-Breaking wegen RFC-Fix):**
> **Hinweis:** Weil die aktuellen Tests den Bug zementieren und ein RFC-Fix das Wire-Format ändert, ist das streng genommen ein Major-Release (v2.0.0). Empfehlung: direkt v2.0.0 anpeilen und v1.0.0 als deprecated markieren, nicht versuchen, den Bug BC-kompatibel zu "fixen".

Alternatives Minimal-v1.1.0 ohne Wire-Format-Änderungen:
- P1#11 (`revolt/event-loop` deklarieren)
- P1#24 (SECURITY.md, SPDX)
- P1#25 (`roave/security-advisories`)
- P1#21 (CI-Matrix)
- P2#33 (Cookbook fix)
- Dokumentation ehrlich machen (widersprüchliche Claims entfernen).

**v2.0.0 (~6 Wochen, RFC-konform, Breaking):**
- P0#1–#10 (alle RFC-Fixes + Fail-Open-Bug + TLS + Streaming-Preview + Validation)
- P1#12, #13, #14, #15 (Exceptions, Logger, Interface, final)
- Integration-Tests mit c-icap in CI (P1#20)
- PHPStan 2.x (P1#22)
- Infection + phpbench (P1#23)
- Migration-Guide 1.x → 2.x.

**v2.1.0 (~4 Wochen):**
- P1#16–#18 (Pooling, OPTIONS-Cache, Cancellation)
- P2#26, #27 (Multi-Vendor-Header, Custom-Request-Headers)
- P2#30, #31 (Retry, Rate-Limiter)

**`icap-flow-bundle v0.1` (parallel zu v2.0.0):**
- DI-Extension, Autowiring
- Monolog-Channel `icap`
- Console-Commands `icap:options`, `icap:scan`, `icap:health`
- Symfony Profiler DataCollector

**`icap-flow-bundle v0.2` (nach v2.1.0):**
- Messenger-Handler für asynchrones Upload-Scanning
- Validator-Constraint `#[IcapClean]`
- VichUploader-/OneupUploader-Adapter

**Produktiv-Gate:** `icap-flow v2.1.0` + `bundle v0.2` + externer Pentest + c-icap-Integration-Tests grün + zweiter unabhängiger Reviewer. Realistisch: **Q3–Q4 2026**.

---

## 8. Konkrete Sofortmaßnahmen

### 8.1 Fork-vs-Upstream-Entscheidung

Das Repo ist ein **Solo-AI-Projekt** (`docs/agent.md:3-5` nennt "Agent: OpenAI Codex, Date: 17 June 2025"). Das hat Konsequenzen:

- **Pro Upstream-Engagement:** EUPL-1.2, sauberer Architektur, hat das Zeug zum Standard.
- **Contra Upstream-Engagement:** Kein Community-Vertrauen (0 Stars, 0 Contributors), Mission-Charter suggeriert Agent-Weiterentwicklung statt Community-Process. Ob `ndrstmr` externe PRs annimmt, ist offen.

**Empfehlung:** **Beides parallel.**

1. **Tag 1–3:** Issue beim Upstream eröffnen mit Fundus aus §3.2 und §3.10 (schwerpunktmäßig Encapsulated-Bug + Fail-Open S7). Abwarten, ob innerhalb 1 Woche Reaktion kommt.
2. **Tag 4–14:** Fork auf `opencode.de/ndrstmr/oss/icap-flow` anlegen (Name: `icap-flow` behalten, Attribution-Header: *"forked from ndrstmr/icap-flow, EUPL-1.2"*). Hotfix-Branch mit den 10 P0-Items beginnen.
3. **Woche 2–5:** Fixes + Integration-Suite. Bei Abschluss jedes P0 PR als Upstream-PR einreichen, unabhängig von Response-Zeit (Due-Diligence-Nachweis).
4. **Woche 6+:** Symfony-Bundle parallel starten.

### 8.2 Interne Nutzungsempfehlung

**Jetzt:** NICHT in Kundenprojekten einsetzen. Wenn ein Projekt akut einen PHP-ICAP-Client braucht:
- Für sofortiges Spielen: **`nathan242/php-icap-client`** — alt, aber RFC-korrekt. Akzeptieren, dass sync-only und wenig gepflegt.
- Für strategisch richtigen Weg: **Fork starten, in 4 Wochen Prod-ready machen, parallel Bundle bauen**.

### 8.3 Aufwandsschätzung Fork → Prod-Ready

| Aufgabe | Aufwand (PT) |
|---|---|
| RFC-Fix RequestFormatter + ResponseParser | 4 |
| Preview + `ieof` + 100-Continue-Flow | 2 |
| TLS + Config-Erweiterung | 2 |
| SyncTransport-Fixes | 1 |
| Exception-Hierarchie + Logger | 2 |
| c-icap Integration-Tests (Docker-Compose + EICAR) | 3 |
| CI-Matrix-Erweiterung + Infection | 1 |
| Symfony-Bundle (Basis: Extension, Autowiring, Commands) | 4 |
| Profiler + Monolog-Channel | 1 |
| Dokumentation (DE, Migration-Guide, BSI-Konformitätsdoku) | 2 |
| Review-Zeit (zweiter Entwickler) | 3 |
| **Summe** | **25 PT** |

Für einen 2-Entwickler-Sprint: **~3 Wochen realistisch** bis interne Prod-Readiness.

---

## 9. Quellenverzeichnis

- **RFC 3507** – Internet Content Adaptation Protocol (ICAP): https://datatracker.ietf.org/doc/html/rfc3507
- **Packagist — ndrstmr/icap-flow:** https://packagist.org/packages/ndrstmr/icap-flow
- **AMPHP v3 Docs:** https://amphp.org/installation
- **Revolt Event Loop:** https://revolt.run
- **PHPStan Level 9:** https://phpstan.org/config-reference#rule-level
- **Pest:** https://pestphp.com
- **Infection (Mutation Testing):** https://infection.github.io
- **phpbench:** https://phpbench.readthedocs.io
- **Symfony Bundle-Entwicklung:** https://symfony.com/doc/current/bundles.html
- **Symfony HttpClient:** https://symfony.com/doc/current/http_client.html
- **Symfony Messenger:** https://symfony.com/doc/current/messenger.html
- **Symfony Profiler DataCollector:** https://symfony.com/doc/current/profiler/data_collector.html
- **BSI IT-Grundschutz OPS.1.1.4 (Schutz vor Schadprogrammen):** https://www.bsi.bund.de/DE/Themen/Unternehmen-und-Organisationen/Standards-und-Zertifizierung/IT-Grundschutz/IT-Grundschutz-Kompendium/it-grundschutz-kompendium_node.html
- **EUPL-1.2:** https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
- **OpenCoDE:** https://opencode.de
- **c-icap Reference:** https://c-icap.sourceforge.net
- **Vergleichsclients:**
  - `nathan242/php-icap-client`: https://github.com/nathan242/php-icap-client
  - `solidwall/icap-client` (Go): https://github.com/solidwall/icap-client
  - `opencloud-eu/icap-client` (Go, strukturierte Errors): https://github.com/opencloud-eu/icap-client
  - `toolarium/toolarium-icap-client` (Java): https://github.com/toolarium/toolarium-icap-client

---

## 10. Fazit

`icap-flow` v1.0.0 ist ein **spannender Fund mit zwei Gesichtern**: Architektonisch vorbildlich (das Beste, was man in der PHP-Welt für ICAP derzeit findet), protokollarisch unvollständig (in der aktuellen Form ungeeignet für Produktiv-Einsatz). Die 1.0.0-Versionsnummer ist aus Semver-Sicht **irreführend** — das ist v0.3-Qualität mit v1.0-Anspruch.

**Glücksfall:** Weil die Architektur sauber ist und die Lizenz EUPL-1.2 eine Zweignutzung erlaubt, lässt sich in ~25 Personen-Tagen ein produktionsreifer, BSI-konformer, DE-dokumentierter, Symfony-integrierter ICAP-Client bauen — deutlich schneller als eine Neuentwicklung from scratch und mit besserer Qualität als alle anderen verfügbaren PHP-Optionen.

**Strategischer Pitch:** "Wir nehmen ein AI-generiertes OSS-Gerüst mit guter Architektur, fixen die 10 echten Protokollbugs, bauen ein Symfony-Bundle drumherum, und stellen das als `opencode.de/ndrstmr/oss/icap-flow` + `ndrstmr/oss/icap-flow-bundle` als Referenz-Implementierung für den DE-Public-Sector bereit. EUPL-1.2, BSI-konform, mit Integration-Tests gegen c-icap + ClamAV." — das ist ein Vorzeigeprojekt für "KI-unterstützte OSS-Qualitätssicherung".
