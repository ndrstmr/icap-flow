# Deep Research Prompt: ICapFlow v2.1.0 Production-Readiness & Technical Excellence Audit

> **Einsatz:** In Claude Code (Opus 4.7), OpenAI Codex (GPT‑5-Codex), oder Google (Gemini 3.1 Pro) mit aktiviertem Deep Research / Web Search / Repository-Zugriff einfügen.
> **Ziel:** Vollständige, unabhängige technische Begutachtung des Repositories `ndrstmr/icap-flow` **in der aktuellen Version v2.1.0** mit konkreten, umsetzbaren Empfehlungen.
> **Vorgänger:** [`deep-research-prompt-icapflow_v1.md`](deep-research-prompt-icapflow_v1.md) — die v1.0.0-Findings aus den drei Reviews (Claude, Codex, Jules) sind in [`consolidated_task-list.md`](consolidated_task-list.md) konsolidiert und laut Maintainer in v2.0/v2.1 geschlossen. **Verifiziere das nicht durch Lesen des Self-Reports — verifiziere es am Code.**
> **Autor-Kontext:** Andreas (ndrstmr), Entwicklungsleiter im öffentlichen Sektor und Initiator der 'public sector dev crew'.

---

## 1. Rolle & Auftrag

Du bist **Principal PHP/Symfony Engineer** mit 15+ Jahren Erfahrung in der Entwicklung von Enterprise-Grade PHP-Bibliotheken, tiefer Kenntnis des Symfony-Ökosystems (HttpClient, Messenger, Lock, DependencyInjection), des asynchronen PHP-Ökosystems (Amphp v3, Revolt, ReactPHP, Swoole/OpenSwoole), RFC-konformer Netzwerkprotokoll-Implementierungen und der Anforderungen des deutschen öffentlichen Sektors (BSI-Grundschutz, Digitale Souveränität, EUPL, OpenCoDE).

Dein Auftrag ist eine **ehrliche, gründliche, vollständig unabhängige Due-Diligence-Analyse** des Repositories `ndrstmr/icap-flow` (v2.1.0, Packagist: `ndrstmr/icap-flow`). Du bewertest, ob diese Bibliothek **produktionsreif** ist für den Einsatz in Symfony-basierten Web-Portalen und E-Commerce-Systemen (TYPO3, Shopware) der öffentlichen Verwaltung — insbesondere in der **Security-kritischen Rolle eines Virenscan-Gateways auf File-Uploads** — und erarbeitest einen konkreten Weg zur nächsten Stufe (`v2.2`, `v3.0`, Begleit-Bundle).

**Wichtig:**
- Keine Gefälligkeitsbewertung. Finde echte Schwachstellen — sowohl neue als auch Regressionen aus der v2-Umstellung.
- **Verifiziere Closure-Claims des Maintainers** aus `CHANGELOG.md`, `README.md`, `docs/migration-v1-to-v2.md` und `docs/review/consolidated_task-list.md` durch eigenständigen Code-Review. Nicht "vertraue dem Self-Report", sondern "stimmt das?".
- Belege jede Aussage mit konkreten Datei-/Zeilen-Referenzen.
- Trenne Fakten (aus dem Code) und Empfehlungen (dein Urteil) klar.
- Quellen (RFCs, Symfony-Docs, Fachartikel) explizit verlinken.

---

## 2. Repository-Kontext (bereits bekannt)

| Attribut | Wert |
|---|---|
| Repository | https://github.com/ndrstmr/icap-flow |
| Packagist | https://packagist.org/packages/ndrstmr/icap-flow |
| Sprache | PHP **8.4+** (CI-Matrix 8.4 + 8.5) |
| Aktuelle Version | **v2.1.0** (Release: 25.04.2026) |
| v1.0.0 | **deprecated** — RFC-3507-blockierende Bugs + Fail-Open auf Status 100 |
| v2.0.0 | 25.04.2026 — Major-Break, RFC-3507-Wire-Format, Security-Hardening, Multi-Vendor-Header, OPTIONS-Cache, Retry-Decorator, Encapsulated-Aware Framing |
| v2.1.0 | 25.04.2026 — Keep-Alive Connection-Pool + strict RFC 3507 §4.5 Preview-Continue (Same-Socket) |
| Lizenz | EUPL-1.2 |
| Async-Stack | `amphp/socket ^2.3` + `revolt/event-loop ^1.0` (jetzt explizit deklariert) |
| Logging | `psr/log ^3.0` (optional, NullLogger-Default) |
| Linting | PHPStan **2.x Level 9 + bleedingEdge**, PHP-CS-Fixer (PSR-12) |
| Tests | Pest 3 / PHPUnit 11; Stand v2.1.0: **91 passed, 187 assertions** (Unit) |
| Mutation | `composer mutation` (lokal, Pest 3 mutate) — kein CI-Job (entfernt in M4) |
| CI | GitHub Actions (`.github/workflows/ci.yml`): quality-and-tests (8.4 + 8.5), integration (c-icap + ClamAV via docker-compose), deploy-coverage (gh-pages) |
| Integration-Tests | gegen `mnemoshare/clamav-icap` (c-icap 0.6.3 + ClamAV) |
| Namespace | `Ndrstmr\Icap` |
| Public Surface | `IcapClientInterface`, `IcapClient` (final), `SynchronousIcapClient`, `RetryingIcapClient`, `Config` (final readonly), `RequestFormatter`/`ResponseParser` (final), Exceptions, DTOs (`HttpRequest`, `HttpResponse`, `IcapRequest`, `IcapResponse`, `ScanResult`), Cache (`OptionsCacheInterface`, `InMemoryOptionsCache`), Transport (`TransportInterface`, `SessionAwareTransport`, `TransportSession`, `AsyncAmpTransport`, `AmpTransportSession`, `AmpConnectionPool`, `ConnectionPoolInterface`, `ResponseFrameReader`, `SynchronousStreamTransport`) |

### v2-Strukturänderungen, die Reviewer kennen müssen

