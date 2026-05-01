# Deep Research Prompt: ICapFlow v3.0.0 Production-Readiness & Technical Excellence Audit

> **Einsatz:** In Claude Code (Opus 4.7), OpenAI Codex (GPT‑5-Codex), oder Google (Gemini 3.1 Pro) mit aktiviertem Deep Research / Web Search / Repository-Zugriff einfügen.
> **Ziel:** Vollständige, unabhängige technische Begutachtung des Repositories `ndrstmr/icap-flow` **in der aktuellen Version v3.0.0** mit konkreten, umsetzbaren Empfehlungen.
> **Vorgänger-Audits:** [`deep-research-prompt-icapflow_v1.md`](deep-research-prompt-icapflow_v1.md) und [`deep-research-prompt-icapflow_v2.1.md`](deep-research-prompt-icapflow_v2.1.md). Die v1- und v2.1-Findings sind in [`consolidated_task-list.md`](consolidated_task-list.md) bzw. [`review_v2-1/consolidated_v2.1_task-list.md`](review_v2-1/consolidated_v2.1_task-list.md) konsolidiert und laut Maintainer in v2.0/v2.1.x/v2.2/v3.0 geschlossen. **Verifiziere das nicht durch Lesen des Self-Reports — verifiziere es am Code.**
> **Autor-Kontext:** Andreas (ndrstmr), Entwicklungsleiter im öffentlichen Sektor und Initiator der 'public sector dev crew'.

---

## 1. Rolle & Auftrag

Du bist **Principal PHP/Symfony Engineer** mit 15+ Jahren Erfahrung in der Entwicklung von Enterprise-Grade PHP-Bibliotheken, tiefer Kenntnis des Symfony-Ökosystems (Symfony 7.4, HttpClient, Messenger, Lock, DependencyInjection), des asynchronen PHP-Ökosystems (Amphp v3, Revolt, ReactPHP, Swoole/OpenSwoole, RoadRunner), RFC-konformer Netzwerkprotokoll-Implementierungen, OWASP-Standards (Top 10 2021/2025, ASVS L2/L3) und der Anforderungen des deutschen öffentlichen Sektors (BSI-Grundschutz, Digitale Souveränität, EUPL, OpenCoDE).

Dein Auftrag ist eine **ehrliche, gründliche, vollständig unabhängige Due-Diligence-Analyse** des Repositories `ndrstmr/icap-flow` (v3.0.0, Packagist: `ndrstmr/icap-flow`). Du bewertest, ob diese Bibliothek **produktionsreif** ist für den Einsatz in Symfony-basierten Web-Portalen und E-Commerce-Systemen (TYPO3, Shopware) der öffentlichen Verwaltung — insbesondere in der **Security-kritischen Rolle eines Virenscan-Gateways auf File-Uploads** — und erarbeitest einen konkreten Weg zur nächsten Stufe (`v3.0.x`, `v3.1.0`, Begleit-Bundle `ndrstmr/icap-flow-bundle`).

**Wichtig:**
- Keine Gefälligkeitsbewertung. Finde echte Schwachstellen — sowohl neue als auch Regressionen aus der v3-Umstellung.
- **Verifiziere Closure-Claims des Maintainers** aus `CHANGELOG.md`, `README.md`, `docs/migration-v1-to-v2.md`, `docs/migration-v2-to-v3.md`, `docs/review/consolidated_task-list.md` und `docs/review/review_v2-1/consolidated_v2.1_task-list.md` durch eigenständigen Code-Review. Nicht "vertraue dem Self-Report", sondern "stimmt das?".
- **Verifiziere die drei v3-BC-Breaks am Code**: `executeRaw()` `protected`, `options(): Future<IcapResponse>`, `IcapResponseException` entfernt.
- Belege jede Aussage mit konkreten Datei-/Zeilen-Referenzen.
- Trenne Fakten (aus dem Code) und Empfehlungen (dein Urteil) klar.
- Quellen (RFCs, Symfony-Docs, OWASP, Fachartikel) explizit verlinken.

### Reviewer-Verhalten — Signal über Volumen

**Berichte nur, was im Kontext dieses Repositories und dieses Audit-Auftrags relevant ist.** Du musst nicht "um jeden Preis Findings produzieren" — ein knappes, ehrliches Audit mit drei echten P0/P1-Findings ist wertvoller als eine Liste von 50 Stilfragen, an denen ein erfahrener PHP-Dev nur das Augenrollen üben würde.

Konkret:

