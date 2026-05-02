# Konsolidierte Task-Liste — v2.1.x / v2.2.0 Follow-ups

> **Status (02.05.2026):** Vier unabhängige Deep-Research-Audits zu v2.1.0 liegen vor (`docs/review/review_v2-1/`). Diese Liste leitet daraus die nächsten Releases ab.
>
> **Reviewer-Konsens:** Scores 74–77/100, TRL-7. Single-Tenant produktionstauglich; Multi-Tenant durch Pool-Key-TLS-Kollision blockiert; Default-Preview-Strategie verschluckt Virus-Treffer als uncatchable Exception → schneller v2.1.1-Hotfix nötig.
>
> | Quelle | Datei |
> |---|---|
> | Claude Deep Research | `claude_deep-research-audit-icap-flow-v2.1.0.md` |
> | Codex Deep Research | `codex_deep-research-v2.1.0-audit.md` |
> | Codex Production-Readiness | `codex_v2.1.0-production-readiness-audit-2026-04-25.md` |
> | Jules Deep Research | `jules_deep_research_report_2-1.md` |

**Ziel:** v2.1.x als Hotfix-Linie ausrollen, v2.2.0 mit additiven Features, v2.3.0 als separates `icap-flow-bundle`-Repo, v3.0.0 sammelt BC-Breaks.

**Strategie-Entscheidungen:**

1. **v2.1.1 zuerst** — kleiner Hotfix für die drei P0-Themen, danach v2.1.2 für CI-Quality. Kein Bündeln in v2.2.0.
2. **`options()`-Korrektur ist BC-Break** und wandert nach v3.0.0. Kein additives `optionsRaw()` als Brücke. OPTIONS-driven Preview-Size + Max-Connections-Auto-Tune in v2.2 nutzen den **internen** OPTIONS-Cache, nicht die Public-API.
3. **Symfony-Bundle in eigenem Repo** `ndrstmr/icap-flow-bundle` mit eigenem Release-Zyklus.

**Korrekturen gegenüber den Roh-Reviews:**

- **Strict-§4.5-Streaming-Bug** ist 3/4 Reviewer (nicht 4/4 — Jules hat ihn nicht erfasst).
- **Jules' Header-Array-Validation-Befund** ist faktisch falsch: `IcapClient::validateIcapHeaders()` Z. 606-612 iteriert per `foreach ((array) $value as $v)` über jeden Eintrag. **Nicht in den Plan aufgenommen.**
- **Coverage-Hotspots aus Jules** (Gesamt 82.4 %): `AmpConnectionPool` 54 %, `SynchronousStreamTransport` 41 %, Async-Socket-Error 63 % → messbares v2.2-Gate.

---

## Reviewer-Konsens-Matrix

| Finding | Claude | Codex-DR | Codex-PR | Jules | Konsens |
|---|---|---|---|---|---|
| Pool-Key TLS-Context-Confusion | P0/P1 | P0 | P1 (pot. P0) | P0 | **4/4 P0** |
| Strict-Preview `stream_get_contents`-Streaming-Bug | P1 | P1 | P1 | — | 3/4 P1 |
| OPTIONS-driven Preview-Size | P1 | P1 | P1 | P1 | **4/4 P1** |
| OPTIONS-driven Max-Connections für Pool-Cap | P1 | P1 | P1 | P1 | **4/4 P1** |
| Mutation-Testing wieder in CI | P1 | P1 | P1 | P1 | **4/4 P1** |
| Symfony-Bundle (`icap-flow-bundle`) | P1 (v2.3) | P1 | P1 | P1 | **4/4 P1** |
| Integration-CI `continue-on-error: true` weg | P1 | P1 | P1 | — | 3/4 P1 |
| OpenTelemetry-Decorator | P2 | P2 | P2 | P2 | 4/4 P2 |
| Pool-Idle-Eviction (`maxIdleAge`) | P2 | P2 | P2 | P2 | 4/4 P2 |
| PSR-6/16 Cache-Adapter | P2 | — | P2 | P2 | 3/4 P2 |
| Property-based + Fuzz-Tests für Parser/Framer | P3 | P2 | P2 | — | 3/4 P2 |
| PHPBench-Suite | P3 | P2 | P2 | — | 3/4 P2 |

