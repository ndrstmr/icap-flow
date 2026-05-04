# Deep Review: ICapFlow v3.0.0 Production-Readiness & Technical Excellence Audit

## Executive Summary

ICapFlow v3.0.0 ist im Kern ein **konsequenter Cleanup-Major** ohne neue Feature-Fläche; die drei angekündigten BC-Breaks sind am Code nachvollziehbar umgesetzt: `executeRaw()` ist `protected`, `options()` liefert `IcapResponse`, und `IcapResponseException` ist entfernt. Die v2.2-Schließungen (Per-IO-Timeout, Pool-Idle-Eviction, OPTIONS-driven Pool-Tuning, ISTag-basierte Cache-Invalidation, PSR-6/16-Adapter) sind ebenfalls im Code und in dedizierten Tests sichtbar.

Aus Security-/Korrektheitssicht ist die zentrale Fail-Secure-Matrix stabil: `100` außerhalb Preview, `4xx` und `5xx` werden als Exceptions erzwungen; Parser/Framer haben DoS-Grenzen und RFC-7230-obs-fold-Handhabung. Für den Einsatz als Upload-Malware-Gate in Symfony/TYPO3/Shopware lautet mein Urteil: **produktionsreif mit überschaubaren P1/P2-Aufgaben im Ökosystem (Bundle/Observability) statt im Core**.

Haupt-Risiken liegen nicht mehr in offensichtlichen Protokollfehlern, sondern in Randbedingungen: (a) Cross-process-Race-Fenster der PSR-Cache-Meta-Key-Invalidation, (b) kein globales Hard-Deadline-Konzept über mehrere Per-IO-Zyklen, (c) fehlende First-Party-Symfony-Bundle-Schicht.

**TRL-Einschätzung v3.0.0: 8/9** (v2.1-Konsens lag bei 7). Der Sprung ergibt sich aus geschlossenem Audit-Backlog, stabiler Test-/Mutation-Governance und klarer SemVer-Bereinigung vor Bundle-Start.

## Repository-Inventar v3.0.0 (Phase 1)

- PHP-Dateien gesamt: 78 (`src`: 39, `tests`: 39).
- LOC: `src` 4212, `tests` 5122.
- Test-zu-Code-Ratio (LOC): ~1.22.
- Unit-Run lokal: 159 Tests, 363 Assertions.

## v3.0-BC-Break-Verifikation

| Item | Erwartung | Status | Evidenz |
|---|---|---|---|
| v3-V | `IcapClient::executeRaw()` ist `protected` inkl. Security-Rationale | Verifiziert geschlossen | `src/IcapClient.php` (Docblock + `protected function executeRaw`) |
| v3-W | `options(): Future<IcapResponse>` + gemeinsame Failure-Single-Source (`assertSuccessfulStatus`) | Verifiziert geschlossen | `src/IcapClient.php`, `src/IcapClientInterface.php`, `src/SynchronousIcapClient.php` |
| v3-F | `IcapResponseException` entfernt; Throw-Sites auf `IcapProtocolException` | Verifiziert geschlossen | `src/Exception/` ohne Datei, `src/DefaultPreviewStrategy.php`, `tests/Exception/ExceptionHierarchyTest.php` |

### Spot-Check v2.2-Closures

- Per-IO-Timeout umgesetzt in `AmpTransportSession::makeIoCancellation()` und per Test abgesichert.
- Idle-Eviction (`maxIdleSeconds`) aktiv in `AmpConnectionPool` + eigene Testdatei.
- OPTIONS-driven Pool-Tuning via `tuneFromOptions()` vorhanden + Tests für dynamische Updates.
- PSR-6/16-Caches mit ISTag-Meta-Keys (`__icap_istag`, `__icap_keys`) vorhanden + dedizierte Tests.

## Findings nach Dimension (Fakten vs Empfehlungen)

### P1 — Cross-process Cache Race (PSR-6/16 Meta-Key Invalidation)

**Fakt:** `Psr6OptionsCache` und `Psr16OptionsCache` implementieren ISTag-Flush über getrennte Writes auf Daten-Key + Meta-Keys (`__icap_istag`, `__icap_keys`), ohne atomare Transaktion.

**Risiko:** In Redis/Memcached-Clustern können konkurrierende Worker inkonsistente Zwischenstände sehen (TOCTOU), insbesondere bei hoher OPTIONS-Schreibfrequenz.