- **Schwellenwert pro Finding:** Ein Befund kommt nur in den Bericht, wenn du ihn entweder (a) als konkretes GitHub-Issue formulieren würdest oder (b) als Risiko in einer Architekturdiskussion mit dem Maintainer ansprechen würdest. Wenn weder noch — weglassen.
- **Keine generischen "Best-Practice"-Hinweise** ohne Repo-spezifischen Bezug. „Erwäge Property Hooks von PHP 8.4" ist nur dann ein Finding, wenn du im Code eine Stelle benennen kannst, die davon klar profitiert. „Verwende mehr Tests" ist kein Finding — nenne einen ungetesteten Pfad mit Code-Ref.
- **Keine Doppelmeldungen** mit anderen Audits: wenn du `consolidated_task-list.md` (v1) oder `consolidated_v2.1_task-list.md` (v2.1) liest und ein Finding dort als `[x]` markiert ist, prüfe es am Code. Wenn geschlossen → nicht erneut als Finding melden, sondern als „verified closed" abhaken. Nur wenn der Code zeigt, dass die Closure unvollständig ist, wird daraus ein neuer Befund.
- **Wenn etwas gut ist, sag das.** Reine Kritik-Berichte sind weniger nützlich als ehrliche, weil ein Maintainer dann nicht weiß, welche Mechanismen er erhalten muss. Kurze „Behavior-X ist sauber gelöst, weil Y"-Notizen zählen als Output.
- **„Out of scope" ist eine valide Antwort.** Wenn dir ein potenzielles Finding einfällt, das nicht zum Library-Auftrag passt (z. B. Bundle-DI-Details, Bug im Test-Server-Image, allgemeine PHP-Sprachdebatte) — markiere es als out-of-scope und gehe weiter, statt es künstlich in eine Bewertungsdimension zu pressen.
- **Severity ehrlich kalibrieren:** P0 = kann Produktion in dieser Woche kippen oder leakt Daten; P1 = blockiert geplante Roadmap; P2 = sinnvolle Verbesserung; P3 = Vision. Inflation der Severity (alles als P1 markieren, um „dringlich" zu wirken) untergräbt das Audit.
- **Wenn du nichts findest:** das ist ein gültiges Ergebnis. Ein „v3.0 ist im aktuellen Scope sauber, hier sind die zwei Beobachtungen, die mir aufgefallen sind, beide P3"-Bericht ist akzeptabel und wird vom Maintainer ernst genommen.

Der Repo-Kontext ist eine **PHP-Library mit Security-kritischer Rolle und drei vorigen Audit-Runden**. Findings sollen entweder die Library besser oder den Audit-Pfad bis zum Bundle (v2.3) absichern. Alles andere ist Lärm.

---

## 2. Repository-Kontext (bereits bekannt)

| Attribut | Wert |
|---|---|
| Repository | https://github.com/ndrstmr/icap-flow |
| Packagist | https://packagist.org/packages/ndrstmr/icap-flow |
| Sprache | PHP **8.4+** (CI-Matrix 8.4 + 8.5) |
| Aktuelle Version | **v3.0.0** (Release: 01.05.2026) |
| v1.0.0 | **deprecated** — RFC-3507-blockierende Bugs + Fail-Open auf Status 100 |
| v2.0.0 | 25.04.2026 — Major-Break, RFC-3507-Wire-Format, Security-Hardening, Multi-Vendor-Header, OPTIONS-Cache, Retry-Decorator, Encapsulated-Aware Framing |
| v2.1.0 | 25.04.2026 — Keep-Alive Connection-Pool + strict RFC 3507 §4.5 Preview-Continue (Same-Socket) |
| v2.1.1 | 28.04.2026 — Hotfix: TLS-Pool-Key-Isolation (Cross-Tenant-Leakage), `DefaultPreviewStrategy` 200/206 → ABORT_INFECTED, `SECURITY.md` aktualisiert |
| v2.1.2 | 28.04.2026 — Strict-§4.5-Streaming-Fix (`stream_get_contents` → Chunked-Encoder), `failOnRisky=true`, PHPStan-Memory-Limit |
| v2.2.0 | 30.04.2026 — OPTIONS-driven Pool-Tuning (`Max-Connections`), `NullConnectionPool`, Pool-Idle-Eviction, ISTag-basierte Cache-Invalidation, PSR-6/PSR-16 OPTIONS-Cache-Adapter, Per-IO-Timeout (statt Session-Lifetime), Mutation-Tests in CI (MSI ≥ 65 %), Coverage-Push, 4 neue Cookbook-Beispiele |
| v3.0.0 | 01.05.2026 — Major-Break, gesammelte BC-Breaks: `IcapClient::executeRaw()` `protected` (war `public`), `options(): Future<IcapResponse>` (war `Future<ScanResult>`), `IcapResponseException` entfernt (Deprecation seit v2.0 eingelöst). Keine neuen Features — reiner Cleanup-Release zur Einfrierung des Bundle-Kontrakts. |
| Lizenz | EUPL-1.2 |
| Async-Stack | `amphp/socket ^2.3` + `revolt/event-loop ^1.0` |
| Logging | `psr/log ^3.0` (optional, NullLogger-Default) |
| Cache (optional) | `psr/cache ^3.0` (PSR-6) und/oder `psr/simple-cache ^3.0` (PSR-16) — beide nur als `suggest`, keine harte Dependency |
| Linting | PHPStan **2.x Level 9 + bleedingEdge** ohne Baseline, PHP-CS-Fixer (PSR-12) |
| Tests | Pest 3 / PHPUnit 11; Stand v3.0.0: **159 passed, 363 assertions** (Unit) |
| Mutation | `composer mutation` (Pest 3 mutate, **jetzt als Required-Job in CI**, MSI-Threshold ≥ 65 %) |
| CI | GitHub Actions (`.github/workflows/ci.yml`): quality-and-tests (8.4 + 8.5), integration (c-icap + ClamAV via docker-compose), deploy-coverage (gh-pages), mutation. Trigger auf `main` und `release/**`. |
| Integration-Tests | gegen `mnemoshare/clamav-icap` (c-icap 0.6.3 + ClamAV) |
| Namespace | `Ndrstmr\Icap` |
| Public Surface (v3.0) | `IcapClientInterface`, `IcapClient` (final, `executeRaw()` jetzt `protected`!), `SynchronousIcapClient`, `RetryingIcapClient`, `Config` (final readonly), `RequestFormatter`/`ResponseParser` (final), `ChunkedBodyEncoder` (final), Exceptions (Marker `IcapExceptionInterface` + 6 konkrete Typen, **`IcapResponseException` entfernt**), DTOs (`HttpRequest`, `HttpResponse`, `IcapRequest`, `IcapResponse`, `ScanResult`), Cache (`OptionsCacheInterface`, `InMemoryOptionsCache`, `Psr6OptionsCache`, `Psr16OptionsCache`), Transport (`TransportInterface`, `SessionAwareTransport`, `TransportSession`, `AsyncAmpTransport`, `AmpTransportSession`, `AmpConnectionPool`, `ConnectionPoolInterface`, `NullConnectionPool`, `ResponseFrameReader`, `SynchronousStreamTransport`) |
| Code-Größe | `src/` ~4 200 LOC, `tests/` ~5 100 LOC (77 PHP-Dateien gesamt) |

### v3.0-BC-Breaks, die jeder Reviewer zuerst verifizieren muss

Die Major-Linie v3.0 kommt **ohne** neue Features. Sie räumt drei Altlasten weg, die das Bundle-Kontrakt-Einfrieren blockierten:

1. **`IcapClient::executeRaw()` ist jetzt `protected`** (PR #85, Item v3-V).
   Die Methode war öffentlich, aber nie Teil von `IcapClientInterface`. Sie umgeht die Fail-Secure-Statuscode-Interpretation und ist nur für den internen Preview-Flow gedacht. Externe Aufrufer müssen `request()` / `scanFile()` / `scanFileWithPreview()` / `options()` nutzen; Subklassen behalten Raw-Access.
   *Verifiziere: `src/IcapClient.php` — Sichtbarkeit `protected`, Phpdoc dokumentiert die Security-Rationale.*

2. **`IcapClient::options()` returned `Future<IcapResponse>`** statt `Future<ScanResult>` (PR #86, Item v3-W).
   OPTIONS ist Capability-Discovery (RFC 3507 §4.10), nicht Virus-Scan. `ScanResult` mit `isInfected()`/`getVirusName()` war hier semantisch falsch. Fail-Secure für 4xx/5xx/100 bleibt — ausgelagert in einen neuen Helper `assertSuccessfulStatus()`, der von `interpretResponse()` (Scans) und `options()` (OPTIONS) gemeinsam genutzt wird.
   *Verifiziere: `IcapClient::options()` returned `IcapResponse`; `SynchronousIcapClient::options()` ebenso; `assertSuccessfulStatus()` als Single-Source-of-Truth für Failure-Branches.*

3. **`Ndrstmr\Icap\Exception\IcapResponseException` ist entfernt** (PR #87, Item v3-F).
   Klasse seit v2.0 deprecated, seit v2.2 mit `#[\Deprecated]`. Beide Throw-Sites (`IcapClient::interpretResponse()` Backstop, `DefaultPreviewStrategy::handlePreviewResponse()` `default`-Branch) werfen jetzt `IcapProtocolException`.
   *Verifiziere: Datei nicht mehr vorhanden; `IcapExceptionInterface`-Marker-Catch-Pfad weiterhin grün; `tests/Exception/ExceptionHierarchyTest.php` ohne `IcapResponseException`-Eintrag.*

### v2.x-Strukturmerkmale, die unverändert in v3 fortbestehen

- **`IcapRequest`**: kein `mixed $body`. Encapsulated-Payload via typisierte Slots `?HttpRequest $encapsulatedRequest`, `?HttpResponse $encapsulatedResponse`, plus `bool $previewIsComplete` (für `0; ieof`).
- **`RequestFormatterInterface::format()` → `iterable<string>`** (Streaming statt One-Shot-String).
- **`TransportInterface::request()`** akzeptiert `iterable<string>` als raw request, plus optionalen `?Amp\Cancellation`.
- **`SessionAwareTransport`** (Subinterface): `openSession(): TransportSession` für Multi-Round-Trip-Flows (strict §4.5 preview-continue, Pipelining).
- **Connection-Pool** über `ConnectionPoolInterface` (LIFO-Stack pro `host:port:tls-fingerprint`-Key seit v2.1.1, `AmpConnectionPool`-Default, `NullConnectionPool` für DI-Defaults seit v2.2, optional in `AsyncAmpTransport(?ConnectionPoolInterface $pool = null)`). **Pool ist OPTIONS-aware**: `tuneFromOptions(IcapResponse)` honouriert `Max-Connections` (RFC 3507 §4.10.2).
- **Pool-Idle-Eviction** seit v2.2: `maxIdleSeconds` evictet zu lange idle Sockets beim nächsten `acquire()`.
- **`ResponseFrameReader`** rahmt ICAP-Responses anhand von Bytes (Encapsulated-Header → `null-body` oder `req-body|res-body=$offset`, dann chunked terminator `0[; ext]\r\n\r\n`). Kein `Connection: close`-Hack mehr.
- **`Config`** ist `final readonly`, mit `withTlsContext()`, `withVirusFoundHeaders()`, `withLimits()`, DoS-Defaults `maxResponseSize=10 MiB`, `maxHeaderCount=100`, `maxHeaderLineLength=8192`.
- **Exception-Taxonomy (v3.0)**: Marker `IcapExceptionInterface`; `IcapProtocolException`, `IcapMalformedResponseException` (extends Protocol), `IcapClientException` (4xx), `IcapServerException` (5xx), `IcapTimeoutException`, `IcapConnectionException`. Insgesamt **6** konkrete Typen (`IcapResponseException` ist gestrichen).
- **Status-Mapping**: `204` clean, `200`/`206` virus-header-Inspektion, `100` außerhalb Preview → `IcapProtocolException` (fail-secure), `4xx` → `IcapClientException`, `5xx` → `IcapServerException`. Für **`options()`** gilt seit v3.0 zusätzlich: 200 OK → raw `IcapResponse`, sonst dieselben Failure-Branches.
- **`RetryingIcapClient`** Decorator: exponential backoff, **nur** auf `IcapServerException` (5xx). Seit v3.0 generisch via `@template T`, weil `withRetry()` jetzt sowohl `ScanResult` (für `request`/`scanFile*`) als auch `IcapResponse` (für `options`) durchreicht.
- **`InMemoryOptionsCache`** + `OptionsCacheInterface`, TTL aus `Options-TTL` (RFC 3507 §4.10.2). Seit v2.2 mit injizierbarem Clock und ISTag-basierter Invalidation (`OptionsCacheInterface::set(string \$key, IcapResponse \$response, int \$ttlSeconds, ?string \$istag): void`).
- **PSR-Cache-Adapter** seit v2.2: `Psr16OptionsCache` (PSR-16 `CacheInterface`) und `Psr6OptionsCache` (PSR-6 `CacheItemPoolInterface`) — beide mit Cross-Process-ISTag-Invalidation via Meta-Keys (`__icap_istag`, `__icap_keys`).
- **`ChunkedBodyEncoder`** (extrahiert aus `RequestFormatter`) — wird vom strict §4.5-Pfad und vom Formatter geteilt. Seit v2.1.2 nutzt der Strict-§4.5-Continuation-Pfad `encodeRemainderFromStream()` statt `stream_get_contents()` (kein RAM-Buffering bei GB-Uploads).
- **Per-IO-Timeout** seit v2.2: `AmpTransportSession` baut für jede I/O-Operation eine frische `TimeoutCancellation` (kombiniert mit der externen `Cancellation`), statt einer Session-Lifetime-Cancellation. Verhindert spurious `CancelledException` bei langsamen, aber legitimen Scans.

Top-Level: `.github/workflows/`, `docs/` (inkl. `migration-v1-to-v2.md`, `migration-v2-to-v3.md`, `compliance.md`, `review/` und `review/review_v2-1/`), `examples/` inkl. `examples/cookbook/` (8 Cookbook-Beispiele in v2.2), `src/`, `tests/` (mit `Wire/`, `Security/`, `Transport/`, `Exception/`, `DTO/`, `Cache/`, `Integration/`), `docker-compose.yml`, `composer.json`, `phpstan.neon`, `phpunit.xml.dist`, `.php-cs-fixer.dist.php`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`, `README.md`, `LICENSE`.

---

## 3. Scope & Abgrenzung

### 3.1 In Scope — das GESAMTE Repository

Du untersuchst **jede Datei**, mit besonderem Fokus auf die in v3.0 BC-kritischen Pfade sowie die in v2.2 neu hinzugekommenen Komponenten:

1. **`src/`** — Vollständige Inspektion. Schwerpunkte:
   - `IcapClient.php` (zentrale Facade — `executeRaw()` jetzt `protected`, `options(): Future<IcapResponse>`, `assertSuccessfulStatus()` als Single-Source-of-Truth für Failure-Branches, strict §4.5 + legacy fallback + status-matrix + logger), `RetryingIcapClient.php` (jetzt generisch `@template T`), `SynchronousIcapClient.php`, `IcapClientInterface.php`.
   - `Transport/AmpConnectionPool.php` (TLS-fingerprint-Pool-Key seit v2.1.1, Idle-Eviction seit v2.2, OPTIONS-driven `tuneFromOptions()`), `Transport/AmpTransportSession.php` (Per-IO-Timeout seit v2.2), `Transport/AsyncAmpTransport.php`, `Transport/ResponseFrameReader.php`, `Transport/SessionAwareTransport.php`, `Transport/TransportSession.php`, `Transport/SynchronousStreamTransport.php`, `Transport/ConnectionPoolInterface.php`, `Transport/NullConnectionPool.php` (neu in v2.2), `Transport/TransportInterface.php` — Connection-Lifecycle, Pool-Korrektheit, Framing-Robustheit, Per-IO-Timeout-Semantik.
   - `RequestFormatter.php`, `ChunkedBodyEncoder.php` (mit `encodeRemainderFromStream()` seit v2.1.2), `ResponseParser.php` — Wire-Format-Korrektheit, Header-Folding (RFC 7230 §3.2.4), Encapsulated-Offset-Berechnung.
   - `Cache/InMemoryOptionsCache.php` (injizierbarer Clock + ISTag-Invalidation seit v2.2), `Cache/OptionsCacheInterface.php` (Signatur erweitert um `?string $istag`), `Cache/Psr6OptionsCache.php`, `Cache/Psr16OptionsCache.php` (beide neu in v2.2 — Cross-Process-Invalidation via Meta-Keys) — TTL-Semantik, Cache-Key-Konstruktion, Cross-Process-Implikationen.
   - `DefaultPreviewStrategy.php` (200/206 → `ABORT_INFECTED`/`ABORT_CLEAN` seit v2.1.1, default-Branch jetzt `IcapProtocolException`), `PreviewStrategyInterface.php`, `PreviewDecision.php`.
   - `Exception/*` — vollständige Hierarchie + `IcapExceptionInterface`-Marker. **Verifiziere, dass `IcapResponseException.php` nicht mehr existiert** (v3-F). Insgesamt 6 konkrete Typen + Marker.
   - `DTO/*` — `final readonly`, Immutability, `mixed` body in `HttpRequest`/`HttpResponse` (string|resource|null).
   - `Config.php` — `final readonly`, alle `with…()`-Wither, Validation-Boundary, TLS-Fingerprint-Helper.
2. **`tests/`** — Test-Abdeckung & -Qualität. Schwerpunkte: `Wire/RequestFormatterWireTest.php`, `Wire/ResponseParserWireTest.php` (hand-computed RFC-3507-Bytes), `Wire/ChunkedBodyEncoderTest.php`, `Security/FailSecureAndValidationTest.php` (jetzt mit `IcapRequest`-basierten Virus-Header-Tests statt `options()`-Trick), `Security/ParserDosLimitsTest.php`, `Transport/AmpConnectionPoolTest.php` (TLS-Isolation, Idle-Eviction), `Transport/ResponseFrameReaderTest.php`, `Transport/AsyncAmpTransportTest.php`, `Transport/PerIoTimeoutTest.php` (neu in v2.2 — Socket-Pair-Test mit gestaffelten Server-Delays), `PreviewContinueStrictTest.php`, `OptionsCacheTest.php` (ISTag-Invalidation, Cache-Hit-Counter, TTL=0 No-Cache), `Cache/Psr6OptionsCacheTest.php` und `Cache/Psr16OptionsCacheTest.php` (je 6 Test-Cases inkl. Cross-Process-ISTag-Flush), `RetryingIcapClientTest.php`, `CancellationTest.php`, `MultiVendorVirusHeadersTest.php`, `LoggerIntegrationTest.php` (sensitive-Header-Regression-Guard), `CustomRequestHeadersTest.php`, `SynchronousIcapClientTest.php`, `Exception/ExceptionHierarchyTest.php` (ohne `IcapResponseException`), `Integration/IcapServerSmokeTest.php`.
3. **`examples/` inkl. `examples/cookbook/`** — Realitätsnähe, Vollständigkeit, Didaktik. Cookbook in v2.2 erweitert auf 8 Beispiele (`01-custom-headers.php`, `02-custom-preview-strategy.php`, `03-options-request.php`, `04-tls-mtls.php`, `05-retry-decorator.php`, `06-pool-tuning.php`, `07-cancellation-from-upload.php`). Werden Connection-Pool, Retry-Decorator, OPTIONS-Cache (inkl. PSR-Adapter), TLS/mTLS, externes `Cancellation`, Custom-Header, OPTIONS-driven Pool-Tuning ausreichend demonstriert? **Prüfe ob die v3-API-Änderungen (`options()` returned `IcapResponse`) in den Cookbooks korrekt reflektiert sind** — speziell `03-options-request.php` und `06-pool-tuning.php`.
4. **`.github/workflows/ci.yml`** — Matrix (8.4 + 8.5), `composer audit`, PHPStan, CS-Fixer, Coverage-Upload, Integration-Job mit c-icap-Readiness-Probe, `roave/security-advisories`, **Mutation-Job (Required, MSI ≥ 65 % seit v2.2)**, Trigger auf `main` und `release/**`. Was fehlt? Stale Skip-/Continue-on-Error-Settings?
5. **`docs/`** — `migration-v1-to-v2.md`, `migration-v2-to-v3.md`, `compliance.md` (BSI/DSGVO/EUPL-Mapping seit v2.2), `review/*` (mit `review_v2-1/*` als drittem Audit-Set). Vollständigkeit einer öffentlichen API-Doku (phpDocumentor/Doctum) — vorhanden? Architecture Decision Records?
6. **Root-Konfiguration** — `composer.json` (deps, autoload, scripts, platform-pin auf 8.4.0, `suggest`-Block für PSR-Cache), `phpstan.neon` (Level 9 + bleedingEdge, gezielte ignoreErrors für Pest-Internals — sauber begründet?), `phpunit.xml.dist` (`beStrictAboutCoverageMetadata=true`, `failOnRisky=true` seit v2.1.2, separate Integration-Suite), `docker-compose.yml`, `CHANGELOG.md` (Keep-a-Changelog-Disziplin, v3.0.0-Stempel), `CONTRIBUTING.md`, `SECURITY.md`, `LICENSE` (EUPL-1.2).
7. **Git-Historie & Issues/PRs** — Commit-Qualität (Conventional Commits, **English-only**), Release-Kadenz v1 → v2.0 → v2.1 → v2.1.1 → v2.1.2 → v2.2 → v3.0, offene Issues, Security-Advisories, BC-Promise-Disziplin innerhalb der v3-Linie. Insbesondere: hat der `release/v3.0`-Integration-Branch sauber gearbeitet (drei isolierte BC-Break-PRs + Release-PR)?

### 3.2 Out of Scope

- ICAP-Server-Implementierungen (außer als Vergleich/Testing-Target).
- Generische PHP-Einführung.
- Reine v1-Findings, die v2 sauber geschlossen hat — **außer du findest sie in v3.0 wieder offen** (Regressionen).
- Reine v2.1-Findings (A–N + M3-Follow-ups), die in v2.1.x/v2.2 geschlossen wurden — **außer du findest sie in v3.0 wieder offen** (Regressionen). Die Liste ist in `docs/review/review_v2-1/consolidated_v2.1_task-list.md` mit `[x]`-Status dokumentiert; verifiziere stichprobenartig am Code.
- Das geplante separate Repository `ndrstmr/icap-flow-bundle` (Symfony-Bundle, v2.3-Roadmap-Item). Dieses Audit bewertet **nur** die Library; ein eventuelles Bundle wird separat reviewt. Bewertung der Bundle-Readiness (DI-Kompatibilität, Service-Definitions-Eignung) bleibt jedoch in Scope.

---

## 4. Analyse-Phasen

Sieben aufeinander aufbauende Phasen. Dokumentiere nach jeder Phase Zwischenergebnisse, bevor du zur nächsten wechselst.

### Phase 1 — Repository-Inventar & v2.1→v3.0-Diff

**Output:**
- Vollständige Dateiliste mit LOC `src/` und `tests/`. (Erwartet ~4.2k / ~5.1k LOC, ~77 PHP-Dateien.)
- Test-zu-Code-Ratio (inkl. der seit v2.2 hinzugekommenen PSR-Cache- und Per-IO-Timeout-Tests).
- Klassen-/Interface-/Enum-Diagramm (Mermaid) inkl. Abhängigkeiten — markiere v1-bestehend / v2.0-neu / v2.1-neu / v2.2-neu / **v3.0-removed** (`IcapResponseException`).
- Dependency-Graph aus `composer.json` (runtime + dev + suggest) inkl. transitiver Pfade aus `composer.lock` für sicherheitsrelevante Pakete (`amphp/socket`, `revolt/event-loop`, `psr/log`, `psr/cache`, `psr/simple-cache`, `roave/security-advisories`).
- Öffentliche API-Oberfläche (jede `public`-Methode auf `final` Klassen + Interfaces) → Tabelle für SemVer-Kontrolle. **Markiere die drei v3.0-BC-Breaks explizit** (`executeRaw()` `protected`, `options(): Future<IcapResponse>`, `IcapResponseException` removed).
- **v3.0-BC-Break-Verifikation**: gehe die drei v3-Items aus [`review_v2-1/consolidated_v2.1_task-list.md`](review_v2-1/consolidated_v2.1_task-list.md) (v3-V, v3-W, v3-F) einzeln durch. Pro Item: Status `verifiziert geschlossen / teilweise / Regression`, Code-Ref, Begründung.
- **v2.1→v2.2-Closure-Verifikation**: Die in der `consolidated_v2.1_task-list.md` als `[x]` markierten Items aus den Milestones v2.1.1, v2.1.2 und v2.2.0 — Stichproben pro Reviewer-Konsens-Achse (4/4 / 3/4 / 2/4) belegen.
- **Wunschliste-Check**: Die in `consolidated_v2.1_task-list.md` als „Someday / Maybe" markierten Items (W-Y OpenTelemetry, W-Z PHPBench, W-sbom SBOM-Workflow) — ist die Verschiebung gerechtfertigt, oder ist mindestens eines davon inzwischen produktionskritisch geworden?

### Phase 2 — Code- & Architektur-Analyse

#### 2.1 Sprachmoderne & Typsystem (PHP 8.4 / 8.5-Niveau)
- `readonly` Klassen (`Config`, DTOs)? Konstruktor-Property-Promotion durchgängig?
- PHP 8.4-Features tatsächlich genutzt? (Property hooks, asymmetric visibility, `#[\Deprecated]`, `array_find`, `new MyClass()->method()` ohne Klammern, Lazy Objects, neue `array_*`-Funktionen)
- PHP 8.5-Kompatibilität in CI-Matrix bestätigt? Wo nutzt der Code 8.5-spezifische Verbesserungen, falls überhaupt?
- `#[\Override]`-Disziplin auf Interface-Implementierungen?
- `@template T` auf `RetryingIcapClient::withRetry()` (neu in v3.0) — sauber? Wird der Generic in beiden Aufrufkontexten (`ScanResult` für `request`/`scanFile*`, `IcapResponse` für `options`) korrekt aufgelöst?
- `mixed` nur dort, wo PHP es nicht besser kann (`HttpRequest::$body: string|resource|null`)? Sind Generics-Phpdocs (`@template`, `@param-out`, `@phpstan-impure`) gepflegt?
- PHPStan **Level 9 + bleedingEdge** ohne Baseline — verifiziere durch lokalen Lauf, finde Schwachstellen die `ignoreErrors` möglicherweise versteckt.
- Strikte Vergleiche, `declare(strict_types=1)` durchgängig (alle Source-Files haben EUPL-Header + `declare`).
- **`#[\Deprecated]`-Disziplin** — gibt es noch Klassen oder Methoden mit `@deprecated`-Phpdoc, aber ohne `#[\Deprecated]`-Attribut? `IcapResponseException` ist gestrichen — bleibt etwas in der Sondergruppe?

#### 2.2 Design, Pattern & SOLID
- SRP der zentralen Bausteine: `ResponseFrameReader` (Framing), `AmpTransportSession` (Lifecycle + Per-IO-Timeout), `AmpConnectionPool` (Pool + Idle-Eviction + OPTIONS-driven Tuning), `ChunkedBodyEncoder` (Encoding) — sind die Schnitte sauber?
- **Decorator-Pattern**: `RetryingIcapClient` als Wrapper um `IcapClientInterface` — clean implementiert? Generisch via `@template T` (v3.0)? (Idempotenz beachtet? Retry auf nicht-idempotenten Operationen ein Risiko?)
- **Strategy**: `PreviewStrategyInterface` + `DefaultPreviewStrategy` — entkoppelt von Statuscode-Mapping? `default`-Branch wirft seit v3.0 `IcapProtocolException` — sauber?
- **Pool-Pattern**: LIFO-Stack pro `host:port:tls-fingerprint`-Key, Idle-Eviction (v2.2), OPTIONS-driven `tuneFromOptions()` (v2.2), `Connection: close`-Honor-Pfad — bewerte gegen verbreitete HTTP-Connection-Pool-Implementierungen (Guzzle, Symfony HttpClient, Java OkHttp).
- DTO-Design: `final readonly` (Config, DTOs)? Wither-Pattern statt Setter? `__construct`-Validation am System-Boundary?
- Hexagonal/Clean Architecture: Ist der Schnitt `Transport ↔ Formatter/Parser ↔ Client ↔ Decorator ↔ Cache` rein, oder gibt es Lecks?
- **v3.0-spezifisch**: ist die Extraktion `assertSuccessfulStatus()` aus `interpretResponse()` ein sauberes SRP-Pattern, oder eine künstliche Trennung? Hat der Backstop-Throw in `interpretResponse()` nach der Extraktion noch eine sinnvolle Existenzberechtigung (Stichwort: 1xx-other-than-100, 3xx, 6xx+)?
- **v3.0-spezifisch**: ist die `protected`-Visibility auf `executeRaw()` ausreichend, oder sollte die Methode in ein dediziertes `IcapRawClient`-Sub-Interface gehoben werden, das Subklassen explizit implementieren? Tradeoff: Ergonomie vs. Audit-Spur.

#### 2.3 PSR-Compliance
- **PSR-3** Logger ✓ (optional, NullLogger-Default, drei strukturierte Events `started/completed/failed`). Bewerte: kein PII im Log? Loglevel sinnvoll? `LoggerIntegrationTest::sensitive headers do not leak into log context` als Regression-Guard ausreichend?
- **PSR-4** Autoload sauber? Alle DTOs unter `Ndrstmr\Icap\DTO\…`, alle Exceptions unter `Ndrstmr\Icap\Exception\…`, Cache-Adapter unter `Ndrstmr\Icap\Cache\…`.
- **PSR-6 (`CacheItemPoolInterface`)**: `Psr6OptionsCache` (v2.2) — bewerte Cross-Process-ISTag-Invalidation via Meta-Keys (`__icap_istag`, `__icap_keys`). Race-Conditions zwischen `getItem()`/`save()` in echten Backends (Redis-Cluster, Memcached)? Default-TTL-Behavior wenn `Options-TTL=0`?
- **PSR-16 (`CacheInterface`)**: `Psr16OptionsCache` (v2.2) — analog. Bewerte den Designtradeoff: Meta-Key-basierte Invalidation vs. Redis-Tagging, was würde ein erfahrener Symfony-Cache-Nutzer erwarten?
- **PSR-7/PSR-17** bewusst nicht für ICAP-Body genutzt (Streaming via `iterable<string>` + native PHP-Resource). Beurteile diese Designentscheidung pro/contra.
- **PSR-11** kein direkter Container — sauber, da framework-agnostisch.
- **PSR-18** Inspirationsquelle? `IcapClientInterface` kommt nahe.
- **PSR-20 (Clock)**: `InMemoryOptionsCache` nutzt seit v2.2 einen injizierbaren `(Closure(): int)|null $clock` Closure statt `clockOffsetSeconds`-Test-Seam. Bewerte: warum keine echte `Psr\Clock\ClockInterface`-Injection? Tradeoff (Closure vs. Interface) sauber begründet?
- **`suggest`-Block** in `composer.json` für `psr/cache` und `psr/simple-cache` — bewerte: ist die "soft dependency" der richtige Weg, oder sollten die Adapter in ein eigenes `ndrstmr/icap-flow-cache`-Paket ausgelagert werden?

#### 2.4 Fehlerbehandlung & Exception-Design
- Hierarchie vollständig? `IcapExceptionInterface` als Marker auf jedem konkreten Typ?
- **v3.0-Spezifika**: `IcapResponseException` ist entfernt — verifiziere, dass kein Code-Pfad mehr darauf referenziert. Beide Throw-Sites (Backstop in `IcapClient::interpretResponse()`, `default`-Branch in `DefaultPreviewStrategy::handlePreviewResponse()`) werfen jetzt `IcapProtocolException`. Bewerte: ist `IcapProtocolException` der semantisch richtige Catch-All, oder hätte ein dedizierter `IcapUnexpectedStatusException` mehr Diagnostik-Wert? Für Catch-Block-Konsumenten (insb. `RetryingIcapClient`, der nur 5xx retried) ist die Frage entscheidend.
- Recoverable vs. non-recoverable klar getrennt? `IcapServerException` recoverable (= retry-bar), Rest nicht — verifiziere am Code von `RetryingIcapClient`.
- Exception-Chaining (`previous`) auf TLS-/Connect-Fehlern (`IcapConnectionException`)?
- Sprechende Messages **ohne** sensible-Daten-Leakage (z.B. kein Body im Exception-Text)?
- `LogicException` für invalid Session-Lifecycle (`AmpTransportSession::assertActive()`) — angemessen?
- **`IcapProtocolException` als neue Spitzklasse**: durch das Entfernen von `IcapResponseException` trägt `IcapProtocolException` jetzt einen breiteren Bedeutungsbereich (RFC-Verletzungen + nicht-zuordenbare Status-Codes + Backstop). Ist die Phpdoc/Class-Doku entsprechend angepasst?

#### 2.5 Ressourcen-Management & Connection-Handling
- Sockets schließen: `AmpConnectionPool::close()` (idempotent? thread-safe genug für Fiber-Kontext?), `AmpTransportSession::release()` vs. `close()` (disposed-Flag, doppelter Release ist no-op).
- **Pool-Korrektheit**: was passiert bei Exception **zwischen** `acquire()` und `write()`? Was, wenn `write()` selbst wirft? Was bei `Cancellation`-trigger mitten im `readResponse()`? — In `AsyncAmpTransport::request()` wird `closeForced=true` bei jedem `Throwable` gesetzt, auch bei `CancelledException` — Socket wird geschlossen statt zurückgegeben. Bewerte ob das die richtige Default-Policy ist (vermutlich ja).
- **Pool-Idle-Eviction (v2.2)**: `maxIdleSeconds`-Default von 30 s. Wird der Idle-Timestamp **bei Release** gesetzt und bei **Acquire** geprüft? Race-Condition zwischen Set-Time und Read-Time? Ist die Eviction lazy (beim nächsten `acquire()`) oder kommt sie mit einem Hintergrund-Task? Memory-Footprint bei langsam-driftenden Workloads?
- **Pool-Tuning aus OPTIONS (v2.2)**: `tuneFromOptions(IcapResponse $response)` extrahiert `Max-Connections` und cap't den Pool. Was passiert, wenn der Server seinen Wert dynamisch ändert (Reload), aber der Pool die alte Cap behält? Ist das ein Caller-Problem oder ein Library-Bug? Wie integriert sich das mit Cache-getriebenen `options()`-Antworten (cached vs. live)?
- **Per-IO-Timeout (v2.2)**: `AmpTransportSession::makeIoCancellation()` baut für jede Read/Write-Operation eine frische `TimeoutCancellation` (kombiniert via `CompositeCancellation` mit der externen `Cancellation`). Bewerte gegen das frühere Session-Lifetime-Modell: keine spurious `CancelledException` mehr bei Strict-§4.5-Continuations, dafür theoretisch unbegrenzte Gesamt-Operations-Zeit, wenn jede I/O knapp unter dem Timeout bleibt. Sollte ein zusätzlicher harter Session-Cap existieren (analog zu `RequestTimeout` in HTTP-Clients)?
- **Half-closed connections**: wenn ein Server `Connection: close` setzt, schließt der Transport. Was, wenn der Server den Socket einseitig zumacht **ohne** Header? Detection im nächsten `acquire()` via `isClosed()` — reicht das?
- **Race-Condition zwischen `isClosed()` und `read()`**: Klassisches TOCTOU bei jedem Connection-Pool. Wie mitigiert?
- Backpressure beim Streaming großer Encapsulated-Bodies (Multi-GB) durch chunked encoder? `fread()` in 8 KiB / amphp default chunks — verifiziere.
- Memory-Footprint: Verifiziere, dass `scanFile()` und `scanFileWithPreviewStrict()` **nie** den ganzen File-Body buffern (seit v2.1.2 nutzt der Strict-§4.5-Continuation-Pfad `ChunkedBodyEncoder::encodeRemainderFromStream()` statt `stream_get_contents()`). (Achtung: `scanFileWithPreviewLegacy` macht `rewind($stream)` + komplette Re-Sendung — bewerte das.)
- Timeouts granular (`socketTimeout` Connect / `streamTimeout` Read-Write) — durchgängig respektiert? Per-IO-Timeout-Implementation korrekt mit beiden?
- **§4.5 Strict-Pfad**: Im strict path wird der `100 Continue` Body-only fortgesetzt. Was passiert, wenn das Server-eigene `100 Continue` z.B. `ICAP/1.0 100 Continue\r\n\r\n` ist und nicht `ICAP/1.1`? Akzeptiert der Parser das? (`ResponseParser` matcht `ICAP/1\.\d` — sollte gehen.)

#### 2.6 Async-Implementierung
- Sauberer Einsatz von `Amp\async()`, `Amp\Future`, `Amp\Cancellation`, `CompositeCancellation`, `TimeoutCancellation`.
- Cancellation durchgereicht von `IcapClient` → `Transport` → `Session` → `socket->read($cancellation)`?
- **Per-IO-Timeout-Composition (v2.2)**: `AmpTransportSession::makeIoCancellation()` kombiniert `TimeoutCancellation` mit `userCancellation` via `CompositeCancellation`. Bewerte: ist der Code defensiv genug gegen den Fall, dass die externe Cancellation bereits gefeuert hat, bevor ein I/O startet? `CompositeCancellation`-Wiederverwendung über mehrere I/Os einer Session?
- Fiber-Sicherheit: Pool ist **nicht** explizit synchronisiert. Im PHP-Single-Thread-Fiber-Modell ist das ok, **außer** zwei Fibers `acquire()`-en gleichzeitig dieselbe Idle-Liste während eines `array_pop()`. Verifiziere (in PHP sind Single-Statement-Operationen atomar bzgl. Fiber-Switching, aber nicht offensichtlich).
- Interaktion mit **Symfony 7.4 Fiber-Support** (HttpClient, etc.) — Library-API verträgt sich? Aufruf aus einem Symfony-Controller-Context (kein expliziter Event-Loop) via `SynchronousIcapClient`?
- Interaktion mit **RoadRunner / Swoole / ReactPHP** Worker-Modi — wird der Pool sauber zwischen Requests wiederverwendet, ohne Cross-Request-Datenleck?
- `RetryingIcapClient::$sleeper` Default ist `Amp\delay()` — blockiert das im sync-Fall (`SynchronousIcapClient`), oder gibt es Probleme?

### Phase 3 — ICAP-Protokoll-Compliance (RFC 3507) — verifizieren am v2.1-Code

#### 3.1 Methoden
- **OPTIONS** — Capability Discovery: `Methods`, `ISTag`, `Max-Connections`, `Options-TTL`, `Preview`, `Transfer-Preview`, `Transfer-Ignore`, `Transfer-Complete`. Verifiziere:
  - `Options-TTL` wird in Cache verwendet (RFC 3507 §4.10.2). Auch wenn die Cache-Adapter PSR-6/16 sind (Cross-Process)?
  - **`Max-Connections`** wird seit v2.2 via `AmpConnectionPool::tuneFromOptions()` ausgelesen und an den Pool propagiert. Test-Coverage ausreichend?
  - **`Preview`** wird seit v2.2 OPTIONS-driven gelesen (`IcapClient::resolvePreviewSize()`) wenn der Caller keinen expliziten Wert übergibt. Verifiziere und prüfe Edge-Case "OPTIONS-Cache leer + kein Caller-Wert".
  - **ISTag** wird seit v2.2 für Cache-Invalidation genutzt (`OptionsCacheInterface::set(..., ?string $istag)`). Bei Server-Reload mit neuer Signatur (ISTag-Change) — werden alle gecachten OPTIONS-Antworten korrekt invalidiert?
  - **v3.0-Spezifikum**: `options()` returned jetzt `IcapResponse` (statt `ScanResult`-Wrapper). Reviewer überprüfen: gibt es noch externen Code in der Library oder den Tests, der `->isInfected()` / `->getVirusName()` auf einer OPTIONS-Antwort aufruft?
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
- Preview-Size-Aushandlung via OPTIONS `Preview`-Header — wird seit v2.2 aus dem OPTIONS-Cache gelesen, falls der Caller keinen expliziten Wert übergibt (`IcapClient::resolvePreviewSize()`). Verifiziere am Code, prüfe Edge-Cases (leerer Cache, fehlender `Preview`-Header, malformed Preview-Wert).
- `Allow: 204` immer gesetzt im Preview-Pfad ✓.
- `100 Continue` wird durch `PreviewStrategyInterface::handlePreviewResponse()` interpretiert (legacy + strict). Verifiziere: nur strict-path nutzt denselben Socket; legacy-path baut neue Verbindung mit Vollkörper auf (zweiter TCP/TLS-Handshake — bewerte als Sub-Optimum für Sync-Fallback).
- Strict-§4.5: Phase 1 = `RESPMOD` mit Preview-Bytes, Phase 2 = nur Body-Remainder als Chunk-Stream. **Verifiziere mit `tests/PreviewContinueStrictTest.php`** dass die Connector-Anzahl exakt 1 ist (das ist der RFC-konforme State).
- **Strict-§4.5-Streaming-Regression-Check (v2.1.2)**: der Body-Remainder wird seit v2.1.2 via `ChunkedBodyEncoder::encodeRemainderFromStream()` gestreamt, **nicht** via `stream_get_contents()` (der den ganzen Stream in RAM gepuffert hätte — OOM-Risiko bei GB-Uploads). Verifiziere am Code von `IcapClient::scanFileWithPreviewStrict()`.
- **DefaultPreviewStrategy 200/206-Pfad (v2.1.1)**: bei Virus-Header → `ABORT_INFECTED`, sonst `ABORT_CLEAN`. Test in `DefaultPreviewStrategyTest.php` vorhanden? Vendor-Header-Reihenfolge respektiert?

#### 3.4 Statuscodes (v3.0-Matrix)
Vollständige Matrix in `IcapClient::interpretResponse()` (Scans) + `assertSuccessfulStatus()` (Failure-Branches, von `interpretResponse()` und `options()` geteilt):
- `100` außerhalb Preview → `IcapProtocolException` ✓ (fail-secure, v2-Finding G).
- `204` → `ScanResult(clean)` ✓ (Scans) / Cache-Hit für `options()` legitim, aber laut RFC unüblich.
- `200` / `206` → Scans: Virus-Header-Inspektion über `getVirusFoundHeaders()`. OPTIONS: raw `IcapResponse` (v3.0).
- `4xx` → `IcapClientException(code)` ✓.
- `5xx` → `IcapServerException(code)` ✓ (retry-bar).
- **Andere (1xx-other-than-100, 3xx, 6xx+) → `IcapProtocolException`** (war `IcapResponseException` bis v3.0; v3-F).

Prüfe Edge-Cases: `408 Request Timeout`, `503 Service Unavailable` (häufigster transienter Fehler — Retry-Defaults passen?), `505 ICAP Version Not Supported`.

**v3.0-spezifische Verifikation**: testet die Test-Suite explizit, dass `options()` bei 4xx/5xx/100 dieselben Exceptions wirft wie `request()`? Ist die Single-Source-of-Truth in `assertSuccessfulStatus()` über beide Pfade abgedeckt?

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

#### 4.1 Security Posture v3.0 (inkl. OWASP Top 10 2021/2025-Mapping)
- **Fail-secure-Verhalten**: jeder Pfad, in dem ein Fehler "weich" als Clean enden könnte, muss durch Test belegt sein. Prüfe `tests/Security/FailSecureAndValidationTest.php` auf Vollständigkeit der Status-Matrix-Coverage (`100`, `4xx`, `5xx`, malformed-response, oversized-response, slow-loris-Header). **v3.0-spezifisch**: deckt der Test sowohl den `request()`- als auch den `options()`-Pfad ab? Ist die Single-Source-of-Truth `assertSuccessfulStatus()` mutation-test-belegt?
- **CRLF-Guard auf `$service` und Headern** ✓. Verifiziere Multi-Value-Header-Validation: `IcapClient::validateIcapHeaders()` Z. ~600-613 iteriert via `foreach ((array) $value as $v)` — d.h. `['X-Foo' => ['line1', 'line2']]` wird Index-by-Index validiert. Jules' Befund "Header-Array-Werte werden nicht tief validiert" war faktisch falsch (so dokumentiert in `consolidated_v2.1_task-list.md`). Verifiziere am Code, prüfe Edge-Case `['X-Foo' => 'mit\r\nNewline']` (String-Wert mit CRLF).
- **TLS / `icaps://`** via `ClientTlsContext`. Bewerte: Default-Cipher-Policy von amphp v3 secure? Hostname-Verification per Default an? **Pool-Key-Isolation seit v2.1.1**: enthält jetzt einen TLS-Context-Fingerprint (Peer-Name + Cert-Path + CA-File via SHA-256), so dass zwei Configs mit unterschiedlichem TLS-Setup nie denselben Pool-Bucket teilen. Verifiziere am Code (`AmpConnectionPool::key()`) und prüfe `tests/Transport/AmpConnectionPoolTest.php` auf Cross-TLS-Isolation-Test.
- **Credentials in Logs**: `LoggerIntegrationTest.php` deckt 3 Events ab + sensitive-Header-Regression-Guard (v2.2). Prüfe, dass keine Header-Inhalte (insbesondere `X-Authenticated-User`) in das Log-Context geschrieben werden — heute werden nur `method/uri/host/port/statusCode/infected` geloggt, keine Header. **v3.0-spezifisch**: Logging-Context bei `options()` enthält jetzt `infected` nicht mehr (semantisch falsch für OPTIONS). Verifiziere die Log-Context-Schema-Konsistenz.
- **Dependency-Security (OWASP A06:2021 — Vulnerable and Outdated Components)**: `composer audit` läuft in CI ✓. `roave/security-advisories: dev-latest` als dev-dep ✓. Lizenz-Compatibility-Check (alle deps EUPL-1.2-kompatibel)? Bewerte ob ein automatisierter SBOM-Export (CycloneDX, SPDX) nötig ist (Wunschliste-Item W-sbom — heute auf "Someday" verschoben).
- **PSR-Cache-Adapter Cross-Process-Risiken (v2.2)**:
  - **Cache-Poisoning**: kann ein Angreifer mit Schreibzugriff auf das Backing-Store (Redis-Cluster, Memcached) eine vergiftete OPTIONS-Antwort einschleusen, die der Library nachgelegt wird? Mitigationen: ISTag-basierte Invalidation, TTL aus `Options-TTL`. Reicht das?
  - **ISTag-Race**: zwischen `set()` (mit neuem ISTag) und der Meta-Key-Aktualisierung (`__icap_istag`) — wenn ein Worker B im Window dazwischen einen `get()` macht, sieht er die alte ISTag-Listenvariable, aber den neuen Wert? Atomicity-Garantien des Backing-Stores spielen hier rein.
  - **Key-Tracking-Footprint**: `__icap_keys` wächst linear mit der Anzahl gecachter Services. Bei Multi-Tenant-Deployments mit dynamischen Service-Pfaden — wann ist Pruning angebracht? Heute: nie aktiv. Bewerte als potenzielles P2-Issue.
- **SSRF (OWASP A10:2021)**: `Config::host` kommt vom Anwender (Library-User, nicht End-User). Wenn ein Web-Portal `host` aus Konfiguration zieht — kein direktes SSRF. Aber wenn jemand `host` aus User-Input zieht (eher Anti-Pattern, in `SECURITY.md` dokumentieren?).
- **Injection (OWASP A03:2021)**: ICAP-Header-Injection wird bereits an `validateIcapHeaders()`/`validateServicePath()` blockiert. URL-Encoding-Tricks (`%0d%0a`)? Heute nicht decodiert — bewerte ob ein zusätzlicher Decode-Pass nötig wäre (vermutlich nicht, da ICAP-URI's nicht URL-decoded werden).
- **Insecure Design (OWASP A04:2021)**: ist die Default-Konfiguration sicher? `Config::__construct` ohne TLS — sollte das eine `LogicException` werfen, wenn `host` nicht `localhost`/`127.0.0.1` ist? Heutige Praxis: ICAP läuft typisch auf interner Host-Verbindung, TLS optional. Bewerte.
- **Cryptographic Failures (OWASP A02:2021)**: TLS-Defaults aus amphp v3 — sind explizite Cipher-Policies im Cookbook dokumentiert? `ClientTlsContext`-mTLS-Setup im Cookbook (`04-tls-mtls.php`) — production-tauglich?

#### 4.2 Öffentliche Verwaltung & Compliance
- **EUPL-1.2** ✓ (Header in jeder Source-Datei verifiziert? Stichproben in `src/` und `tests/`).
- **OpenCoDE-Kompatibilität**: License + EU-Public-Sector-Tauglichkeit ✓. Ist das Repo auf OpenCoDE gespiegelt oder wird es?
- **BSI IT-Grundschutz**: Relevante Bausteine sind CON.6 Löschen und Vernichten / OPS.1.1.4 Schutz vor Schadprogrammen / APP.4.4 Webanwendungen. Seit v2.2 dokumentiert in `docs/compliance.md` (Mapping OPS.1.1.4, APP.4.4, DSGVO Art. 32, EUPL-1.2). Bewerte Tiefe und Aussagekraft des Mappings: hilft es einem ISO 27001-Auditor, oder ist es nur Marketing-Material? Fehlen weitere Bausteine (CON.6 Löschen, NET.3.2 Firewall)?
- **Digitale Souveränität**: Alle deps Open Source, EU-/community-gehostet. Keine SaaS-Abhängigkeiten ✓.
- **GDPR/DSGVO Art. 32**: Was geht in `Logger` rein? `LoggerInterface` ist optional und plug-bar — DSGVO-Verantwortung beim Caller. Dokumentiert in `docs/compliance.md`. Reicht der Hinweis, oder müsste die Library eine `LogScrubberInterface` o. ä. anbieten?
- **AI-assisted origin disclaimer** im README ✓ — bewerte Tonalität: ehrlich? hinreichend warnend für Security-kritischen Einsatz? **Mit v3.0 als reinem Cleanup-Release** ist die Reife der Library jetzt höher — passt der Disclaimer-Ton zur tatsächlichen Maturity (drei unabhängige Audits + 159 grüne Tests + Mutation-Score ≥ 65 %)?

### Phase 5 — Testing & Qualitätssicherung

- **Unit-Test-Coverage** lines/branches/methods. Erwartet ≥ 90% (Pest Coverage HTML in `build/coverage-html/`). Verifiziere lokal oder per gh-pages-Report. Welche Pfade sind UN-covered? Coverage-Hotspot-Push in v2.2 war auf `AmpConnectionPool` (54 → ≥ 90 %), `SynchronousStreamTransport` (41 → ≥ 85 %) — verifiziere, ob die Ziele tatsächlich erreicht sind.
- **Mutation Testing**: `composer mutation` (Pest 3 mutate) **seit v2.2 als Required-Job in CI** mit MSI-Threshold ≥ 65 %. Bewerte: reicht 65 % oder sollte für eine Security-kritische Library mindestens 75 % gefordert werden? Welche Mutanten überleben (Stichproben aus dem letzten Mutation-Report)? Speziell der neue v3-Code (`assertSuccessfulStatus()` Helper, `options()` Pfad) — Mutation-Score?
- **Integration-Tests**: `tests/Integration/IcapServerSmokeTest.php` gegen `mnemoshare/clamav-icap`. Skip-by-default (`ICAP_HOST` env). CI startet docker-compose + readiness-Probe — robust gegen ClamAV-Bootstrap-Latenz. Bewerte ob `continue-on-error: true` in CI verhüllt, dass die Integration intermittierend rot ist (Item v2.2-#17 in der konsolidierten Liste — Status?).
- **Per-IO-Timeout-Test (v2.2)**: `tests/Transport/PerIoTimeoutTest.php` nutzt Socket-Pair mit gestaffelten Server-Delays (zwei × 1.5 s, gesamt 3 s > 2 s session timeout). Reproduziert das Test wirklich den Bug, gegen den der Fix gerichtet war? Würde der Test unter dem alten Session-Lifetime-Modell zuverlässig rot sein? Determinismus?
- **PSR-Cache-Adapter-Tests (v2.2)**: `Cache/Psr6OptionsCacheTest.php`, `Cache/Psr16OptionsCacheTest.php` — je 6 Test-Cases inkl. Cross-Process-ISTag-Flush. Bewerte: testet das wirklich Cross-Process-Verhalten oder nur In-Memory-Test-Doubles? Würde ein echtes Redis-Backend andere Timing-Probleme aufdecken?
- **Property-Based Testing** (Eris, phpunit-generator): nicht vorhanden. Würde sich für `ResponseParser` / `ResponseFrameReader` rentieren? P2.
- **Benchmark-Tests** (phpbench): aktuell nicht vorhanden — auf Wunschliste W-Z verschoben. Bewerte ob das gerechtfertigt ist (Argumente für die Verschiebung: kein bekanntes Performance-Problem, geringe Release-Frequenz).
- **Fuzzing** für Parser: nicht vorhanden. Mit Symfony's `php-fuzzer` oder einfaches AFL-style-Setup? P2/P3.
- **Test-Architektur**: klare Trennung `tests/Wire/`, `tests/Security/`, `tests/Transport/`, `tests/Cache/`, `tests/Exception/`, `tests/Integration/` ✓. `tests/Pest.php` Bootstrap, `tests/AsyncTestCase.php` für async-Tests. Bewerte Konsistenz Pest vs. PHPUnit-Stil-Mix.
- **Determinismus**: `RetryingIcapClient::$sleeper`-Test-Seam ✓, `InMemoryOptionsCache` injizierbarer Clock-Closure (v2.2) ✓. Andere Timing-Quellen?
- **`phpunit.xml.dist`**: `beStrictAboutCoverageMetadata=true`, `beStrictAboutOutputDuringTests=true`, `failOnRisky=true` (seit v2.1.2) — verifiziere, dass `failOnWarning` auch auf `true` steht (oder begründet auf `false`).

### Phase 6 — Symfony-Integration & Ökosystem-Fit (v3.0-Stand, Symfony 7.4-LTS-Ziel)

#### 6.1 Bundle-Frage
- `review_v2-1/consolidated_v2.1_task-list.md` plant ein eigenes Repo `ndrstmr/icap-flow-bundle` als v2.3-Roadmap-Item, **post-v3.0** (Bundle-Kontrakt jetzt eingefroren). Existiert das schon? Wenn ja, ist es publiziert? Wenn nein, **welcher Stand wäre minimal-viable**:
  - `IcapFlowBundle` mit `Configuration`-Tree (`host`, `port`, `socket_timeout`, `stream_timeout`, `tls`, `virus_found_headers[]`, `limits.*`, `pool.max_connections_per_host`, `pool.max_idle_seconds`, `retry.*`, `options_cache.adapter`).
  - `IcapFlowExtension` mit Service-Definitionen für `Config`, `AsyncAmpTransport`, `AmpConnectionPool` (oder `NullConnectionPool` für stateless Worker), `IcapClient`, `RetryingIcapClient`, `InMemoryOptionsCache` (Default) + Auto-Wiring auf `Psr6OptionsCache` wenn `cache.app` injiziert wird.
  - Auto-DI-Aliase: `IcapClientInterface` → `RetryingIcapClient` → inner `IcapClient`.
  - Tagged-Service-Support: mehrere ICAP-Konfigurationen (`icap_flow.client.<name>`).
- Bewerte: ist die v3.0-API (insb. `options(): Future<IcapResponse>`, `executeRaw()` `protected`) sauber DI-fähig? Fallen Edge-Cases aus dem `final readonly`-Pattern auf, wenn ein Bundle Services dekoriert?
- Bewerte: Library-Core bleibt framework-agnostisch — gut. Aber: ohne offizielles Bundle ist die Symfony-Adoption schwerer.

#### 6.2 Framework-Features (Symfony 7.4)
- **Symfony Profiler** DataCollector für ICAP-Aufrufe — fehlt im Library-Core (gehört ins Bundle).
- **Monolog Channel** `icap` — vom Bundle bereitstellbar, aber Library-PSR-3-Logger reicht aus.
- **Messenger-Integration**: Async-Scanning via Message-Queue. Beispiel im `examples/cookbook/`? Heute nicht; relevant für High-Throughput-Upload-Pipelines.
- **Console-Command**: `icap:scan <file>`, `icap:options <service>` für CLI-Debugging — fehlt.
- **Flex-Recipe**: nicht eingereicht (`symfony/recipes-contrib`)? Status?
- **Environment-Variablen**-Prozessoren (`%env(ICAP_HOST)%` etc.) — würde das Bundle abdecken.
- **Validator**: `#[IcapClean]` Constraint für File-Upload-Validation — typische Use-Case-Erweiterung, fehlt heute (gehört ins Bundle).
- **VichUploaderBundle / OneupUploaderBundle**: Integration-Hooks für Virus-Scan-on-Upload — typische Anwendung, fehlt.
- **Symfony Cache als OPTIONS-Backend**: `Symfony\Component\Cache\Adapter\AdapterInterface` implementiert PSR-6, also funktioniert `Psr6OptionsCache(symfony_cache)` direkt. Bewerte ob das im Cookbook explizit demonstriert sein sollte.

#### 6.3 Observability (Wunschliste W-Y)
- **OpenTelemetry** Instrumentation (Traces, Metrics) — auf Wunschliste W-Y verschoben. Bewerte: ist der Verzicht in v3.0 gerechtfertigt? Argument für die Verschiebung war "OTel im Deployment-Stack noch nicht relevant für Public-Sector-Stack". Stimmt das noch?
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

Erstelle am Ende eine Scoring-Tabelle. Jede Dimension 0–10 Punkte, mit Begründung und Code-Refs. **Vergleiche zusätzlich gegen die v2.1.0-Werte** (aus dem v2.1-Prompt-Output, sofern vorhanden) um Fortschritt sichtbar zu machen.

| Dimension | v1.0.0 (Referenz) | v2.1.0 (Audit-Snapshot) | v3.0.0 (Score) | Begründung | Kritische Findings |
|---|---|---|---|---|---|
| Sprachmoderne (PHP 8.4 / 8.5-Features) | | | | | |
| Typsystem / PHPStan-Strenge (Level 9 + bleedingEdge, `@template`) | | | | | |
| SOLID / Architektur-Klarheit | | | | | |
| Exception-Design + Marker-Interface (v3.0: 6 Typen, `IcapResponseException` weg) | | | | | |
| PSR-Konformität (PSR-3, PSR-4, PSR-6, PSR-16, PSR-20) | | | | | |
| Ressourcen-/Connection-Management | | | | | |
| Connection-Pool-Korrektheit (TLS-Isolation, Idle-Eviction, OPTIONS-Tuning) | n/a | | | | |
| Async-Implementierung (Amp v3 / Revolt, Per-IO-Timeout) | | | | | |
| Cancellation-Propagation | n/a | | | | |
| ICAP RFC 3507 Methoden-Vollständigkeit | | | | | |
| ICAP §4.5 Strict Preview-Continue (Same-Socket, Streaming) | n/a | | | | |
| ICAP §4.10.2 Options-TTL/ISTag-Cache (inkl. PSR-6/16 Cross-Process) | n/a | | | | |
| ICAP Robustheit (Parser, Edge Cases, RFC 7230 Folding) | | | | | |
| Multi-Vendor-Header-Support | n/a | | | | |
| Security-Posture (Fail-Secure, CRLF-Guard, DoS-Limits, TLS, OWASP-Mapping) | | | | | |
| Test-Coverage (Lines/Branches; Coverage-Push-Hotspots seit v2.2) | | | | | |
| Wire-Format-Tests (Hand-Computed Bytes) | n/a | | | | |
| Mutation Testing (CI Required, MSI ≥ 65 % seit v2.2) | | | | | |
| Integration-Testing gegen echte Server | | | | | |
| CI-Pipeline-Qualität (Audit, Multi-PHP, Integration, Mutation, Coverage) | | | | | |
| Dokumentation (README, Migration v1→v2 + v2→v3, compliance.md, Reviews) | | | | | |
| Example-/Cookbook-Qualität (8 Cookbook-Beispiele in v2.2) | | | | | |
| API-Stabilität / SemVer-Disziplin (v3.0 als reiner Cleanup-Release) | n/a | | | | |
| Symfony-Bundle-Integration (DI-Readiness, geplant `ndrstmr/icap-flow-bundle`) | | | | | |
| Observability (Logger, OTel, Profiler) | | | | | |
| Release-Management / Semver / Changelog (v1 → v2 → v2.1.x → v2.2 → v3.0) | | | | | |
| Public-Sector-Fit (EUPL, BSI, OpenCoDE, Souveränität, compliance.md) | | | | | |
| **Gesamt-Readiness-Score** | **/280** | **/280** | **/280** | | |

Zusätzlich: **TRL-Einschätzung v3.0.0** (1–9) mit Begründung; Vergleich zur v2.1-TRL (Audit-Konsens war TRL-7).

---

## 6. Produktionsreife-Gate

### 6.1 Ist v3.0.0 heute produktionsreif?
- **Für interne Tools / Prototypen**: Ja / Mit Einschränkungen / Nein.
- **Für Symfony-Applikationen in Projekten (TYPO3, Shopware, Portale)**: Ja / Mit Einschränkungen / Nein.
- **Für den Einsatz als kritische Security-Komponente (Virenscan auf Upload)**: Ja / Mit Einschränkungen / Nein. Beachte den README-AI-Disclaimer als Maintainer-Position — bewerte ob die getroffene Selbsteinschätzung deckungsgleich mit deinem Befund ist. Mit drei unabhängigen Audits (v1, v2.1, v3) als Provenance: ist die Empfehlung jetzt eindeutiger?
- **Für die geplante Bundle-Stufe (`ndrstmr/icap-flow-bundle`)**: Ist der API-Vertrag jetzt eingefroren genug, oder siehst du noch Stellen, die in einem hypothetischen v3.1/v4.0 wieder brechen müssten?

### 6.2 Was fehlt zum "technisch perfekten" State?
Priorisierte Liste der Gaps:
- **P0 (Blocker für Produktion)** — Echte Security- / Korrektheits-Mängel die jetzt zu fixen sind. (Erwartet: sehr wenig, da v2.1.x die letzten v2.1-Audit-P0/P1-Items geschlossen hat. Mögliche neue Kandidaten: PSR-Cache-Race-Conditions, Per-IO-Timeout-Edge-Cases, Logging-Schema-Inkonsistenzen nach v3.0-options-Change.)
- **P1 (Kritisch für Ökosystem-Fit)** — Symfony-Bundle (v2.3-Roadmap), OpenTelemetry-Decorator (Wunschliste W-Y), Console-Commands, Symfony-Cache-Cookbook-Demo.
- **P2 (Nice-to-have / Differenzierung)** — Property-Based-Tests, phpbench-Suite (Wunschliste W-Z), Validator-Constraint, Vich/OneupUploader-Adapter, SBOM-Workflow (Wunschliste W-sbom), Health-Check-Helper.
- **P3 (Langfristige Vision)** — Formal verified Parser, Multi-Vendor-Integration-Pipeline (Symantec/Kaspersky/Trend Micro/Sophos in CI), Fuzz-Korpus.

### 6.3 Konkrete Roadmap

Schlage eine versionsbasierte Roadmap vor — mit dem Wissen, dass v3.0 ein reiner Cleanup-Release ist und keine neuen Features mitbringt:
- **v3.0.x** (Patch — reine Bugfixes / Doc-Klarstellungen, falls dein Review welche findet, z.B. ein Logging-Schema-Inkonsistenz nach dem `options()`-Change).
- **v3.1.0** (Minor — additive Features: was sollte als nächstes hinzukommen? OpenTelemetry-Decorator? Console-Commands? PHPBench-Suite?).
- **v3.2.0** (Minor — weitere additive Erweiterungen, z.B. Property-Based-Tests, Validator-Constraint).
- **v4.0.0** (Major — nur falls Reviews echte Breaking Changes erfordern; mit dem Cleanup von v3.0 sollte das nicht nötig sein für Jahre).
- **Begleit-Repo**: `ndrstmr/icap-flow-bundle` (Symfony 7.4) — soll das als v0.1 oder direkt v1.0 starten? Welcher API-Snapshot der Library wird referenziert (`^3.0`)? Zeitpunkt empfehlen.

Bewerte zusätzlich: ist die in der Wunschliste verschobene Triage (W-Y OpenTelemetry, W-Z PHPBench, W-sbom SBOM) korrekt — oder hat sich der Bedarf inzwischen geändert (z.B. durch neue BSI/EU-Anforderungen für Public-Sector-Software)?

---

## 7. Output-Format & Deliverables

Liefere am Ende **eine strukturierte Antwort** mit folgenden Abschnitten (in dieser Reihenfolge):

1. **Executive Summary** (max. 500 Wörter) — Kernbefund, Empfehlung, TRL-Score, Vergleich v2.1→v3.0.
2. **Repository-Inventar v3.0.0** (Phase 1).
3. **v3.0-BC-Break-Verifikation** — Tabelle v3-V / v3-W / v3-F. Pro Item: `Status (verifiziert geschlossen / teilweise / Regression)`, Code-Ref, Begründung. Plus eine kurze Spot-Check-Tabelle der v2.2-Closures (PSR-Cache-Adapter, Pool-Idle-Eviction, Per-IO-Timeout, OPTIONS-driven Tuning, ISTag-Invalidation).
4. **Findings nach Dimension** (Phase 2–6 detailliert, Code-Refs im Format `src/Pfad/Datei.php:Z-Y`).
5. **ICAP RFC 3507 Compliance-Checkliste v3.0** (tabellarisch).
6. **OWASP Top 10 (2021/2025) Mapping** — eine Spalte pro Top-10-Kategorie, eine Zeile pro relevantem Library-Mechanismus, mit Status `Mitigated / Partial / N/A`.
7. **Pool / Session-Lifecycle / Cache Threat-Analyse** (eigener Abschnitt — die zentrale Schichten-Analyse: TLS-Pool-Isolation, Idle-Eviction, OPTIONS-driven Tuning, Per-IO-Timeout, PSR-Cache-Cross-Process-Race-Conditions).
8. **Wettbewerbsvergleich** (Phase 7, tabellarisch).
9. **Bewertungsmatrix** (Kapitel 5, mit v1- und v2.1-Spalten als Vergleich).
10. **Produktionsreife-Gate-Entscheidung** (Kapitel 6.1).
11. **Priorisierte Gap-Liste** (Kapitel 6.2).
12. **Roadmap v3.0.x → v3.1 → v3.2 → v4.0** (Kapitel 6.3) — inkl. Bundle-Empfehlung.
13. **Quellenverzeichnis** — Alle konsultierten RFCs (3507, 7230, 7231, 9110), Symfony-Docs (7.4 LTS), Amp-Docs (v3), PSR-Specs (3, 6, 16, 20), OWASP (Top 10 2021, ASVS 4.0/5.0), BSI IT-Grundschutz (OPS.1.1.4, APP.4.4), Vendor-ICAP-Docs (c-icap, Symantec, Sophos, Kaspersky, Trend Micro), Vergleichs-Repos.

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

- [ ] Habe ich **jede Datei in `src/` mindestens einmal inspiziert** — besonders die v2.2/v3.0-Neuzugänge bzw. -Änderungen (`IcapClient.php` mit `assertSuccessfulStatus()`, `Cache/Psr6OptionsCache.php`, `Cache/Psr16OptionsCache.php`, `Transport/NullConnectionPool.php`, `Transport/AmpTransportSession.php` mit Per-IO-Timeout, `Transport/AmpConnectionPool.php` mit Idle-Eviction + `tuneFromOptions()`, `RetryingIcapClient.php` mit `@template T`)?
- [ ] Habe ich die **drei v3-BC-Breaks (v3-V, v3-W, v3-F) am Code verifiziert** — und auch die v2.1.x/v2.2-Closures stichprobenartig?
- [ ] Habe ich **jede Test-Datei** auf Coverage-Lücken geprüft — insbesondere `tests/Wire/`, `tests/Security/`, `tests/Transport/`, `tests/Cache/`, `tests/PreviewContinueStrictTest.php`?
- [ ] Habe ich **RFC 3507 (besonders §4.4–§4.10), RFC 7230 (§3.2.4 Folding, §4.1 Chunked, §6.1 Connection-close), RFC 9110 (semantic)** konkret aufgeschlagen und gegen den Code geprüft — nicht aus dem Gedächtnis?
- [ ] Habe ich **OWASP Top 10 (2021/2025)** als Mapping-Tabelle geliefert?
- [ ] Habe ich **mindestens drei Alternativ-Implementierungen** in anderen Sprachen als Benchmark angeschaut — und ehrlich verglichen?
- [ ] Ist **jede kritische Aussage mit Datei-/Zeilen-Referenz oder externer Quelle belegt**?
- [ ] Sind meine **Empfehlungen so konkret**, dass sie direkt als GitHub-Issue / PR-Diff geöffnet werden könnten?
- [ ] Habe ich **die Pool-/Session-/Cache-Lifecycle-Sicherheit** in einem eigenen Threat-Analyse-Abschnitt durchgekämmt — inkl. Cross-Tenant-, Cancellation-Mid-Read-, Per-IO-Timeout-Edge-Case- und Cross-Process-Cache-Race-Szenarien?
- [ ] Habe ich **die Symfony 7.4 / Public-Sector-Spezifika** (DI, Profiler, BSI, EUPL, OpenCoDE, compliance.md, Souveränität) ausreichend gewichtet?
- [ ] Habe ich **Self-Reports des Maintainers** (CHANGELOG, README, `migration-v2-to-v3.md`, `consolidated_v2.1_task-list.md`) als Hypothesen behandelt und am Code verifiziert — nicht als Wahrheit übernommen?
- [ ] Habe ich die **drei vorigen Audit-Sets** (v1, v2.1) als Provenance verstanden und Wiederöffnungen aktiv gesucht?
- [ ] Bin ich **ehrlich kritisch** oder habe ich unterbewusst wohlwollend bewertet, weil v3 viel Cleanup-Arbeit war und nichts kaputtgegangen ist?
- [ ] Habe ich umgekehrt nicht künstlich Findings produziert, um „etwas zu liefern"? Würde ich jeden gemeldeten Punkt entweder als GitHub-Issue öffnen oder in einer Architekturdiskussion ansprechen — und wenn nicht, gehört er nicht in den Bericht?
- [ ] Habe ich auch **dokumentiert, was sauber gelöst ist** — nicht nur Mängel?

Wenn eines dieser Gates nicht erfüllt ist, recherchiere nach und ergänze, bevor du abschließt.

---

## 9. Zusätzliche Hinweise

- **Wenn Informationen unzugänglich sind** (z.B. privater Repo-Teil, fehlende Issue-History, gh-pages-Coverage offline): explizit markieren, keine Halluzinationen.
- **Wenn du Annahmen triffst** (z.B. über nicht dokumentierte interne Motivation): als Annahme kennzeichnen.
- **Wenn du zwischen zwei Design-Alternativen abwägst**: beide Optionen mit Trade-offs darstellen, dann eine begründete Empfehlung.
- **Bei Ungewissheit über aktuelle Versionen** (Amp v3, Revolt 1.x, PHPStan 2.x, Pest 3, Symfony 7.4 LTS, PHP 8.5): aktuellen Stand recherchieren.
- **Vergleich v2.1↔v3.0**: Bei jedem Finding-Status erläutern, **wie** die Behebung erfolgte (Wire-Format-Test mit Hand-Computed-Bytes? Pool-Test mit `Amp\Socket\createSocketPair`? Per-IO-Timeout-Test mit Socket-Pair und gestaffelten Server-Delays? PSR-Cache-Test mit In-Memory-Test-Doubles?). Der Refactor-Pfad ist Teil des Lernens.
- **Beachte den v3-Stil**: v3.0 ist bewusst ein reiner Cleanup-Release ohne neue Features. Wenn du Features fehlen siehst, schiebe sie auf v3.1+. Wenn du im v3-Code Bugs findest, sind das echte v3.0-P0/P1-Items.

**Starte jetzt mit Phase 1.**