### Unique Findings (nur ein Reviewer)

**Nur Claude:**

- SECURITY.md Z. 73-75 v2.0-stale (behauptet Cache/Pool/Retry sind nicht da). **P0 Doku.**
- DefaultPreviewStrategy wirft auf 200/206 in Preview statt `ABORT_INFECTED` zu liefern. ABORT_INFECTED-Pfad in `IcapClient.php:388` aus der Default-Strategie unerreichbar. **P0/P1 Bug.**
- `ConnectionPoolInterface.php:36` referenziert nicht-existente `NullConnectionPool`. **P1 Doku.**
- `IcapResponseException` `@deprecated since 2.0`, removal in M2 — aber M2 ist released und Klasse im Hot-Path. **P1 Inkonsistenz.**
- `IcapClient::executeRaw()` ist `public` aber nicht im Interface. **P1 API.**
- `options() : Future<ScanResult>` API-Smell. **P1 API.**
- Cookbook `02`/`03` Stale-Claims & Anti-Pattern. **P2 Doku.**
- 4 fehlende Cookbook-Files (TLS/mTLS, Retry, Pool, Cancellation). **P1 Doku.**
- PSR-20 `ClockInterface` für `InMemoryOptionsCache`. **P2 Tooling.**
- Strict-§4.5-Path: Session-Lifetime-Timeout statt per-IO. **P1 Korrektheit.**

**Nur Codex-PR:** `phpunit.xml.dist` `failOnRisky=false` + 3 risky Tests. **P2 Tooling.**

**Nur Codex-DR:** PHPStan CI Memory-Limit Stabilisierung. **P2 Tooling.**

**Nur Jules:** Konkrete Coverage-Zahlen + Hotspots (siehe oben).

---

## v2.1.1 — Critical Hotfix (2-3 Tage, kein BC-Break)

**Aufwand:** ≤ 1 PT
**PR:** `fix(v2.1.1): pool-key TLS isolation, preview-strategy 200/206, stale SECURITY.md`

| ID | Sev | Item | Datei(en) | Quelle |
|---|---|---|---|---|
| **2.1.1-A** | **P0** | SECURITY.md Z. 73-75 aktualisieren — Cache/Pool/Retry sind seit v2.0/2.1 implementiert | `SECURITY.md:73-75` | Claude, Jules |
| **2.1.1-B** | **P0** | `AmpConnectionPool::key()` um TLS-Context-Fingerprint erweitern (Übergang `spl_object_hash($tls)`; deterministischer Hash später in v2.2) + Cross-TLS-Isolation-Test | `src/Transport/AmpConnectionPool.php:130-133`, `tests/Transport/AmpConnectionPoolTest.php` | **4/4** |
| **2.1.1-C** | **P0** | `DefaultPreviewStrategy` für 200/206 erweitern: Virus-Header → `ABORT_INFECTED`, sonst `ABORT_CLEAN`. Macht den toten Branch in `IcapClient.php:388` erreichbar | `src/DefaultPreviewStrategy.php:35-42`, `tests/DefaultPreviewStrategyTest.php` | Claude, Codex, Jules |
| **2.1.1-D** | P1 | Phpdoc-Verweis auf `NullConnectionPool` korrigieren (Klasse erst in v2.2) | `src/Transport/ConnectionPoolInterface.php:36` | Codex |
| **2.1.1-E** | P1 | Cookbook `03-options-request.php` Z. 11-13 Stale-Claim entfernen | `examples/cookbook/03-options-request.php` | Claude |
| **2.1.1-F** | P2 | Cookbook `02-custom-preview-strategy.php` McAfee-Strategy korrigieren | `examples/cookbook/02-custom-preview-strategy.php` | Claude |