**Empfehlung:** Optionalen atomaren Backend-Mode ergänzen (z. B. CAS/Lua/transaction-capable adapter hook), mindestens dokumentierte Konsistenzgarantien + Lasttest-Szenario.

### P2 — Kein globales Request-Deadline-Konzept

**Fakt:** Per-IO-Timeout ist korrekt pro I/O neu zusammengesetzt; es existiert kein zusätzlicher harter Gesamt-Timeout über die komplette Session/Preview-Mehrphasen-Operation.

**Risiko:** Bei „dauerhaft knapp unter Timeout“-I/O kann ein Request praktisch unbegrenzt laufen.

**Empfehlung:** Optionalen `maxSessionDuration`/`hardDeadline` in `Config` ergänzen und in `AmpTransportSession` mit externer Cancellation kombinieren.

### P1 — Ökosystem-Lücke: fehlendes offizielles Symfony-Bundle

**Fakt:** Core ist DI-fähig, aber produktiver Symfony-Betrieb (multi-client config, cache wiring, retry policy, channelized logging, health-probes) erfordert derzeit Eigenbau.

**Empfehlung:** `ndrstmr/icap-flow-bundle` zeitnah als Begleit-Repo (`^3.0`) starten; Fokus auf Configuration tree, service aliases, PSR-6 wiring, optional DataCollector.

## Positiv verifiziert (erhalten)

- Fail-secure Status-Mapping bleibt strikt und zentralisiert.
- Strict preview-continue streamt Restdaten ohne Vollpufferung.
- TLS-kontextbasierte Pool-Isolation gegen Cross-Tenant-Reuse vorhanden.
- Tests decken Wire-Format, Pool-Lifecycle, Security-Guards und Cache-Adapter breit ab.

## Roadmap

- **v3.0.x:** Dokuhärtung zu Cache-Konsistenz + klare Guidance für OPTIONS-Cache in verteilten Setups.
- **v3.1.0:** Hard-deadline-Feature, OTel-Decorator, offizielles Symfony-Bundle v0.1/1.0.
- **v3.2.0:** Property-based Parser tests, optional fuzz harness, Symfony cookbook for `cache.app` + Messenger.
- **v4.0.0:** Nur bei tatsächlichen API-Zwängen (derzeit nicht erforderlich).

## Phase 2 — Code- & Architektur-Analyse

### 2.1 Sprachmoderne & Typsystem (PHP 8.4/8.5)

- `declare(strict_types=1)` und EUPL-Header sind im Core durchgängig umgesetzt.
- `Config` ist `final readonly`; Wither (`with*`) liefern neue Instanzen statt Mutationen.
- `#[\Override]` wird in den Interface-Implementierungen konsequent genutzt (`IcapClient`, `SynchronousIcapClient`, `RetryingIcapClient`).
- `RetryingIcapClient::withRetry()` ist generisch (`@template T`) und wird sowohl für `Future<ScanResult>` als auch `Future<IcapResponse>` verwendet; das passt zur v3-`options()`-Semantik.
- `IcapResponseException` ist in der Exception-Hierarchie nicht mehr vorhanden; Marker-Catch bleibt über `IcapExceptionInterface` konsistent.

**Bewertung:** Typsystem-Strenge ist für eine Netzwerkbibliothek überdurchschnittlich gut; ein größeres Restrisiko liegt weniger in Typen als in verteilten Cache-Atomizitäten.

### 2.2 Design, Pattern & SOLID

- Core-Schnitt ist klar: Client orchestriert Formatter/Transport/Parser, Retry bleibt als separater Decorator.
- `assertSuccessfulStatus()` entkoppelt Fail-Secure-Mapping sauber von der Scan-Interpretation; dadurch ist die `options()`-Semantik korrekt und konsistent.
- Preview-Handling ist mit Strategy (`PreviewStrategyInterface`) gut kapsuliert; der Strict-Path bleibt in derselben Session (same-socket).
- `executeRaw()` als `protected` verhindert, dass Consumer die Security-Interpretation im Normalfall umgehen.

**Bewertung:** SRP/Decorator/Strategy sind stimmig umgesetzt; keine offensichtliche Architektur-Regression durch v3-Cleanup.

### 2.3 PSR-Compliance

