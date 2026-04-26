# Plan: Nächste Todos aus den v2.1-Reviews ableiten

## Context

`docs/review/review_v2-1/` (Commit `84e5a99` auf `main`) enthält vier
unabhängige Deep-Research-Audits zu icap-flow v2.1.0:

- `claude_deep-research-audit-icap-flow-v2.1.0.md`
- `codex_deep-research-v2.1.0-audit.md`
- `codex_v2.1.0-production-readiness-audit-2026-04-25.md`
- `jules_deep_research_report_2-1.md`

Die vier Reviewer landen mit Scores zwischen 74 und 77/100 bei TRL-7-Konsens.
v2.1.0 ist „mit Einschränkungen produktionstauglich" für Single-Tenant — aber
Multi-Tenant ist durch die Pool-Key-TLS-Kollision **blockiert**, und die
Default-Preview-Strategie verschluckt Virus-Treffer als uncatchable Exception.
Beide Punkte rechtfertigen einen schnellen v2.1.1-Hotfix vor allem anderen.

Diese Plan-Datei sortiert die in der Konsolidierung identifizierten Findings
nach Release-Milestone, ordnet jeden Punkt seinen Reviewer-Quellen zu und
nennt die kritischen Dateien.

## Entscheidungen (aus Klärungsdialog)

1. **Release-Kadenz**: v2.1.1 zuerst (Critical-Hotfix), v2.1.2 für CI-Quality,
   v2.2.0 als additives Minor, v2.3.0 in separatem Bundle-Repo,
   v3.0.0 sammelt BC-Breaks.
2. **`options()`-Korrektur**: BC-Break direkt — `options()` wechselt zu
   `Future<IcapResponse>` in v3.0.0. Kein additives `optionsRaw()` als
   Brücke. Das verschiebt **Items, die auf `optionsRaw()` aufbauten**
   (Preview-Size-Negotiation, Max-Connections-Auto-Tune) auf einen internen
   Pfad in v2.2 (lib-intern via OPTIONS-Cache, nicht über `optionsRaw()`).
3. **Symfony-Bundle**: separates Repo `ndrstmr/icap-flow-bundle`,
   eigener Release-Zyklus, keine Monorepo-Aufnahme.
4. **Deliverable nach Plan-Approval**: (c) GitHub-Issues für jeden
   P0/P1-Punkt erstellen **und** (a) `consolidated_v2.1_task-list.md`
   im Repo anlegen.

## Korrekturen gegenüber Roh-Synthese

- **Strict-§4.5-Streaming-Bug ist 3/4, nicht 4/4** (Jules hat ihn nicht
  erfasst).
- **Jules' Header-Array-Validation-Befund ist faktisch falsch** —
  `IcapClient::validateIcapHeaders()` Z. 606-612 iteriert per
  `foreach ((array) $value as $v)` über jeden Array-Eintrag. **Nicht
  in den Plan aufnehmen.**
- **Konkrete Coverage-Hotspots aus Jules** (Gesamt 82.4 %):
  - `AmpConnectionPool` 54 %
  - `SynchronousStreamTransport` 41 %
  - Async-Socket-Error-Handling 63 %
  → liefern messbares Coverage-Gate für v2.2.

---

## v2.1.1 — Critical Hotfix (2-3 Tage, kein BC-Break)

Zweck: Sicherheits- und Korrektheits-Regressionen schließen, die im Feld
heute schon Schaden anrichten können. Alle Items konsens-relevant oder
eindeutige Bugs.