**Abhängigkeiten:** B + Cross-TLS-Test im selben PR. C inkl. Test für `200 + X-Virus-Name`.

**Release-Notes:** Security-Advisory für B (Cross-Tenant-Leakage, CVE-würdig in Multi-Tenant), Bug-Fix-Note für C.

---

## v2.1.2 — CI-Quality-Patch (1 Woche, kein BC-Break)

**Aufwand:** 2-3 PT
**PR:** `fix(v2.1.2): strict §4.5 streaming, phpunit strict mode, phpstan memory`

| ID | Sev | Item | Datei(en) | Quelle |
|---|---|---|---|---|
| **2.1.2-A** | P1 | Strict-§4.5-Streaming-Fix: `stream_get_contents()` durch `ChunkedBodyEncoder::encodeRemainderFromStream($stream)` ersetzen + Memory-Watermark-Test | `src/IcapClient.php:399`, `src/ChunkedBodyEncoder.php`, `tests/PreviewContinueStrictTest.php` | Claude, Codex×2 (3/4) |
| **2.1.2-B** | P2 | 3 risky Tests entkernen, dann `failOnRisky=true` + `failOnWarning=true` | `phpunit.xml.dist` | Codex-PR |
| **2.1.2-C** | P2 | PHPStan CI Memory-Limit (`composer stan` mit `--memory-limit=1G`) | `composer.json`, `.github/workflows/ci.yml:43` | Codex-DR |

---

## v2.2.0 — Minor (4-6 Wochen, additiv)

**Aufwand:** 8-12 PT
**Strategie:** OPTIONS-getriebene Pool-/Preview-Tuning intern via `OptionsCacheInterface` (kein `optionsRaw()` nötig).

### M2.2-A — OPTIONS-driven Tuning

| ID | Sev | Item | Datei(en) | Quelle |
|---|---|---|---|---|
| **2.2-A1** | **P1** | OPTIONS-driven Preview-Size: `scanFileWithPreview()` ohne `$previewSize` befragt OPTIONS-Cache | `src/IcapClient.php:269-322`, `src/Cache/InMemoryOptionsCache.php` | **4/4** |
| **2.2-A2** | **P1** | Max-Connections aus OPTIONS für Pool-Cap (`effectiveCap = min(localCap, serverMaxConnections)`), opt-in via `Config::autoTunePoolFromOptions` | `src/Transport/AmpConnectionPool.php:106-110`, `src/Config.php` | **4/4** |
| **2.2-A3** | P2 | OPTIONS-Cache ISTag-Invalidation (`OptionsCacheInterface` um `?string $istag` erweitern) | `src/Cache/InMemoryOptionsCache.php`, `src/Cache/OptionsCacheInterface.php` | Claude |
| **2.2-A4** | P2 | PSR-20 `ClockInterface` in `InMemoryOptionsCache` | `src/Cache/InMemoryOptionsCache.php` | Claude |
| **2.2-A5** | P2 | `NullConnectionPool` jetzt anlegen (für Pool-Off-Konfigs + Tests) | `src/Transport/NullConnectionPool.php` (neu) | Codex |
| **2.2-A6** | P2 | PSR-6/16 OPTIONS-Cache-Adapter als Optional-Deps | `src/Cache/Psr16OptionsCache.php`, `src/Cache/Psr6OptionsCache.php` (neu) | 3/4 |

### M2.2-B — CI-Härtung & Test-Reife