- PSR-3: Logger optional, `NullLogger` default, keine Header-Payloads im Logging-Kontext.
- PSR-4: Namespace/Verzeichnisstruktur ist konsistent.
- PSR-6/PSR-16: Adapter vorhanden und getestet, aber mit Meta-Key-basiertem Invalidierungsdesign (Race-Fenster bleibt als bekannter Trade-off).
- PSR-20: InMemory-Clock nutzt Closure statt `ClockInterface`; pragmatisch testbar, aber weniger interoperabel.

**Empfehlung (P2):** Optionalen `ClockInterface`-Adapterpfad in v3.1 ergänzen (ohne Breaking Change).

### 2.4 Fehlerbehandlung & Exception-Design

- Status-Mapping bleibt fail-secure: `100` außerhalb Preview ist Protocol-Fehler; `4xx`/`5xx` sind typisiert.
- Nach Entfernung von `IcapResponseException` ist `IcapProtocolException` jetzt der Catch-all für unerwartete/non-taxonomy Status.
- Retry bleibt bewusst auf `IcapServerException` (5xx) begrenzt; das schützt vor Retry auf Protokoll- oder Client-Fehlern.

**Bewertung:** Für Security-kritische Upload-Scans ist diese Trennung sinnvoll und defensiv.

### 2.5 Ressourcen-Management & Connection-Handling

- Pool-Key enthält TLS-Fingerprint-Kontext; reduziert Cross-Tenant-Reuse-Risiko.
- Idle-Eviction ist lazy-on-acquire implementiert (performant, aber ohne Hintergrund-Reaper).
- Bei Fehlern im Session-Flow wird Socket geschlossen statt zurück in den Pool gelegt (fail-safe Pool-Hygiene).
- Per-IO-Timeout behebt das frühere Lifetime-Cancellation-Problem, aber ohne globale Hard-Deadline.

**Empfehlung (P2):** Optionalen globalen Request-Timeout als zweite Schutzlinie einführen.

### 2.6 Async-Implementierung

- Amp-Futures/Cancellation werden End-to-End durchgereicht (Client → Transport → Session).
- `CompositeCancellation` + frische `TimeoutCancellation` pro I/O ist korrekt für mehrphasige Preview-Flows.
- Sync-Wrapper bleibt als klare Integrationsschicht für nicht-async Laufzeitkontexte vorhanden.

**Bewertung:** Async-Design ist robust und praxistauglich; größte offene Lücke liegt eher bei Observability/Bundle-Fit als im Event-Loop-Handling selbst.

## Phase 3 — ICAP-Protokoll-Compliance (RFC 3507)

### 3.1 Methodenabdeckung

- **OPTIONS** ist als Capability-Discovery korrekt modelliert (v3: raw `IcapResponse` statt `ScanResult`).
- **RESPMOD** ist der primäre Scan-Pfad (`scanFile`, `scanFileWithPreview`).
- **REQMOD** wird vom Formatter/Parser unterstützt und über `request(IcapRequest)` adressiert.

**Verifikationsergebnis:** Methodenmatrix ist für den Library-Auftrag vollständig; v3-Semantik-Korrektur bei OPTIONS ist konsistent über Async- und Sync-Client.

### 3.2 Nachrichtenformat (Wire)

- Request-Line/Encapsulation wird im Formatter gebildet; die Wire-Tests prüfen Hand-Computed-Bytes für OPTIONS/RESPMOD/REQMOD.
- Chunked-Encoding inklusive `0; ieof`-Pfad ist testseitig abgesichert.
- Response-Framing arbeitet encapsulated-aware statt `Connection: close`-Heuristik.

**Verifikationsergebnis:** RFC-nahe Wire-Disziplin ist vorhanden; die Test-Suite schützt die kritischen Byte-Pfade gut gegen Regression.

### 3.3 Preview (§4.5)

- Strict Preview-Continue läuft bei `SessionAwareTransport` auf **demselben Socket**.
- Legacy-Fallback für nicht-sessionfähige Transports bleibt als bewusstes Kompatibilitätsverhalten erhalten.
- Fortsetzungs-Streaming nutzt den Chunked-Encoder aus dem aktuellen Stream-Offset (kein Vollbuffer-Read des Restbodys).

**Verifikationsergebnis:** Der v2.1.2-Fix gegen OOM-Risiko ist im Designpfad verankert und durch dedizierte Wire/Transport-Tests flankiert.

### 3.4 Statuscode-Matrix (v3)