| # | Sev | Titel | Datei(en) | Quelle |
|---|---|---|---|---|
| 1 | P0 | SECURITY.md Z. 73-75 aktualisieren — Cache/Pool/Retry sind seit v2.0/2.1 implementiert | `SECURITY.md:73-75` | Claude, Jules |
| 2 | P0 | `AmpConnectionPool::key()` um TLS-Context-Fingerprint erweitern (Übergang: `spl_object_hash($tls)`; deterministischer Hash später in v2.2) + Cross-TLS-Isolation-Test | `src/Transport/AmpConnectionPool.php:130-133`, `tests/Transport/AmpConnectionPoolTest.php` | 4/4 |
| 3 | P0 | `DefaultPreviewStrategy` für 200/206 erweitern: Virus-Header → `ABORT_INFECTED`, sonst `ABORT_CLEAN`. Macht den toten Branch in `IcapClient.php:388` erreichbar | `src/DefaultPreviewStrategy.php:35-42`, `tests/DefaultPreviewStrategyTest.php` | Claude, Codex, Jules |
| 4 | P1 | Phpdoc-Verweis auf `NullConnectionPool` korrigieren (Klasse erst in v2.2 anlegen, hier nur Phpdoc-Drop) | `src/Transport/ConnectionPoolInterface.php:36` | Codex |
| 5 | P1 | Cookbook `03-options-request.php` Z. 11-13: „next milestone after v2.0.0"-Stale-Claim entfernen | `examples/cookbook/03-options-request.php` | Claude |
| 6 | P2 | Cookbook `02-custom-preview-strategy.php`: McAfee-Strategy korrigieren (200-Verhalten + Virus-Header-Inspektion zeigen, Caveat zum Anti-Pattern ergänzen) | `examples/cookbook/02-custom-preview-strategy.php` | Claude |

**Abhängigkeiten:** Item 2 und Cross-TLS-Test im selben PR. Item 3 inkl. Test
für `200 + X-Virus-Name` Antwort.

**Release-Notes:** Security-Advisory für Item 2 (Cross-Tenant-Leakage,
CVE-würdig für Multi-Tenant-Deployments), Bug-Fix-Note für Item 3.

---

## v2.1.2 — CI-Quality-Patch (1 Woche, kein BC-Break)

Zweck: Streaming-Bug + CI-Hygiene; bewusst getrennt von v2.1.1, damit der
Hotfix klein und reviewbar bleibt.

| # | Sev | Titel | Datei(en) | Quelle |
|---|---|---|---|---|
| 7 | P1 | Strict-§4.5-Streaming-Fix: `stream_get_contents()` durch `ChunkedBodyEncoder::encodeRemainderFromStream($stream)` ersetzen + Memory-Watermark-Test | `src/IcapClient.php:399`, `src/ChunkedBodyEncoder.php`, `tests/PreviewContinueStrictTest.php` | Claude, Codex×2 (3/4) |
| 8 | P2 | 3 risky Tests im Unit-Run entkernen, dann `failOnRisky=true` + `failOnWarning=true` | `phpunit.xml.dist` | Codex-PR |
| 9 | P2 | PHPStan CI Memory-Limit-Stabilisierung (`composer stan` mit `--memory-limit=1G`) | `composer.json`, `.github/workflows/ci.yml:43` | Codex-DR |

---

## v2.2.0 — Minor (4-6 Wochen, additiv)

Zweck: Funktionale Lücken schließen, Observability + Test-Reife heben.
Alle Items additiv (kein BC-Break).

### OPTIONS-getriebene Pool-/Preview-Tuning (intern, ohne API-Bruch)

| # | Sev | Titel | Datei(en) | Quelle |
|---|---|---|---|---|
| 10 | P1 | OPTIONS-driven Preview-Size: `scanFileWithPreview()` ohne `$previewSize` befragt OPTIONS-Cache und nutzt Server-`Preview`-Header | `src/IcapClient.php:269-322`, `src/Cache/InMemoryOptionsCache.php` | 4/4 |
| 11 | P1 | Max-Connections aus OPTIONS für Pool-Cap: `effectiveCap = min(localCap, serverMaxConnections)` (intern, opt-in via `Config::autoTunePoolFromOptions`) | `src/Transport/AmpConnectionPool.php:106-110`, `src/Config.php` | 4/4 |
| 12 | P2 | OPTIONS-Cache ISTag-Invalidation (`OptionsCacheInterface` um `?string $istag` erweitern) | `src/Cache/InMemoryOptionsCache.php`, `src/Cache/OptionsCacheInterface.php` | Claude |
| 13 | P2 | PSR-20 `ClockInterface` in `InMemoryOptionsCache` (deterministische TTL-Tests) | `src/Cache/InMemoryOptionsCache.php` | Claude |
| 14 | P2 | `NullConnectionPool` jetzt anlegen (für explizite Pool-Off-Konfigs + Tests) | `src/Transport/NullConnectionPool.php` (neu) | Codex |
| 15 | P2 | PSR-6/16 OPTIONS-Cache-Adapter als Optional-Deps | `src/Cache/Psr16OptionsCache.php`, `src/Cache/Psr6OptionsCache.php` | 3/4 |