| ID | Sev | Item | Datei(en) | Quelle |
|---|---|---|---|---|
| **2.2-B1** | **P1** | Mutation-Testing wieder als CI-Job (`composer mutation`, `--min=65`, kein `continue-on-error`) | `.github/workflows/ci.yml:130-135` | **4/4** |
| **2.2-B2** | **P1** | Integration-CI aus `continue-on-error: true` (hartes Gate **oder** Nightly-Cron + Required-Status) | `.github/workflows/ci.yml:63` | 3/4 |
| **2.2-B3** | **P1** | Coverage-Push: `AmpConnectionPool` 54 → ≥90 %, `SynchronousStreamTransport` 41 → ≥85 %, Async-Socket-Error 63 → ≥85 %. Konkrete Fälle: Concurrent-Acquire-Race (Fiber), `0; ieof`-Recv, Multi-Section-Encapsulated, Cancellation mid-write/mid-read/Composite, `Options-TTL=0`, `SynchronousIcapClient::scanFileWithPreview`, Logger-Sensitive-Header-Regression | `tests/Transport/`, `tests/Wire/`, `tests/CancellationTest.php`, `tests/SynchronousIcapClientTest.php`, `tests/LoggerIntegrationTest.php`, `tests/OptionsCacheTest.php` | Jules + Claude + Codex |

### M2.2-C — Korrektheit & Robustheit

| ID | Sev | Item | Datei(en) | Quelle |
|---|---|---|---|---|
| **2.2-C1** | P2 | Strict-§4.5-Path: per-IO-Timeout-Reset statt Session-Lifetime | `src/Transport/AsyncAmpTransport.php:111-114` | Claude |
| **2.2-C2** | P2 | Pool-Idle-Eviction mit `maxIdleAge` (Default 30 s) | `src/Transport/AmpConnectionPool.php:42-46` | 4/4 |
| **2.2-C3** | P2 | obs-fold (RFC 7230) im Encapsulated-Header | `src/Transport/ResponseFrameReader.php:144-153` | Claude, Codex |
| **2.2-C4** | P2 | Header-Name-Validation strenger (RFC-7230-§3.2.6-Token-Set) | `src/IcapClient.php:601` | Claude |

### M2.2-D — Doku & Tooling

| ID | Sev | Item | Datei(en) | Quelle |
|---|---|---|---|---|
| **2.2-D1** | **P1** | 4 neue Cookbook-Files: TLS/mTLS, Retry, Pool-Tuning, Cancellation | `examples/cookbook/04-tls-mtls.php`, `…/05-retry-decorator.php`, `…/06-pool-tuning.php`, `…/07-cancellation-from-upload.php` | Claude, Codex |
| **2.2-D2** | P2 | Connection-Pool im README dokumentieren | `README.md:89-129` | Claude |
| **2.2-D3** | P2 | `docs/compliance.md` mit BSI OPS.1.1.4 / APP.4.4 / DSGVO-Mapping | `docs/compliance.md` (neu) | Claude |
| **2.2-D4** | P2 | DSGVO/Logging-Caveat in `SECURITY.md` und Logger-Phpdoc | `SECURITY.md`, `src/IcapClient.php` | Jules |
| **2.2-D5** | P2 | CONTRIBUTING.md um Conventional-Commits ergänzen | `CONTRIBUTING.md` | Claude |
| **2.2-D6** | P2 | OpenTelemetry-Decorator als Optional-Dep | `src/Tracing/OtelTracingIcapClient.php` (neu) | 4/4 |
| **2.2-D7** | P2 | PHPBench-Suite (Pool-Throughput, Strict-§4.5-Latency, Chunked-Encoder) | `benchmarks/` (neu) | 3/4 |
| **2.2-D8** | P2 | SBOM (CycloneDX/SPDX) in CI | `.github/workflows/ci.yml` | Claude |
| **2.2-D9** | P3 | Property-based Tests für Parser/Framer | `tests/Property/` (neu) | 3/4 |
| **2.2-D10** | P3 | Fuzz-Korpus für Parser | `tests/Fuzz/` (neu) | Claude |
| **2.2-D11** | P2 | `IcapResponseException` PHP 8.4 `#[\Deprecated]` mit konkretem v3.0.0-Removal-Tag | `src/Exception/IcapResponseException.php:28-32` | Claude, Codex |

---

## v2.3.0 — Symfony-Bundle (8-12 Wochen, separates Repo)