- `204` → clean scan.
- `200/206` → Header-basierte Virus-Interpretation.
- `100` außerhalb Preview → `IcapProtocolException` (fail-secure).
- `4xx` → `IcapClientException`, `5xx` → `IcapServerException`.
- Nicht zuordenbare Codes außerhalb dieser Taxonomie laufen fail-secure als Protocol-Fehler.

**Verifikationsergebnis:** v3-Extraktion `assertSuccessfulStatus()` reduziert Drift-Risiko zwischen `request()` und `options()`.

### 3.5 Parser-Robustheit & Security

- Parser verarbeitet RFC-7230-obsolete-folding (praktisch relevant für c-icap/Vendor-Header).
- DoS-Limits (`maxResponseSize`, Headeranzahl, Headerzeilenlänge) sind konfigurierbar und testseitig abgesichert.
- Malformed-Input wird in typisierte Protocol/Malformed-Exceptions überführt.

**Verifikationsergebnis:** Robuste defensive Parsing-Strategie; kein Hinweis auf fail-open-Verhalten im Parserpfad.

### 3.6 Interop-/Kompatibilitätsstand

- CI-Integration gegen c-icap/ClamAV ist vorhanden.
- Multi-Vendor-Header-Support ist im Core konfigurierbar und getestet.
- Breite Hersteller-Interop (Symantec/Sophos/Trend/Kaspersky) ist nicht als First-Party-CI-Matrix abgedeckt.

**Empfehlung (P2):** Interop-Matrix als optionales Nightly-Programm aufbauen (nicht als Hard-Gate für PRs).

### RFC-3507-Checkliste (Phase-3 Snapshot)

| Bereich | Status | Evidenz im Repo |
|---|---|---|
| OPTIONS Capability Discovery | Mitigated | `IcapClient::options()`, `OptionsCache*`, `OptionsCacheTest` |
| REQMOD/RESPMOD Wire-Format | Mitigated | `RequestFormatter`, `Wire/RequestFormatterWireTest.php` |
| Preview §4.5 Same-Socket (strict) | Mitigated | `scanFileWithPreviewStrict`, `PreviewContinueStrictTest.php` |
| Preview fallback behavior | Partial (bewusst) | `scanFileWithPreviewLegacy` |
| Encapsulated-aware response framing | Mitigated | `ResponseFrameReader`, `ResponseFrameReaderTest.php` |
| Statuscode Fail-Secure-Mapping | Mitigated | `interpretResponse`, `assertSuccessfulStatus`, Security-Tests |
| Header folding / malformed handling | Mitigated | `ResponseParser`, `Wire/ResponseParserWireTest.php` |
| Vendor breadth CI coverage | Partial | Integration nur gegen c-icap/ClamAV |

## Phase 4 — Security & Compliance Assessment

### 4.1 Security Posture v3.0 (OWASP-orientiert)

#### Fail-Secure-Verhalten

- Der Core erzwingt fail-secure für `100` außerhalb Preview sowie für `4xx/5xx` via typed exceptions.
- `options()` nutzt dieselbe Failure-Policy wie Scan-Pfade (keine semantische Sonderbehandlung als „clean“).
- Unerwartete Statusbereiche außerhalb der bekannten Taxonomie landen ebenfalls als Protocol-Fehler.

**Bewertung:** Für ein Upload-Security-Gateway ist dieses Fehlerverhalten angemessen defensiv.

#### Input-/Header-Validierung (Injection)

- Service-Path-Validierung blockiert Control-Characters, NUL und Whitespace zur Vermeidung von Request-Line/Header-Injection.
- Header-Validierung prüft Namen gegen RFC-7230-tchar und validiert Werte inkl. Array-Mehrfachwerten auf CR/LF/NUL.
- Damit ist der bekannte „nested header value“-Vorwurf aus früheren Reviews im v3-Codepfad faktisch nicht bestätigt.

**Restrisiko:** `%0d%0a` wird nicht explizit decoded; im aktuellen Modell ist das vertretbar, da keine URI-Decodierung vor Wire-Emission erfolgt.

#### TLS / Connection-Isolation

- Pool-Key differenziert per TLS-Kontext-Fingerprint (host:port plus TLS-relevante Materialisierung), was Cross-Tenant-Reuse mit abweichender TLS-Konfiguration verhindert.
- Fehlerpfade schließen Session-Sockets fail-safe statt potenziell desynchronisierte Streams in den Pool zurückzugeben.