### CI-Härtung & Test-Reife

| # | Sev | Titel | Datei(en) | Quelle |
|---|---|---|---|---|
| 16 | P1 | Mutation-Testing wieder als CI-Job (`composer mutation`, `--min=65`, kein `continue-on-error`) | `.github/workflows/ci.yml:130-135` | 4/4 |
| 17 | P1 | Integration-CI aus `continue-on-error: true` herausführen (hartes Gate **oder** Nightly-Cron mit Required-Status) | `.github/workflows/ci.yml:63` | 3/4 |
| 18 | P1 | Coverage-Push: `AmpConnectionPool` 54 → ≥90 %, `SynchronousStreamTransport` 41 → ≥85 %, Async-Socket-Error 63 → ≥85 %. Konkrete Fälle: Cross-TLS-Pool-Isolation (bereits in v2.1.1), Concurrent-Acquire-Race (Fiber), `0; ieof`-Recv-Pfad, Multi-Section-Encapsulated, Cancellation mid-write/mid-read/Composite, `Options-TTL=0`, `SynchronousIcapClient::scanFileWithPreview`, Logger-Sensitive-Header-Regression | `tests/Transport/`, `tests/Wire/`, `tests/CancellationTest.php`, `tests/SynchronousIcapClientTest.php`, `tests/LoggerIntegrationTest.php`, `tests/OptionsCacheTest.php` | Jules + Claude + Codex |

### Korrektheit & Robustheit

| # | Sev | Titel | Datei(en) | Quelle |
|---|---|---|---|---|
| 19 | P2 | Strict-§4.5-Path: per-IO-Timeout-Reset statt Session-Lifetime-Timeout (oder Caveat dokumentieren) | `src/Transport/AsyncAmpTransport.php:111-114` | Claude |
| 20 | P2 | Pool-Idle-Eviction mit `maxIdleAge` (Default 30 s) | `src/Transport/AmpConnectionPool.php:42-46` | 4/4 |
| 21 | P2 | obs-fold (RFC 7230) im Encapsulated-Header beachten | `src/Transport/ResponseFrameReader.php:144-153` | Claude, Codex |
| 22 | P2 | Header-Name-Validation strenger auf RFC-7230-§3.2.6-Token-Set | `src/IcapClient.php:601` | Claude |

### Doku & Tooling