Neues Repo `ndrstmr/icap-flow-bundle`, abhängig auf `^2.2`. Initial-Scope:

- `IcapFlowBundle` mit Configuration-Tree + `IcapFlowExtension`
- Auto-DI: `IcapClientInterface` → `RetryingIcapClient` → inner `IcapClient`
- Tagged-Services für Multi-Client (`icap_flow.client.<name>`)
- Symfony Profiler DataCollector
- Monolog-Channel `icap`
- Console-Commands `icap:scan`, `icap:options`, `icap:health`
- Validator-Constraint `#[IcapClean]`
- Flex-Recipe für `symfony/recipes-contrib`
- Adapter für VichUploaderBundle / OneupUploaderBundle

Quelle: 4/4 Reviewer.

---

## v3.0.0 — Major (BC-Breaks gesammelt, nur falls erforderlich)

| ID | Item | Datei(en) | Quelle |
|---|---|---|---|
| **3.0-A** | `IcapClient::executeRaw()` → `protected` ODER ins Interface heben | `src/IcapClient.php:144`, `src/IcapClientInterface.php` | Claude, Codex |
| **3.0-B** | `options()` direkt zu `Future<IcapResponse>` umstellen | `src/IcapClient.php:157`, `src/IcapClientInterface.php`, `src/SynchronousIcapClient.php` | Claude, Codex |
| **3.0-C** | `IcapResponseException` entfernen (Deprecation einlösen) | `src/Exception/IcapResponseException.php` + Call-Sites | Claude, Codex |

Trigger: erst wenn mind. zwei davon durch User-Feedback erzwungen werden.

---

## Abhängigkeitskarte

- **2.1.1-B ↔ Cross-TLS-Test**: gleicher PR.
- **2.1.2-A ↔ Memory-Watermark-Test**: gleicher PR; CI mit niedrigem `memory_limit`.
- **2.2-A1 + A2**: bauen auf bestehendem `OptionsCacheInterface` auf (kein `optionsRaw()` nötig).
- **2.2-A3 ↔ A4**: zusammen mergen für deterministische ISTag-Test-Fixture.
- **2.2-A5**: entkoppelt das Phpdoc-Versprechen aus 2.1.1-D.
- **2.2-B1** (Mutation-CI) früh im v2.2-Zyklus, damit Folge-PRs profitieren.
- **v2.3** (Bundle) blockiert auf v2.2-Release (insbes. A5 NullConnectionPool und D6 Otel).

---

## Verification

**Nach v2.1.1-Release:**

- `composer test` grün; neuer `AmpConnectionPoolTest::testCrossTlsIsolation` schlägt **vor** dem Pool-Key-Fix fehl, **nach** dem Fix grün.
- `DefaultPreviewStrategyTest::testInfectedDuringPreview` (`200 + X-Virus-Name: Eicar-Test`) liefert `ABORT_INFECTED`.
- `SECURITY.md` Z. 73-75 referenziert `RetryingIcapClient`, `InMemoryOptionsCache`, `AmpConnectionPool` als vorhanden.
- Lauf gegen `docker compose up icap-clamav` mit Eicar-Sample im 1-KiB-Preview liefert `ScanResult(isInfected=true)` statt Exception.

**Nach v2.1.2-Release:**

- Memory-Watermark-Test mit 2-GB-Stream und `php -d memory_limit=128M` muss durchlaufen.
- `composer test` mit `failOnRisky=true` + `failOnWarning=true` grün.
- CI-PHPStan-Job ohne OOM.

**Nach v2.2-Release:**

- `composer mutation --min=65` als Required-Status auf PR-Branches.
- Coverage-Report bestätigt `AmpConnectionPool` ≥ 90 %, `SynchronousStreamTransport` ≥ 85 %, Async-Socket-Error ≥ 85 %.
- Integration-CI Required-Status (oder Nightly mit Issue-on-Failure).
- `phpbench run benchmarks/ --report=default` liefert Baseline.