**Bewertung:** Die v2.1.1-Härtung gegen Cross-Tenant-Leaks ist in v3 intakt.

#### Logging / Sensitive Data Exposure

- Logging ist optional (PSR-3, `NullLogger` default).
- Event-Kontext fokussiert auf Metadaten (`method`, `uri`, `host`, `port`, `statusCode`, bei Scan `infected`) statt Header-/Body-Inhalten.
- Regressionstest schützt gegen Header-Leakage im Log-Kontext.

**Bewertung:** DSGVO-relevante Minimierung ist im Library-Core sinnvoll umgesetzt; Betreiberverantwortung bleibt beim Logger-Backend.

#### Dependency Security

- Security-Guardrails via `composer audit` und `roave/security-advisories` sind im Projektprozess verankert.
- Das senkt Lieferkettenrisiken, ersetzt aber keine SBOM-/Provenance-Transparenz für Public-Sector-Beschaffung.

**Empfehlung (P2):** SBOM-Export (CycloneDX/SPDX) als CI-Artefakt optional in v3.1 einführen.

### 4.2 PSR-Cache-Cross-Process-Analyse (vertieft)

#### Beobachtung

- PSR-6/16-Adapter nutzen Meta-Keys (`__icap_istag`, `__icap_keys`) für globale ISTag-Invalidation.
- Der Ansatz ist pragmatisch und backend-agnostisch, aber nicht streng atomar in jedem Store.

#### Risiko-Szenarien

1. **TOCTOU beim ISTag-Switch:** Worker A schreibt neuen Response-Key, Worker B liest zwischen Daten- und Meta-Key-Update.
2. **Key-Set-Wachstum:** `__icap_keys` kann in dynamischen Multi-Service/Multi-Tenant-Umgebungen wachsen.
3. **Backend-Semantik-Divergenz:** Redis/Memcached/File-Adapter verhalten sich bzgl. Konsistenz/Race unterschiedlich.

#### Präzise Empfehlung

- v3.0.x: Dokumentation ergänzen (Konsistenzmodell + empfohlene Backend-Klassen).
- v3.1: Optionaler „atomic invalidation strategy“-Hook (adapter- oder backend-spezifisch).
- v3.1+: Last-/Race-Testprofil (parallelisierte Worker-Simulation) als nicht-blockender CI-Job.

### 4.3 OWASP Top 10 (2021/2025) Mapping — Library-spezifisch

| OWASP-Kategorie | Relevanter Mechanismus in icap-flow | Status |
|---|---|---|
| A01 Broken Access Control | Nicht primär Library-Scope (keine AuthZ-Engine) | N/A |
| A02 Cryptographic Failures | TLS-Unterstützung + Pool-Isolation nach TLS-Kontext | Partial (Caller muss TLS policy sauber setzen) |
| A03 Injection | Service-/Header-CRLF-Guards, Header-Name/Value-Validierung | Mitigated |
| A04 Insecure Design | Fail-secure Status-Mapping, typed exception taxonomy | Mitigated |
| A05 Security Misconfiguration | Sichere Defaults für Parser-/Response-Limits, aber TLS optional | Partial |
| A06 Vulnerable Components | `composer audit` + roave advisories | Mitigated |
| A07 Identification/Auth Failures | Nicht primärer Scope (abhängig von Upstream/ICAP-Infra) | N/A |
| A08 Software/Data Integrity Failures | Keine Signatur-/attestation-Pipeline im Repo | Partial |
| A09 Logging/Monitoring Failures | Minimaler Log-Context + Leak-Guard-Test | Mitigated |
| A10 SSRF | Host/Port kommen aus App-Konfiguration; kein direkter User-Input-Pfad im Core | Partial (Betriebsrichtlinie nötig) |

### 4.4 Public-Sector-Compliance (EUPL/BSI/DSGVO/OpenCoDE)

#### EUPL / Lizenz

- EUPL-1.2 ist konsistent im Repo verankert; Header-Disziplin in Source/Test-Dateien ist sichtbar.

#### BSI-Grundschutz / Betriebskontext

- Das vorhandene Compliance-Mapping ist für technische Orientierung nützlich, aber kein formaler Nachweis.
- Für Behördenbetrieb bleibt die Betriebsdokumentation (Netzsegmentierung, Schlüsselmanagement, Incident-Prozesse) außerhalb der Library zwingend.