| # | Sev | Titel | Datei(en) | Quelle |
|---|---|---|---|---|
| 23 | P1 | 4 neue Cookbook-Files: TLS/mTLS, RetryingIcapClient, Pool-Tuning, External-Cancellation | `examples/cookbook/04-tls-mtls.php`, `…/05-retry-decorator.php`, `…/06-pool-tuning.php`, `…/07-cancellation-from-upload.php` | Claude, Codex |
| 24 | P2 | Connection-Pool im Config-Block des README dokumentieren | `README.md:89-129` | Claude |
| 25 | P2 | `docs/compliance.md` mit BSI OPS.1.1.4 / APP.4.4 / DSGVO-Mapping + AI-Disclaimer | `docs/compliance.md` (neu) | Claude |
| 26 | P2 | DSGVO/Logging-Caveat in `SECURITY.md` und Logger-Phpdoc | `SECURITY.md`, `src/IcapClient.php` (Logger-Doku) | Jules |
| 27 | P2 | CONTRIBUTING.md um Conventional-Commits-Konvention ergänzen | `CONTRIBUTING.md` | Claude |
| 28 | P2 | OpenTelemetry-Decorator als Optional-Dep auf `open-telemetry/api` | `src/Tracing/OtelTracingIcapClient.php` (neu) | 4/4 |
| 29 | P2 | PHPBench-Suite: Pool-Throughput, Strict-§4.5-Latency, Chunked-Encoder | `benchmarks/` (neu) | 3/4 |
| 30 | P2 | SBOM (CycloneDX/SPDX) in CI | `.github/workflows/ci.yml` | Claude |
| 31 | P3 | Property-based Tests für `ResponseParser` / `ResponseFrameReader` | `tests/Property/` (neu) | 3/4 |
| 32 | P3 | Fuzz-Korpus für Parser | `tests/Fuzz/` (neu) | Claude |
| 33 | P2 | `IcapResponseException`: PHP 8.4 `#[\Deprecated]` mit konkretem v3.0.0-Removal-Tag | `src/Exception/IcapResponseException.php:28-32` | Claude, Codex |

---

## v2.3.0 — Symfony-Bundle (8-12 Wochen, separates Repo)

Eigenes Repo `ndrstmr/icap-flow-bundle`, abhängig auf `^2.2`. Nicht Teil
dieses Plans im Detail; Initial-Scope:

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

| # | Titel | Datei(en) | Quelle |
|---|---|---|---|
| 34 | `IcapClient::executeRaw()` → `protected` ODER ins Interface heben | `src/IcapClient.php:144`, `src/IcapClientInterface.php` | Claude, Codex |
| 35 | `options()` direkt zu `Future<IcapResponse>` umstellen (entsprechend Entscheidung 2) | `src/IcapClient.php:157`, `src/IcapClientInterface.php`, `src/SynchronousIcapClient.php` | Claude, Codex |
| 36 | `IcapResponseException` entfernen (Deprecation einlösen) | `src/Exception/IcapResponseException.php` + Call-Sites | Claude, Codex |

Trigger: erst wenn mind. zwei davon durch User-Feedback erzwungen werden.

---

## Abhängigkeitskarte

- **v2.1.1 #2 ↔ Cross-TLS-Test**: gleicher PR.
- **v2.1.2 #7 ↔ Memory-Watermark-Test**: gleicher PR; läuft im CI unter
  niedrigem `memory_limit`.
- **v2.2 #10 + #11**: bauen auf bestehendem `OptionsCacheInterface` auf
  (kein `optionsRaw()` nötig dank Entscheidung 2).
- **v2.2 #12 ↔ #13**: zusammen mergen, sonst keine deterministische
  ISTag-Test-Fixture.
- **v2.2 #14**: blockiert keine anderen Items, entkoppelt das Phpdoc-Versprechen
  aus v2.1.1.
- **v2.2 #16** (Mutation-CI) sollte früh im v2.2-Zyklus landen, damit
  Folge-PRs davon profitieren.