- **`IcapRequest`**: `mixed $body` ist weg. Encapsulated-Payload via typisierte Slots `?HttpRequest $encapsulatedRequest`, `?HttpResponse $encapsulatedResponse`, plus `bool $previewIsComplete` (für `0; ieof`).
- **`RequestFormatterInterface::format()` → `iterable<string>`** (Streaming statt One-Shot-String).
- **`TransportInterface::request()`** akzeptiert `iterable<string>` als raw request, plus optionalen `?Amp\Cancellation`.
- **`SessionAwareTransport`** (Subinterface): `openSession(): TransportSession` für Multi-Round-Trip-Flows (strict §4.5 preview-continue, Pipelining).
- **Connection-Pool** über `ConnectionPoolInterface` (LIFO-Stack pro `host:port[:tls]`-Key, `AmpConnectionPool`-Default, optional in `AsyncAmpTransport(?ConnectionPoolInterface $pool = null)`).
- **`ResponseFrameReader`** rahmt ICAP-Responses anhand von Bytes (Encapsulated-Header → `null-body` oder `req-body|res-body=$offset`, dann chunked terminator `0[; ext]\r\n\r\n`). Kein `Connection: close`-Hack mehr.
- **`Config`** ist `final readonly`, mit `withTlsContext()`, `withVirusFoundHeaders()`, `withLimits()`, DoS-Defaults `maxResponseSize=10 MiB`, `maxHeaderCount=100`, `maxHeaderLineLength=8192`.
- **Exception-Taxonomy**: Marker `IcapExceptionInterface`; `IcapProtocolException`, `IcapMalformedResponseException` (extends Protocol), `IcapClientException` (4xx), `IcapServerException` (5xx), `IcapTimeoutException`, `IcapConnectionException`, `IcapResponseException`.
- **Status-Mapping**: `204` clean, `200`/`206` virus-header-Inspektion, `100` außerhalb Preview → `IcapProtocolException` (fail-secure), `4xx` → `IcapClientException`, `5xx` → `IcapServerException`.
- **`RetryingIcapClient`** Decorator: exponential backoff, **nur** auf `IcapServerException` (5xx).
- **`InMemoryOptionsCache`** + `OptionsCacheInterface`, TTL aus `Options-TTL` (RFC 3507 §4.10.2).
- **`ChunkedBodyEncoder`** (extrahiert aus `RequestFormatter`) — wird vom strict §4.5-Pfad und vom Formatter geteilt.