#### DSGVO Art. 32

- Logging-Minimierung im Core unterstützt Datenschutzprinzipien.
- Der eigentliche Datenschutz-Impact entsteht durch Integrationsentscheidungen (welche Dateiinhalte/Metadaten geloggt oder gespeichert werden).

#### Digitale Souveränität

- OSS-Stack ohne SaaS-Zwang ist positiv; für Beschaffungspfade kann SBOM/Provenance mittelfristig relevant werden.

### 4.5 Phase-4-Entscheidung (präzise)

- **Keine neuen P0-Blocker** aus dem Core-Codepfad identifiziert.
- **P1 bestätigt:** Cross-process Cache-Konsistenz ist die wichtigste verbleibende technische Unsicherheit.
- **P2 bestätigt:** SBOM/Provenance, globales Deadline-Modell und erweiterte Interop-Matrix sind die sinnvollsten nächsten Schritte.

## Phase 5 — Testing & Qualitätssicherung (Coverage/Mutation/CI-Gates)

### 5.1 Unit-Test-Qualität & Suite-Disziplin

- PHPUnit ist strikt konfiguriert (`beStrictAboutCoverageMetadata`, `beStrictAboutOutputDuringTests`, `failOnRisky=true`, `failOnWarning=true`).
- Unit und Integration sind sauber getrennt (eigene Testsuites, Integration ausgeschlossen aus Unit-Run).
- Die dokumentierte lokale Unit-Ausführung (159/363) passt zur erwarteten Größenordnung für den v3-Stand.

**Bewertung:** Test-Hygiene ist überdurchschnittlich robust; „grün“ bedeutet hier mehr als nur „keine Assertion fehlgeschlagen“.

### 5.2 Coverage-Gates

- CI führt Unit-Tests mit Coverage auf **PHP 8.4 und 8.5** aus.
- Coverage wird als Artifact persistiert und auf `gh-pages` für `main` veröffentlicht.
- `includeUncoveredFiles=true` in der PHPUnit-Coverage-Konfiguration verhindert künstlich geschönte Coveragewerte.

**Einschätzung:** Der Gate-Mechanismus ist sauber; die konkrete Prozentzahl muss aus dem jeweils aktuellen Coverage-Report gezogen werden (nicht statisch im Doc hardcoden).

### 5.3 Mutation-Testing-Gate

- Mutation-Job ist als eigener CI-Job mit MSI-Schwelle (`--min=65`) konfiguriert.
- Mutation läuft auf PR-Events nach erfolgreichem Quality-Gate.

**Bewertung:** Für eine Security-kritische Library ist 65% ein brauchbarer Einstieg, aber mittelfristig konservativ.

**Empfehlung (P2):** MSI in Stufen anheben (z. B. 65 → 70 → 75), begleitet von gezielten Tests für überlebende Mutanten in Status-/Parser-/Cache-Pfaden.

### 5.4 Integration-Tests & Intermittenz-Risiko

- Integration läuft gegen echtes `c-icap + ClamAV` Setup via Docker Compose.
- Readiness-Probe prüft nicht nur offenen Port, sondern erwartet echte ICAP-Antwort (`ICAP/1.x`) — wichtig gegen Freshclam-Cold-Start-Flakiness.
- Integration wird auf `push`/`schedule` ausgeführt, nicht auf PRs.

**Trade-off:** Schnellere PR-Zyklen vs. späteres Erkennen von Wire-Interop-Regressionen.

**Empfehlung (P1/P2-Grenze):** Optionalen, lightweight PR-smoke für Integration (z. B. manuell auslösbar oder label-gesteuert) ergänzen, um kritische Wire-Änderungen früher zu validieren.

### 5.5 Cache-/Timeout-spezifische Teststärke

- Dedizierte Testdateien für `PerIoTimeout`, `AmpConnectionPool` (inkl. Idle-Eviction/Max-Connections) und PSR-6/16-Options-Cache-Adapter sind vorhanden.
- Das reduziert das Risiko, dass regressionskritische v2.2/v3-Pfade unbemerkt aufweichen.

**Restrisiko:** Cross-process-Races in echten verteilten Caches sind mit In-Memory/Test-Doubles nur begrenzt abbildbar.

### 5.6 CI-Gate-Bewertung (Gesamt)