- **v2.3 (Bundle)**: vollständig blockiert auf v2.2-Release (insbesondere
  #14 NullConnectionPool und #28 Otel).

---

## Kritische Dateien (Implementierungs-Hotspots)

- `src/Transport/AmpConnectionPool.php` — Pool-Key, Idle-Eviction, Max-Connections
- `src/IcapClient.php` — Strict-§4.5-Path, OPTIONS-driven Preview-Size, Header-Validation
- `src/DefaultPreviewStrategy.php` — 200/206-Branch
- `src/Cache/InMemoryOptionsCache.php` — ISTag, PSR-20 Clock
- `src/Transport/ConnectionPoolInterface.php` — Phpdoc, später `NullConnectionPool`
- `src/Transport/AsyncAmpTransport.php` — Per-IO-Timeout
- `src/Transport/ResponseFrameReader.php` — obs-fold
- `src/Exception/IcapResponseException.php` — Deprecation-Status
- `SECURITY.md` — Stale-Claims (v2.1.1 P0)
- `examples/cookbook/02-…`, `…03-…` — Anti-Pattern-Korrektur
- `.github/workflows/ci.yml` — Mutation-Job, Integration-Gate, SBOM

---

## Wiederverwendung bestehender Bausteine

- `OptionsCacheInterface` (`src/Cache/OptionsCacheInterface.php`) ist bereits
  vorhanden und reicht für #10 + #11; `InMemoryOptionsCache` muss nur um Clock
  und ISTag-Param erweitert werden.
- `ChunkedBodyEncoder` (`src/ChunkedBodyEncoder.php`) hat bereits einen
  `encode(string)` — die Streaming-Variante `encodeRemainderFromStream($resource)`
  passt direkt daneben (Referenzpfad ist der existierende
  `scanFileWithPreviewLegacy` in `IcapClient.php:426ff`, der schon korrekt
  per `rewind() + resource` arbeitet).
- Logger-Sensitive-Header-Logik existiert bereits — der fehlende Test
  (#18) ist reine Regressions-Absicherung, kein neues Verhalten.

---

## Deliverables nach Plan-Approval

1. **(c) GitHub-Issues** für jeden P0/P1-Punkt (insgesamt 11 Items: v2.1.1
   #1-3, v2.1.2 #7, v2.2 #10-11, #16-18, #23, plus #4 als "good first issue").
   Pro Issue: Reviewer-Quellen-Tabelle, Datei-Verweise, Akzeptanzkriterien,
   Milestone-Label (`v2.1.1`, `v2.1.2`, `v2.2.0`).
2. **(a) `docs/review/review_v2-1/consolidated_v2.1_task-list.md`** im Stil der
   bestehenden `consolidated_task-list.md` aus v2.0. Inhalt: diese Plan-Datei
   in Repo-tauglicher Form, ohne den Plan-Mode-Vorspann.

Beide Deliverables erfolgen auf dem Branch `claude/review-v2-1-feedback-k5kRX`
(GitHub-Issues sind Repo-weit, der Branch hostet die Doku).

---

## Verification

Nach v2.1.1-Release:

- `composer test` grün, neuer `AmpConnectionPoolTest::testCrossTlsIsolation`
  schlägt **vor** dem Pool-Key-Fix fehl, **nach** dem Fix grün.
- `DefaultPreviewStrategyTest::testInfectedDuringPreview` (mit fixiertem
  `200 + X-Virus-Name: Eicar-Test`) liefert `ABORT_INFECTED`.
- `SECURITY.md` Z. 73-75 referenziert `RetryingIcapClient`, `InMemoryOptionsCache`
  und `AmpConnectionPool` als vorhandene Komponenten.
- `examples/cookbook/03-options-request.php` enthält keinen "next milestone"-Text.
- Manuell: ein Lauf gegen `docker compose up icap-clamav` mit dem
  bekannten Eicar-Sample im 1-KiB-Preview liefert `ScanResult(isInfected=true)`
  statt `IcapResponseException`.

Nach v2.1.2-Release:

- Memory-Watermark-Test mit 2-GB-Stream und `memory_limit=128M` (per
  `php -d memory_limit=128M`) muss durchlaufen.
- `composer test` mit `failOnRisky=true` + `failOnWarning=true` grün.
- CI-PHPStan-Job läuft ohne OOM.

Nach v2.2-Release:

- `composer mutation --min=65` als Required-Status auf PR-Branches.
- Coverage-Report bestätigt `AmpConnectionPool` ≥ 90 %, `SynchronousStreamTransport`
  ≥ 85 %, Async-Socket-Error ≥ 85 %.
- Integration-CI ist Required-Status (oder Nightly mit Issue-on-Failure).
- `phpbench run benchmarks/ --report=default` liefert Baseline-Zahlen.