Top-Level: `.github/workflows/`, `docs/`, `examples/` inkl. `examples/cookbook/`, `src/`, `tests/` (mit `Wire/`, `Security/`, `Transport/`, `Exception/`, `DTO/`, `Integration/`), `docker-compose.yml`, `composer.json`, `phpstan.neon`, `phpunit.xml.dist`, `.php-cs-fixer.dist.php`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`, `README.md`, `LICENSE`.

---

## 3. Scope & Abgrenzung

### 3.1 In Scope — das GESAMTE Repository

Du untersuchst **jede Datei**, mit besonderem Fokus auf die in v2.0 / v2.1 neu hinzugekommenen oder grundlegend umgebauten Bereiche:

1. **`src/`** — Vollständige Inspektion. Schwerpunkte:
   - `Transport/AmpConnectionPool.php`, `Transport/AmpTransportSession.php`, `Transport/AsyncAmpTransport.php`, `Transport/ResponseFrameReader.php`, `Transport/SessionAwareTransport.php`, `Transport/TransportSession.php`, `Transport/SynchronousStreamTransport.php`, `Transport/ConnectionPoolInterface.php`, `Transport/TransportInterface.php` — Connection-Lifecycle, Pool-Korrektheit, Framing-Robustheit.
   - `IcapClient.php` (jetzt deutlich größer durch strict §4.5 + legacy fallback + status-matrix + logger), `RetryingIcapClient.php`, `SynchronousIcapClient.php`, `IcapClientInterface.php`.
   - `RequestFormatter.php`, `ChunkedBodyEncoder.php`, `ResponseParser.php` — Wire-Format-Korrektheit, Header-Folding (RFC 7230 §3.2.4), Encapsulated-Offset-Berechnung.
   - `Cache/InMemoryOptionsCache.php`, `Cache/OptionsCacheInterface.php` — TTL-Semantik, Cache-Key-Konstruktion, Cross-Process-Implikationen.
   - `DefaultPreviewStrategy.php`, `PreviewStrategyInterface.php`, `PreviewDecision.php`.
   - `Exception/*` — vollständige Hierarchie + `IcapExceptionInterface`-Marker.
   - `DTO/*` — `readonly`, Immutability, `mixed` body in `HttpRequest`/`HttpResponse` (string|resource|null).
   - `Config.php` — `final readonly`, alle `with…()`-Wither, Validation-Boundary.
2. **`tests/`** — Test-Abdeckung & -Qualität. Schwerpunkte: `Wire/RequestFormatterWireTest.php`, `Wire/ResponseParserWireTest.php` (hand-computed RFC-3507-Bytes), `Security/FailSecureAndValidationTest.php`, `Security/ParserDosLimitsTest.php`, `Transport/AmpConnectionPoolTest.php`, `Transport/ResponseFrameReaderTest.php`, `Transport/AsyncAmpTransportTest.php`, `PreviewContinueStrictTest.php`, `OptionsCacheTest.php`, `RetryingIcapClientTest.php`, `CancellationTest.php`, `MultiVendorVirusHeadersTest.php`, `LoggerIntegrationTest.php`, `CustomRequestHeadersTest.php`, `Integration/IcapServerSmokeTest.php`.
3. **`examples/` inkl. `examples/cookbook/`** — Realitätsnähe, Vollständigkeit, Didaktik. Werden Connection-Pool, Retry-Decorator, OPTIONS-Cache, TLS, externes `Cancellation`, Custom-Header demonstriert?
4. **`.github/workflows/ci.yml`** — Matrix (8.4 + 8.5), `composer audit`, PHPStan, CS-Fixer, Coverage-Upload, Integration-Job mit c-icap-Readiness-Probe, `roave/security-advisories`. Was fehlt? (Mutation-Job ist bewusst entfernt — bewerten.)
5. **`docs/`** — `agent.md` (historisch v1-Charter, klar gekennzeichnet), `migration-v1-to-v2.md`, `review/*`. Vollständigkeit einer öffentlichen API-Doku (phpDocumentor/Doctum) — vorhanden? Architecture Decision Records?
6. **Root-Konfiguration** — `composer.json` (deps, autoload, scripts, platform-pin auf 8.4.0, suggest fehlt?), `phpstan.neon` (Level 9 + bleedingEdge, gezielte ignoreErrors für Pest-Internals — sauber begründet?), `phpunit.xml.dist` (`beStrictAboutCoverageMetadata=true`, separate Integration-Suite), `docker-compose.yml`, `CHANGELOG.md` (Keep-a-Changelog-Disziplin), `CONTRIBUTING.md`, `SECURITY.md`, `LICENSE` (EUPL-1.2).
7. **Git-Historie & Issues/PRs** — Commit-Qualität (Conventional Commits?), Release-Kadenz v1→v2.0→v2.1, offene Issues, Security-Advisories, BC-Promise-Disziplin innerhalb der v2-Linie.

### 3.2 Out of Scope

- ICAP-Server-Implementierungen (außer als Vergleich/Testing-Target).
- Generische PHP-Einführung.
- Reine v1-Findings, die v2 sauber geschlossen hat — **außer du findest sie in v2.1 wieder offen** (Regressionen).

---

## 4. Analyse-Phasen

Sieben aufeinander aufbauende Phasen. Dokumentiere nach jeder Phase Zwischenergebnisse, bevor du zur nächsten wechselst.

### Phase 1 — Repository-Inventar & v1→v2.1-Diff

**Output:**
- Vollständige Dateiliste mit LOC `src/` und `tests/`. (Erwartet ~3.5k / ~3.1k LOC.)
- Test-zu-Code-Ratio.
- Klassen-/Interface-/Enum-Diagramm (Mermaid) inkl. Abhängigkeiten — markiere v1-bestehend / v2.0-neu / v2.1-neu.
- Dependency-Graph aus `composer.json` (runtime + dev) inkl. transitiver Pfade aus `composer.lock` für sicherheitsrelevante Pakete (`amphp/socket`, `revolt/event-loop`, `psr/log`, `roave/security-advisories`).
- Öffentliche API-Oberfläche (jede `public`-Methode auf `final` Klassen + Interfaces) → Tabelle für SemVer-Kontrolle.
- **v2.0-Closure-Verifikation**: Die 14 Findings A–N aus [`consolidated_task-list.md`](consolidated_task-list.md) — gehe sie einzeln durch und belege per Code-Ref, ob sie tatsächlich geschlossen sind. Das ist der wichtigste Output dieser Phase.

### Phase 2 — Code- & Architektur-Analyse

#### 2.1 Sprachmoderne & Typsystem (PHP 8.4-Niveau)
- `readonly` Klassen (`Config`, DTOs)? Konstruktor-Property-Promotion durchgängig?
- PHP 8.4-Features tatsächlich genutzt? (Property hooks, asymmetric visibility, `#[\Deprecated]`, `array_find`, `new MyClass()->method()` ohne Klammern, Lazy Objects)
- `#[\Override]`-Disziplin auf Interface-Implementierungen?
- `mixed` nur dort, wo PHP es nicht besser kann (`HttpRequest::$body: string|resource|null`)? Sind Generics-Phpdocs (`@template`, `@param-out`, `@phpstan-impure`) gepflegt?
- PHPStan **Level 9 + bleedingEdge** ohne Baseline — verifiziere durch lokalen Lauf, finde Schwachstellen die `ignoreErrors` möglicherweise versteckt.
- Strikte Vergleiche, `declare(strict_types=1)` durchgängig (alle Source-Files haben EUPL-Header + `declare`).

#### 2.2 Design, Pattern & SOLID
- SRP der neuen Bausteine: `ResponseFrameReader` (Framing), `AmpTransportSession` (Lifecycle), `AmpConnectionPool` (Pool), `ChunkedBodyEncoder` (Encoding) — sind die Schnitte sauber?
- **Decorator-Pattern**: `RetryingIcapClient` als Wrapper um `IcapClientInterface` — clean implementiert? (Idempotenz beachtet? Retry auf nicht-idempotenten Operationen ein Risiko?)
- **Strategy**: `PreviewStrategyInterface` + `DefaultPreviewStrategy` — entkoppelt von Statuscode-Mapping?
- **Pool-Pattern**: LIFO-Stack pro Host, kein Idle-Sweep, `Connection: close`-Honor-Pfad — bewerte gegen verbreitete HTTP-Connection-Pool-Implementierungen (Guzzle, Symfony HttpClient, Java OkHttp).
- DTO-Design: `final readonly` (Config, DTOs)? Wither-Pattern statt Setter? `__construct`-Validation am System-Boundary?
- Hexagonal/Clean Architecture: Ist der Schnitt `Transport ↔ Formatter/Parser ↔ Client ↔ Decorator` rein, oder gibt es Lecks?

#### 2.3 PSR-Compliance
- **PSR-3** Logger ✓ (optional, NullLogger-Default, drei strukturierte Events `started/completed/failed`). Bewerte: kein PII im Log? Loglevel sinnvoll?
- **PSR-4** Autoload sauber? Alle DTOs unter `Ndrstmr\Icap\DTO\…`, alle Exceptions unter `Ndrstmr\Icap\Exception\…`.
- **PSR-7/PSR-17** bewusst nicht für ICAP-Body genutzt (Streaming via `iterable<string>` + native PHP-Resource). Beurteile diese Designentscheidung pro/contra.
- **PSR-11** kein direkter Container — sauber, da framework-agnostisch.
- **PSR-18** Inspirationsquelle? `IcapClientInterface` kommt nahe.
- **PSR-20 (Clock)**: `InMemoryOptionsCache` nutzt `time()` + `clockOffsetSeconds`-Test-Seam — sollte das ein injizierter `ClockInterface` werden? Tradeoff bewerten.

#### 2.4 Fehlerbehandlung & Exception-Design
- Hierarchie vollständig? `IcapExceptionInterface` als Marker auf jedem konkreten Typ?
- Recoverable vs. non-recoverable klar getrennt? `IcapServerException` recoverable (= retry-bar), Rest nicht — verifiziere am Code von `RetryingIcapClient`.
- Exception-Chaining (`previous`) auf TLS-/Connect-Fehlern (`IcapConnectionException`)?
- Sprechende Messages **ohne** sensible-Daten-Leakage (z.B. kein Body im Exception-Text)?
- `LogicException` für invalid Session-Lifecycle (`AmpTransportSession::assertActive()`) — angemessen?

#### 2.5 Ressourcen-Management & Connection-Handling
- Sockets schließen: `AmpConnectionPool::close()` (idempotent? thread-safe genug für Fiber-Kontext?), `AmpTransportSession::release()` vs. `close()` (disposed-Flag, doppelter Release ist no-op).
- **Pool-Korrektheit**: was passiert bei Exception **zwischen** `acquire()` und `write()`? Was, wenn `write()` selbst wirft? Was bei `Cancellation`-trigger mitten im `readResponse()`? — In `AsyncAmpTransport::request()` wird `closeForced=true` bei jedem `Throwable` gesetzt, auch bei `CancelledException` — Socket wird geschlossen statt zurückgegeben. Bewerte ob das die richtige Default-Policy ist (vermutlich ja).
- **Half-closed connections**: wenn ein Server `Connection: close` setzt, schließt der Transport. Was, wenn der Server den Socket einseitig zumacht **ohne** Header? Detection im nächsten `acquire()` via `isClosed()` — reicht das?
- **Race-Condition zwischen `isClosed()` und `read()`**: Klassisches TOCTOU bei jedem Connection-Pool. Wie mitigiert?
- Backpressure beim Streaming großer Encapsulated-Bodies (Multi-GB) durch chunked encoder? `fread()` in 8 KiB / amphp default chunks — verifiziere.
- Memory-Footprint: Verifiziere, dass `scanFile()` und `scanFileWithPreviewStrict()` **nie** den ganzen File-Body buffern. (Achtung: `scanFileWithPreviewLegacy` macht `rewind($stream)` + komplette Re-Sendung — bewerte das.)
- Timeouts granular (`socketTimeout` Connect / `streamTimeout` Read-Write) — durchgängig respektiert?
- **§4.5 Strict-Pfad**: Im strict path wird der `100 Continue` Body-only fortgesetzt. Was passiert, wenn das Server-eigene `100 Continue` z.B. `ICAP/1.0 100 Continue\r\n\r\n` ist und nicht `ICAP/1.1`? Akzeptiert der Parser das? (`ResponseParser` matcht `ICAP/1\.\d` — sollte gehen.)

#### 2.6 Async-Implementierung
- Sauberer Einsatz von `Amp\async()`, `Amp\Future`, `Amp\Cancellation`, `CompositeCancellation`, `TimeoutCancellation`.
- Cancellation durchgereicht von `IcapClient` → `Transport` → `Session` → `socket->read($cancellation)`?
- Fiber-Sicherheit: Pool ist **nicht** explizit synchronisiert. Im PHP-Single-Thread-Fiber-Modell ist das ok, **außer** zwei Fibers `acquire()`-en gleichzeitig dieselbe Idle-Liste während eines `array_pop()`. Verifiziere (in PHP sind Single-Statement-Operationen atomar bzgl. Fiber-Switching, aber nicht offensichtlich).
- Interaktion mit **Symfony 7.x Fiber-Support** (HttpClient, etc.) — Library-API verträgt sich?
- `RetryingIcapClient::$sleeper` Default ist `Amp\delay()` — blockiert das im sync-Fall (`SynchronousIcapClient`), oder gibt es Probleme?

### Phase 3 — ICAP-Protokoll-Compliance (RFC 3507) — verifizieren am v2.1-Code

#### 3.1 Methoden
- **OPTIONS** — Capability Discovery: `Methods`, `ISTag`, `Max-Connections`, `Options-TTL` (in Cache verwendet? Verifiziere am `IcapClient::options()`-Cache-Set-Pfad), `Preview`, `Transfer-Preview`, `Transfer-Ignore`, `Transfer-Complete`. Wird **`Max-Connections`** ausgelesen und an den Pool propagiert? (Code-Kommentar in `AmpConnectionPool::release()` sagt: "future enhancement". Ist das ein P1-Gap?)
- **REQMOD** — vollständig implementiert oder nur Skelett?
- **RESPMOD** — Hauptpfad für `scanFile()`. Vollständig?
- High-Level: `scanFile()`, `scanFileWithPreview()`, `options()`, plus generisches `request(IcapRequest)`. Symmetrie zwischen async & sync API?

#### 3.2 Nachrichten-Format
- ICAP-Startzeile (`METHOD icap://… ICAP/1.0`) — RequestFormatter:135 — sauber.
- ICAP-Header inkl. `Encapsulated`-Offsets — `RequestFormatter::buildEncapsulatedHeader()`. Bei mehreren Sektionen (req-hdr + res-hdr + req-body): Reihenfolge & Offsets korrekt? Schreibe ein Hand-Test-Fixture und prüfe.
- HTTP-in-ICAP: `renderHttpRequestHeaders()` / `renderHttpResponseHeaders()` — RFC 7230 conformes HTTP/1.1 mit CRLF-Terminator.
- Chunked-Transfer-Encoding via `ChunkedBodyEncoder` — beide Paths (Formatter + strict §4.5 continuation) nutzen denselben Encoder.
- `0; ieof\r\n\r\n` beim letzten Chunk wenn `previewIsComplete=true` — verifiziere.
- **Header-Reihenfolge**: `Host` first, `Encapsulated` last — bewertet `ResponseFrameReader` korrekt, wenn ein Server die Reihenfolge anders rendert?

#### 3.3 Preview-Feature (§4.5)
- Preview-Size-Aushandlung via OPTIONS `Preview`-Header — wird das in `scanFileWithPreview($previewSize=1024)` aus dem OPTIONS-Cache gelesen oder ist es Caller-Verantwortung? Code-Check: aktuell **Caller-only** — bewerte als P1-Gap.
- `Allow: 204` immer gesetzt im Preview-Pfad ✓.
- `100 Continue` wird durch `PreviewStrategyInterface::handlePreviewResponse()` interpretiert (legacy + strict). Verifiziere: nur strict-path nutzt denselben Socket; legacy-path baut neue Verbindung mit Vollkörper auf (zweiter TCP/TLS-Handshake — bewerte als Sub-Optimum für Sync-Fallback).
- Strict-§4.5: Phase 1 = `RESPMOD` mit Preview-Bytes, Phase 2 = nur Body-Remainder als Chunk-Stream. **Verifiziere mit `tests/PreviewContinueStrictTest.php`** dass die Connector-Anzahl exakt 1 ist (das ist der RFC-konforme State).

#### 3.4 Statuscodes
Vollständige Matrix in `IcapClient::interpretResponse()`:
- `100` außerhalb Preview → `IcapProtocolException` ✓ (fail-secure, Finding G).
- `204` → `ScanResult(clean)` ✓.
- `200` / `206` → Virus-Header-Inspektion über `getVirusFoundHeaders()` ✓.
- `4xx` → `IcapClientException(code)` ✓.
- `5xx` → `IcapServerException(code)` ✓ (retry-bar).
- Andere → `IcapResponseException`.

Prüfe Edge-Cases: `408 Request Timeout`, `503 Service Unavailable` (häufigster transienter Fehler — Retry-Defaults passen?), `505 ICAP Version Not Supported`.

#### 3.5 Robustheit & Security im Parser
- `ResponseParser::parseHeaderBlock()` honouriert RFC 7230 §3.2.4 obsolete folding ✓ (für c-icap multi-line `X-Violations-Found`). Test in `Wire/ResponseParserWireTest.php` vorhanden — Coverage ausreichend?
- Malformed Headers → `IcapMalformedResponseException` ✓.
- DoS-Limits: `maxResponseSize`, `maxHeaderCount`, `maxHeaderLineLength` enforced sowohl in `ResponseFrameReader` als auch `ResponseParser`. Verifiziere keinen Off-by-one (`+1 für Status-Line`).
- Header-Injection: CR/LF/NUL/Control auf `$service` (`validateServicePath()`) und auf jedem User-Header-Name + Wert (`validateIcapHeaders()`). Prüfe ob auch URL-Encoding-Tricks (`%0d%0a`) abgewiesen werden — vermutlich nicht (kein URL-Decode), aber doppelt prüfen.
- **Integer-Overflow**: `$bodyOffset = (int) hexdec(...)` — bei sehr großem hex-Chunk-Size auf 32-bit PHP? Heutige Targets sind 64-bit, aber prüfen.

#### 3.6 Kompatibilitätstests
- **c-icap + ClamAV** via `mnemoshare/clamav-icap` — als Integration-Job in CI ✓. EICAR-Detection über `X-Violations-Found` — verifiziert.
- Andere Vendor-Server: dokumentiert getestet **gegen** Symantec / Sophos / Kaspersky / Trend Micro / McAfee Web Gateway? README-Hinweise existieren, aber keine Integration-Pipeline. Bewerte als P1-Gap (oder bewerte ob sich das überhaupt als Library-Verantwortung schultern lässt — Vendor-spezifische Header schon konfigurierbar).
- **Squid** als ICAP-Client-Seite: nicht direkt relevant (icap-flow ist selbst Client), aber als Referenz-Implementation für Wire-Format-Vergleich nutzbar.

### Phase 4 — Security & Compliance-Assessment

#### 4.1 Security Posture v2.1
- **Fail-secure-Verhalten**: jeder Pfad, in dem ein Fehler "weich" als Clean enden könnte, muss durch Test belegt sein. Prüfe `tests/Security/FailSecureAndValidationTest.php` auf Vollständigkeit der Status-Matrix-Coverage (`100`, `4xx`, `5xx`, malformed-response, oversized-response, slow-loris-Header).
- **CRLF-Guard auf `$service` und Headern** ✓. Aber: Was, wenn `$extraHeaders`-**Werte** Multi-Line via Array sind (`['X-Foo' => ['line1', 'line2']]`)? Wird Index-by-Index validiert?
- **TLS / `icaps://`** via `ClientTlsContext`. Bewerte: Default-Cipher-Policy von amphp v3 secure? Hostname-Verification per Default an?
- **Credentials in Logs**: `LoggerIntegrationTest.php` deckt 3 Events ab. Prüfe, dass keine Header-Inhalte (insbesondere `X-Authenticated-User`) in das Log-Context geschrieben werden — heute werden nur `method/uri/host/port/statusCode/infected` geloggt, keine Header. ✓ Verifiziere.
- **Dependency-Security**: `composer audit` läuft in CI ✓. `roave/security-advisories: dev-latest` als dev-dep ✓. Lizenz-Compatibility-Check (alle deps EUPL-1.2-kompatibel)?
- **Pool-Specific Risiken**:
  - Connection-Confusion zwischen verschiedenen `Config`-Instanzen mit identischem `host:port[:tls]`-Key aber **unterschiedlichen TLS-Contexten** (Cert Pinning, Client-Cert)? Der Pool-Key ist binär `:tls`/kein-`:tls` — verschiedene `ClientTlsContext`-Objekte landen im selben Pool. **Bewerte als potenzielles P0/P1-Security-Issue** wenn ein Caller mehrere Configs für unterschiedliche ICAP-Mandanten parallel verwendet.
  - Cross-Tenant-Datenleck wenn ein Socket nach `Connection: close` (vom Server gewollt) trotzdem zurückgegeben wird? Code prüft `serverWantsClose()` ✓ und schließt. Lückenlos?
- **SSRF**: `Config::host` kommt vom Anwender (Library-User, nicht End-User). Wenn ein Web-Portal `host` aus Konfiguration zieht — kein direktes SSRF. Aber wenn jemand `host` aus User-Input zieht (eher Anti-Pattern, dokumentieren!).

#### 4.2 Öffentliche Verwaltung & Compliance
- **EUPL-1.2** ✓ (Header in jeder Source-Datei verifiziert? Stichproben).
- **OpenCoDE-Kompatibilität**: License + EU-Public-Sector-Tauglichkeit ✓. Ist das Repo auf OpenCoDE gespiegelt oder wird es?
- **BSI IT-Grundschutz**: Relevante Bausteine sind CON.6 Löschen und Vernichten / OPS.1.1.4 Schutz vor Schadprogrammen / APP.4.4 Webanwendungen. Dokumentiert das `SECURITY.md` / `README.md` den Mapping-Bezug? Heute eher nicht — bewerte als Doku-Gap.
- **Digitale Souveränität**: Alle deps Open Source, EU-/community-gehostet. Keine SaaS-Abhängigkeiten ✓.
- **GDPR/DSGVO**: Was geht in `Logger` rein? `LoggerInterface` ist optional und plug-bar — DSGVO-Verantwortung beim Caller. Dokumentiert?
- **AI-assisted origin disclaimer** im README ✓ — bewerte Tonalität: ehrlich? hinreichend warnend für Security-kritischen Einsatz?

### Phase 5 — Testing & Qualitätssicherung

- **Unit-Test-Coverage** lines/branches/methods. Erwartet ≥ 90% (Pest Coverage HTML in `build/coverage-html/`). Verifiziere lokal oder per gh-pages-Report. Welche Pfade sind UN-covered?
- **Mutation Testing**: `composer mutation` (Pest 3 mutate) lokal verfügbar, **nicht in CI**. CI-Job wurde in M4 entfernt mit Begründung "Pest-3-Mutation-CLI nicht stabilisiert". Bewerte: ist das v2.1.x ein gangbarer Stand oder muss das vor v2.2 in CI?
- **Integration-Tests**: `tests/Integration/IcapServerSmokeTest.php` gegen `mnemoshare/clamav-icap`. Skip-by-default (`ICAP_HOST` env). CI startet docker-compose + readiness-Probe — robust gegen ClamAV-Bootstrap-Latenz. Bewerte ob `continue-on-error: true` in CI verhüllt, dass die Integration intermittierend rot ist.
- **Property-Based Testing** (Eris, phpunit-generator): nicht vorhanden. Würde sich für `ResponseParser` / `ResponseFrameReader` rentieren? P2.
- **Benchmark-Tests** (phpbench): nicht vorhanden. Würde sich für Pool-Throughput, Chunked-Encoder-Durchsatz, Strict-§4.5-Latency-Vorteil rentieren? P2.
- **Fuzzing** für Parser: nicht vorhanden. Mit Symfony's `php-fuzzer` oder einfaches AFL-style-Setup? P2/P3.
- **Test-Architektur**: klare Trennung `tests/Wire/`, `tests/Security/`, `tests/Transport/`, `tests/Integration/` ✓. `tests/Pest.php` Bootstrap, `tests/AsyncTestCase.php` für async-Tests. Bewerte Konsistenz Pest vs. PHPUnit-Stil-Mix.
- **Determinismus**: `RetryingIcapClient::$sleeper`-Test-Seam ✓, `InMemoryOptionsCache::advanceClockForTesting()` ✓. Andere Timing-Quellen?
- **`phpunit.xml.dist`**: `beStrictAboutCoverageMetadata=true`, `beStrictAboutOutputDuringTests=true`, `failOnRisky=false`, `failOnWarning=false` — Warum nicht beide auf `true`?

### Phase 6 — Symfony-Integration & Ökosystem-Fit (v2.1-Stand)

#### 6.1 Bundle-Frage
- `consolidated_task-list.md` plant ein eigenes Repo `icap-flow-bundle` post-v2.0. Existiert das schon? Wenn ja, ist es publiziert? Wenn nein, **welcher Stand wäre minimal-viable**:
  - `IcapFlowBundle` mit `Configuration`-Tree (`host`, `port`, `socket_timeout`, `stream_timeout`, `tls`, `virus_found_headers[]`, `limits.*`, `pool.max_connections_per_host`, `retry.*`, `options_cache.adapter`).
  - `IcapFlowExtension` mit Service-Definitionen für `Config`, `AsyncAmpTransport`, `AmpConnectionPool`, `IcapClient`, `RetryingIcapClient`, `InMemoryOptionsCache` (Default) + Adapter für PSR-6 / PSR-16 / Symfony-Cache.
  - Auto-DI-Aliase: `IcapClientInterface` → `RetryingIcapClient` → inner `IcapClient`.
  - Tagged-Service-Support: mehrere ICAP-Konfigurationen (`icap_flow.client.<name>`).
- Bewerte: Library-Core bleibt framework-agnostisch — gut. Aber: ohne offizielles Bundle ist die Symfony-Adoption schwerer.

#### 6.2 Framework-Features
- **Symfony Profiler** DataCollector für ICAP-Aufrufe — fehlt.
- **Monolog Channel** `icap` — vom Bundle bereitstellbar, aber Library-PSR-3-Logger reicht aus.
- **Messenger-Integration**: Async-Scanning via Message-Queue. Beispiel im `examples/cookbook/`? Heute nicht.
- **Console-Command**: `icap:scan <file>`, `icap:options <service>` für CLI-Debugging — fehlt.
- **Flex-Recipe**: nicht eingereicht (`symfony/recipes-contrib`)? Status?
- **Environment-Variablen**-Prozessoren (`%env(ICAP_HOST)%` etc.) — würde das Bundle abdecken.
- **Validator**: `#[IcapClean]` Constraint für File-Upload-Validation — typische Use-Case-Erweiterung, fehlt heute.
- **VichUploaderBundle / OneupUploaderBundle**: Integration-Hooks für Virus-Scan-on-Upload — typische Anwendung, fehlt.

#### 6.3 Observability
- **OpenTelemetry** Instrumentation (Traces, Metrics) — fehlt; mit `open-telemetry/api` als opt-in-Decorator möglich.
- **Prometheus** Exporter (Counter `icap_requests_total{status,vendor}`, Histogram `icap_request_duration_seconds`) — fehlt.
- **Health-Check-Endpoint-Komponente**: ein OPTIONS-Probe-Helper für Liveness-Checks wäre sinnvoll.

### Phase 7 — Benchmarking gegen andere ICAP-Clients

#### 7.1 PHP-Welt
- Suche andere Packagist-Pakete mit `icap` im Namen (Stand 2026). Vergleich Aktualität / Feature-Set / Maintenance.
- Speziell: gibt es bereits ein konkurrierendes `*-bundle` für Symfony?

#### 7.2 Andere Sprachen (als Maßstab)
Vergleich entlang der Achsen *RFC-Coverage / Streaming / Pool / TLS / Multi-Vendor-Header / Cancellation*:
- **Java**: Apache HttpComponents-style Clients, `c-icap-client` (C, aber viele Java-Bindings), `greasyspoon` historisch.
- **Python**: `pyicap`, `icap-client`.
- **Go**: `egirna/icap-client`, `solidwall/icap-server`.
- **Node.js**: `icap-client`-Pakete (qualitativ stark variierend).
- **.NET**: `ICAPClient` NuGet, RegMagik-Forks.
- **Rust**: Crates.io `icap`-Suchergebnisse.

Welche Patterns dort etabliert (z.B. zero-copy-Body-Streaming, formal-verifizierte Parser) hat icap-flow noch nicht?

#### 7.3 Referenzimplementierung
- **c-icap** (https://sourceforge.net/projects/c-icap/) — Wire-Format-Reference. Stichprobenartig: schreibt c-icap an Stellen, die icap-flow rendert, Byte-identische Antworten? (z.B. bei Multi-Section-Encapsulated.)
- **Squid + ICAP-Client-Seite** als Vergleichsimplementierung der Client-Verhaltensweisen (Pool-Size-Defaults, Preview-Negotiation).

---

## 5. Bewertungsmatrix (quantitativ)

Erstelle am Ende eine Scoring-Tabelle. Jede Dimension 0–10 Punkte, mit Begründung und Code-Refs. **Vergleiche zusätzlich gegen die hypothetischen v1.0.0-Werte** (aus dem v1-Prompt-Output) um Fortschritt sichtbar zu machen.

| Dimension | v1.0.0 (Referenz) | v2.1.0 (Score) | Begründung | Kritische Findings |
|---|---|---|---|---|
| Sprachmoderne (PHP 8.4-Features) | | | | |
| Typsystem / PHPStan-Strenge (Level 9 + bleedingEdge) | | | | |
| SOLID / Architektur-Klarheit | | | | |
| Exception-Design + Marker-Interface | | | | |
| PSR-Konformität | | | | |
| Ressourcen-/Connection-Management | | | | |
| Connection-Pool-Korrektheit (v2.1-spezifisch) | n/a | | | |
| Async-Implementierung (Amp v3 / Revolt) | | | | |
| Cancellation-Propagation | n/a | | | |
| ICAP RFC 3507 Methoden-Vollständigkeit | | | | |
| ICAP §4.5 Strict Preview-Continue (v2.1) | n/a | | | |
| ICAP §4.10.2 Options-TTL-Cache | n/a | | | |
| ICAP Robustheit (Parser, Edge Cases, RFC 7230 Folding) | | | | |
| Multi-Vendor-Header-Support | n/a | | | |
| Security-Posture (Fail-Secure, CRLF-Guard, DoS-Limits, TLS) | | | | |
| Test-Coverage (Lines/Branches) | | | | |
| Wire-Format-Tests (Hand-Computed Bytes) | n/a | | | |
| Mutation Testing (CI?) | | | | |
| Integration-Testing gegen echte Server | | | | |
| CI-Pipeline-Qualität (Audit, Multi-PHP, Integration) | | | | |
| Dokumentation (README, Migration-Guide, Reviews) | | | | |
| Example-/Cookbook-Qualität | | | | |
| Symfony-Bundle-Integration | | | | |
| Observability (Logger, OTel, Profiler) | | | | |
| Release-Management / Semver / Changelog | | | | |
| Public-Sector-Fit (EUPL, BSI, OpenCoDE, Souveränität) | | | | |
| **Gesamt-Readiness-Score** | **/260** | **/260** | | |