| Gate | Status | Bewertung |
|---|---|---|
| Lint/Style (`cs-check`) | Aktiv | Gut |
| Static Analysis (`stan`) | Aktiv | Gut |
| Unit Tests (8.4/8.5) | Aktiv | Sehr gut |
| Coverage Artifact/Deploy | Aktiv | Gut |
| Integration c-icap/ClamAV | Aktiv (push/schedule) | Gut mit PR-Lücke |
| Mutation (`min=65`) | Aktiv (PR) | Gut, steigerbar |
| Dependency Audit | Aktiv | Gut |

**Phase-5-Fazit:** Die QA-Kette ist für v3.0 stark. Die größten Hebel sind jetzt nicht „mehr von allem“, sondern **gezielte Härtung**: höhere Mutation-Schwelle, optionaler PR-Integration-Smoke und belastbarere Cache-Race-Validierung.

## Phase 6 — Symfony-Integration & Ökosystem-Fit

### 6.1 Bundle-Readiness (Core → `ndrstmr/icap-flow-bundle`)

- Der Core bleibt framework-agnostisch und ist damit grundsätzlich bundle-freundlich.
- `IcapClientInterface` plus `RetryingIcapClient`-Decorator passen gut zu Symfony-DI-Alias-Ketten.
- Die v3-API-Bereinigung (`options(): IcapResponse`, `executeRaw()` geschützt) reduziert API-Ambiguitäten für spätere Bundle-Kontrakte.

**Bewertung:** Der jetzige Core ist hinreichend stabil, um ein offizielles Bundle auf `^3.0` aufzusetzen.

### 6.2 Minimal Viable Bundle (konkret)

Empfohlene erste Version (v0.1 oder direkt v1.0, je nach Stabilitätsanspruch):

1. `IcapFlowBundle` + `DependencyInjection/Configuration` mit Tree für:
   - `host`, `port`, `socket_timeout`, `stream_timeout`
   - `tls.*` (inkl. mTLS-Optionen)
   - `virus_found_headers[]`
   - `limits.max_response_size`, `limits.max_header_count`, `limits.max_header_line_length`
   - `pool.max_connections_per_host`, `pool.max_idle_seconds`
   - `retry.max_attempts`, `retry.initial_delay_ms`, `retry.max_delay_ms`
   - `options_cache` (`in_memory` / `psr6` / `psr16`)
2. Service-Wiring:
   - `Config`
   - `AmpConnectionPool` (optional `NullConnectionPool`)
   - `AsyncAmpTransport`
   - `IcapClient` (+ optional `RetryingIcapClient` als public alias auf `IcapClientInterface`)
3. Multi-Client-Unterstützung (`icap_flow.clients.<name>`), damit Behörden-/Shop-Setups mehrere ICAP-Services sauber trennen können.

### 6.3 Symfony-7.4-Fit (praktische Integrationspunkte)

- **Monolog Channel**: `icap` (Bundle-seitig) für saubere Trennung operativer Scan-Logs.
- **Cache-Integration**: PSR-6 Bridge zu `cache.app` als Default-OPTIONS-Cache mit klarer Doku zu Konsistenztrade-offs.
- **Health/Readiness Probe**: `icap:options` oder interner Probe-Service für Operations.
- **Messenger-Szenario**: Best-Practice-Recipe für Async-File-Scan-Pipelines ergänzen.

**Bewertung:** Nicht-Core-Themen; sollten bewusst im Bundle statt in der Library selbst landen.

### 6.4 Observability-Lage (OTel/Profiler/Metrics)

- Aktuell ist PSR-3-Logging solide, aber Tracing/Metrics fehlen als First-Party-Bausteine.
- Für öffentliche Träger mit wachsender OTel-Adoption wäre ein optionaler Decorator sinnvoll (`trace span per request`, `status/infected tags`, latency histograms).

**Empfehlung (P1/P2):** In v3.1 zuerst einen kleinen Observability-Decorator liefern; Symfony-DataCollector kann danach im Bundle folgen.

### 6.5 Ökosystem-Fit Entscheidung

- **Stark:** Core-Design, DI-Tauglichkeit, Fehler-/Statusmodell, transportnahe Kapselung.
- **Fehlt:** Offizielles Bundle, standardisierte Symfony-Recipes, OTel/Metrics-Erweiterung.
- **Konsequenz:** Kein Core-Redesign nötig; der nächste Hebel ist klar **Produktisierung im Ökosystem**.