Zusätzlich: **TRL-Einschätzung v2.1.0** (1–9) mit Begründung; Vergleich zur (geschätzten) v1.0.0-TRL.

---

## 6. Produktionsreife-Gate

### 6.1 Ist v2.1.0 heute produktionsreif?
- **Für interne Tools / Prototypen**: Ja / Mit Einschränkungen / Nein.
- **Für Symfony-Applikationen in Projekten (TYPO3, Shopware, Portale)**: Ja / Mit Einschränkungen / Nein.
- **Für den Einsatz als kritische Security-Komponente (Virenscan auf Upload)**: Ja / Mit Einschränkungen / Nein. Beachte den README-AI-Disclaimer als Maintainer-Position — bewerte ob die getroffene Selbsteinschätzung deckungsgleich mit deinem Befund ist.

### 6.2 Was fehlt zum "technisch perfekten" State?
Priorisierte Liste der Gaps:
- **P0 (Blocker für Produktion)** — Echte Security- / Korrektheits-Mängel die jetzt zu fixen sind. (Erwartet: wenig, da v2.0 die v1-Blocker geschlossen hat. Mögliche Kandidaten: Pool-Key-Cross-TLS-Context-Confusion, fehlende Max-Connections-Auswertung, fehlende OPTIONS-Cache-Adapter-PSR-6/16.)
- **P1 (Kritisch für Ökosystem-Fit)** — Symfony-Bundle, OpenTelemetry-Decorator, Pool-Idle-Eviction, OPTIONS-driven Preview-Size, Mutation-CI-Wiederbelebung.
- **P2 (Nice-to-have / Differenzierung)** — Property-Based-Tests, phpbench-Suite, Console-Commands, Validator-Constraint, Vich/OneupUploader-Adapter.
- **P3 (Langfristige Vision)** — Formal verified Parser, Multi-Vendor-Integration-Pipeline (Symantec/Kaspersky/Trend Micro), Fuzz-Korpus.

### 6.3 Konkrete Roadmap

Schlage eine versionsbasierte Roadmap vor — mit dem Wissen, dass v2.0 + v2.1 frisch sind:
- **v2.1.x** (Patch — reine Bugfixes / Doc-Klarstellungen, falls dein Review welche findet).
- **v2.2.0** (Minor — Pool-Idle-Eviction, OPTIONS-driven Preview-Size, OPTIONS-Cache-PSR-6-Adapter, OpenTelemetry-Decorator, Console-Commands).
- **v2.3.0** (Minor — Symfony-Bundle als separates Repo `icap-flow-bundle`, Validator-Constraint).
- **v3.0.0** (Major — nur falls Reviews echte Breaking Changes erfordern; sonst nicht). Z.B. PSR-3 → PSR-7-Body-Migration, oder Pool-Interface-Refactor für Cross-Tenant-Sicherheit.
- **Begleit-Repo**: `icap-flow-bundle` — Zeitpunkt empfehlen.

---

## 7. Output-Format & Deliverables

Liefere am Ende **eine strukturierte Antwort** mit folgenden Abschnitten (in dieser Reihenfolge):

1. **Executive Summary** (max. 500 Wörter) — Kernbefund, Empfehlung, TRL-Score, Vergleich v1→v2.1.
2. **Repository-Inventar v2.1.0** (Phase 1).
3. **v1-Findings-Closure-Verifikation** — Tabelle A–N + die vier M3-Follow-ups (Cancellation, OPTIONS-Cache, 503-Retry, Response-Framing) + die zwei v2.1-Themen (Pool, Strict §4.5). Pro Finding: `Status (verifiziert geschlossen / teilweise / Regression / nie behoben)`, Code-Ref, Begründung.
4. **Findings nach Dimension** (Phase 2–6 detailliert, Code-Refs im Format `src/Pfad/Datei.php:Z-Y`).
5. **ICAP RFC 3507 Compliance-Checkliste v2.1** (tabellarisch).
6. **Pool / Session-Lifecycle Threat-Analyse** (eigener Abschnitt — das ist die zentrale v2.1-Neuerung).
7. **Wettbewerbsvergleich** (Phase 7, tabellarisch).
8. **Bewertungsmatrix** (Kapitel 5, mit v1-Spalte als Vergleich).
9. **Produktionsreife-Gate-Entscheidung** (Kapitel 6.1).
10. **Priorisierte Gap-Liste** (Kapitel 6.2).
11. **Roadmap v2.1.x → v2.2 → v2.3 → v3.0** (Kapitel 6.3).
12. **Quellenverzeichnis** — Alle konsultierten RFCs (3507, 7230, 7231, 9110), Symfony-Docs, Amp-Docs, Vendor-ICAP-Docs (c-icap, Symantec, Sophos, Kaspersky, Trend Micro), Vergleichs-Repos.

**Format:**
- Markdown, sinnvolle Überschriften (H2/H3).
- Code-Blocks mit Sprach-Tag.
- Tabellen für Matrizen und Checklisten.
- Inline-Zitate mit Links.
- Konkrete, umsetzbare Vorschläge — kein "man könnte"-Schwafeln, sondern "Füge in `src/Transport/AmpConnectionPool.php` nach Zeile X folgendes hinzu: [Code]".

**Sprache:** Deutsch. Fachtermini auf Englisch belassen. Ton: kollegial-direkt, keine Floskeln.

---

## 8. Quality Gates für deine Analyse

Bevor du deine finale Antwort abgibst, prüfe:

- [ ] Habe ich **jede Datei in `src/` mindestens einmal inspiziert** — besonders die v2.0/v2.1-Neuzugänge (`Transport/AmpConnectionPool.php`, `Transport/AmpTransportSession.php`, `Transport/ResponseFrameReader.php`, `Transport/SessionAwareTransport.php`, `Transport/TransportSession.php`, `RetryingIcapClient.php`, `Cache/InMemoryOptionsCache.php`, `Cache/OptionsCacheInterface.php`, `ChunkedBodyEncoder.php`, `IcapClientInterface.php`)?
- [ ] Habe ich **jede der 14 v1-Findings (A–N) sowie die 4 M3-Follow-ups** im aktuellen Code verifiziert geschlossen / teilweise / regressiert?
- [ ] Habe ich **jede Test-Datei** auf Coverage-Lücken geprüft — insbesondere `tests/Wire/`, `tests/Security/`, `tests/Transport/`, `tests/PreviewContinueStrictTest.php`?
- [ ] Habe ich **RFC 3507 (besonders §4.4–§4.10), RFC 7230 (§3.2.4 Folding, §4.1 Chunked, §6.1 Connection-close), RFC 9110 (semantic)** konkret aufgeschlagen und gegen den Code geprüft — nicht aus dem Gedächtnis?
- [ ] Habe ich **mindestens drei Alternativ-Implementierungen** in anderen Sprachen als Benchmark angeschaut — und ehrlich verglichen?
- [ ] Ist **jede kritische Aussage mit Datei-/Zeilen-Referenz oder externer Quelle belegt**?
- [ ] Sind meine **Empfehlungen so konkret**, dass sie direkt als GitHub-Issue / PR-Diff geöffnet werden könnten?
- [ ] Habe ich **die Pool-/Session-Lifecycle-Sicherheit** (v2.1-Kernfeature) in einem eigenen Threat-Analyse-Abschnitt durchgekämmt — inkl. Cross-Tenant-, Cancellation-Mid-Read- und Race-Condition-Szenarien?
- [ ] Habe ich **die Symfony-/Public-Sector-Spezifika** (DI, Profiler, BSI, EUPL, OpenCoDE, Souveränität) ausreichend gewichtet?
- [ ] Habe ich **Self-Reports des Maintainers** (CHANGELOG, README, Migration-Guide, consolidated_task-list) als Hypothesen behandelt und am Code verifiziert — nicht als Wahrheit übernommen?
- [ ] Bin ich **ehrlich kritisch** oder habe ich unterbewusst wohlwollend bewertet, weil v2 viel Arbeit war?

Wenn eines dieser Gates nicht erfüllt ist, recherchiere nach und ergänze, bevor du abschließt.

---

## 9. Zusätzliche Hinweise

- **Wenn Informationen unzugänglich sind** (z.B. privater Repo-Teil, fehlende Issue-History, gh-pages-Coverage offline): explizit markieren, keine Halluzinationen.
- **Wenn du Annahmen triffst** (z.B. über nicht dokumentierte interne Motivation): als Annahme kennzeichnen.
- **Wenn du zwischen zwei Design-Alternativen abwägst**: beide Optionen mit Trade-offs darstellen, dann eine begründete Empfehlung.
- **Bei Ungewissheit über aktuelle Versionen** (Amp v3, Revolt 1.x, PHPStan 2.x, Pest 3, Symfony 7.x): aktuellen Stand recherchieren.
- **Vergleich v1↔v2.1**: Bei jedem Finding-Status erläutern, **wie** die Behebung erfolgte (Wire-Format-Test mit Hand-Computed-Bytes? Status-Matrix-Test? Pool-Test mit `Amp\Socket\createSocketPair`?). Der Refactor-Pfad ist Teil des Lernens.

**Starte jetzt mit Phase 1.**
