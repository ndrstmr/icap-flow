# Konsolidierte Task-Liste — v2.1.x / v2.2.0 Findings & Roadmap

> **Basis:** Synthese der vier unabhängigen Deep-Research-Audits zu v2.1.0
> in `docs/review/review_v2-1/` (Claude, Codex ×2, Jules), verifiziert am
> `src/`-Stand bei Commit `84e5a99`.
>
> **Scores (auf 100 normiert):** Claude 74, Codex-DR 77, Codex-PR 77, Jules 74
> — Mittel **76/100, TRL-7-Konsens.**
> v2.1.0 ist produktionstauglich für Single-Tenant; Multi-Tenant ist durch die
> Pool-Key-TLS-Kollision **blockiert** (Finding #2 unten).
>
> **Archiv:** Die ursprüngliche Plan-Export-Version steht in
> `consolidated_v2.1_task-list_plan-export.md`.

---

## Reviewer-Konsens-Matrix

| Finding | Claude | Codex-DR | Codex-PR | Jules | Konsens |
|---|---|---|---|---|---|
| Pool-Key TLS-Context-Collision | P0 | P0 | P1 | P0 | **4/4 P0** |
| DefaultPreviewStrategy wirft auf 200/206 | P0 | P1 | P1 | — | 3/4 P0/P1 |
| Strict-Preview `stream_get_contents` OOM | P1 | P1 | P1 | — | **3/4 P1** |
| OPTIONS-driven Preview-Size-Negotiation | P1 | P1 | P1 | P1 | **4/4 P1** |
| OPTIONS-driven Max-Connections für Pool | P1 | P1 | P1 | P1 | **4/4 P1** |
| Mutation-Testing wieder in CI | P1 | P1 | P1 | P1 | **4/4 P1** |
| Integration-CI `continue-on-error: true` | P1 | P1 | P1 | — | 3/4 P1 |
| OpenTelemetry-Decorator | P2 | P2 | P2 | P2 | **4/4 P2** |
| Pool-Idle-Eviction (max-idle-age) | P2 | P2 | P2 | P2 | **4/4 P2** |
| PSR-6/16 Cache-Adapter | P2 | — | P2 | P2 | 3/4 P2 |
| PHPBench-Suite | P3 | P2 | P2 | — | 3/4 P2 |
| Symfony-Bundle (separates Repo) | P1 | P1 | P1 | P1 | **4/4 P1** |
| SECURITY.md v2.0-stale | P0 | — | — | P0 | 2/4 P0 |
| `ConnectionPoolInterface` Phpdoc → `NullConnectionPool` | P1 | P1 | — | — | 2/4 P1 |
| `IcapResponseException` Deprecation unklar | P1 | P1 | — | — | 2/4 P1 |
| `executeRaw()` public / nicht im Interface | P1 | P1 | — | — | 2/4 P1 |
| `options()` liefert `ScanResult` statt `IcapResponse` | P1 | P1 | — | — | 2/4 P1 |
| `failOnRisky=false` / 3 risky Unit-Tests | — | — | P2 | — | Codex-PR |
| PHPStan CI Memory-Limit | — | P2 | — | — | Codex-DR |

> **Korrektur:** Jules' Befund „Header-Array-Werte werden nicht tief validiert"
> ist **faktisch falsch** — `IcapClient::validateIcapHeaders()` Z. 606-612
> iteriert via `foreach ((array) $value as $v)` über jeden Eintrag.
> Dieser Punkt ist **nicht** in die Task-Liste aufgenommen.

---

## Verifizierte Findings

| ID | Finding | Schweregrad | Code-Nachweis | Quelle |
|---|---|---|---|---|
| A | Pool-Key ignoriert TLS-Context-Unterschiede → Cross-Tenant-Socket-Reuse | Security P0 | `Transport/AmpConnectionPool.php:130-133` | 4/4 |
| B | `DefaultPreviewStrategy` wirft `IcapResponseException` auf 200/206 → ABORT_INFECTED-Branch unerreichbar | Bug P0/P1 | `DefaultPreviewStrategy.php:37-41`, `IcapClient.php:388` | Claude, Codex ×2 |
| C | `SECURITY.md` Z. 73-75 behauptet: kein Retry, kein Cache, kein Pooling — alles seit v2.0/2.1 implementiert | Doku P0 | `SECURITY.md:73-75` | Claude, Jules |
| D | `scanFileWithPreviewStrict()` lädt Body-Remainder via `stream_get_contents()` in RAM → OOM bei GB-Uploads | OOM P1 | `IcapClient.php:399` | Claude, Codex ×2 |
| E | `ConnectionPoolInterface` Phpdoc referenziert nicht-existente `NullConnectionPool`-Klasse | Doku/Code P1 | `Transport/ConnectionPoolInterface.php:36` | Codex |
| F | ~~`IcapResponseException` ist `@deprecated since 2.0, removal in M2` — M2 ist released, Klasse im Hot-Path~~ ✅ PR #79 | Inkonsistenz P1 | `Exception/IcapResponseException.php` | Claude, Codex |
| G | Cookbook `03-options-request.php` Z. 11-13: „next milestone after v2.0.0" — v2.0 ist released | Doku P1 | `examples/cookbook/03-options-request.php:11-13` | Claude |
| H | Cookbook `02-custom-preview-strategy.php`: McAfee-Strategy mappt 200 → `ABORT_CLEAN` (Anti-Pattern, lehrt unsicheres Muster) | Doku P2 | `examples/cookbook/02-custom-preview-strategy.php` | Claude |
| I | `phpunit.xml.dist`: `failOnRisky=false` + `failOnWarning=false`; 3 risky Tests im Unit-Run | CI P2 | `phpunit.xml.dist` | Codex-PR |
| J | PHPStan-CI setzt kein `--memory-limit` → lokale OOM möglich | Tooling P2 | `.github/workflows/ci.yml:43` | Codex-DR |
| K | OPTIONS-Cache `Preview`-Header wird nicht genutzt → Caller muss `$previewSize` manuell setzen | RFC P1 | `IcapClient.php:269-322` | 4/4 |
| L | OPTIONS-Cache `Max-Connections` wird nicht für Pool-Cap genutzt | RFC P1 | `Transport/AmpConnectionPool.php:106-110` | 4/4 |
| M | Mutation-Testing-Job aus CI entfernt — kein MSI-Gate auf PRs | Testing P1 | `.github/workflows/ci.yml:130-135` | 4/4 |
| N | Integration-CI `continue-on-error: true` → Wire-Format-Regressionen bleiben unerkannt | CI P1 | `.github/workflows/ci.yml:63` | 3/4 |
| O | Coverage-Hotspots: `AmpConnectionPool` 54 %, `SynchronousStreamTransport` 41 %, Socket-Error-Handling 63 % | Testing P1 | — | Jules |
| P | Strict-§4.5-Path nutzt eine Session-Lifetime-Cancellation statt per-IO | Korrektheit P2 | `Transport/AsyncAmpTransport.php:111-114` | Claude |
| Q | ~~Pool ohne Idle-Eviction → langlebige Workers akkumulieren Stale-Connections~~ ✅ PR #77 | Robustheit P2 | `Transport/AmpConnectionPool.php` | 4/4 |
| R | ~~obs-fold (RFC 7230) im `Encapsulated`-Header wird im Framer nicht erkannt~~ ✅ PR #76 | RFC P2 | `Transport/ResponseFrameReader.php:144-155` | Claude, Codex |
| S | ~~Header-Name-Validation-Regex lässt RFC-7230-§3.2.6 Separator-Tokens durch~~ ✅ PR #75 | Security P2 | `IcapClient.php:637` | Claude |
| T | ~~OPTIONS-Cache ohne ISTag-Invalidation — Signature-Update wird nicht erkannt~~ ✅ PR #78 | RFC P2 | `Cache/InMemoryOptionsCache.php` | Claude |
| U | ~~`InMemoryOptionsCache` nutzt `time()` direkt, kein PSR-20 `ClockInterface`~~ ✅ PR #78 | Testbarkeit P2 | `Cache/InMemoryOptionsCache.php` | Claude |
| V | `IcapClient::executeRaw()` ist `public` aber nicht im Interface — leakt internen Pfad | API P1 | `IcapClient.php:144` | Claude, Codex |
| W | `options()` gibt `Future<ScanResult>` zurück — semantisch falsch (keine infected/clean Semantik) | API P1 | `IcapClient.php:157` | Claude, Codex |
| X | Kein PSR-6/16 Cache-Adapter für OPTIONS-Cache | Erweiterbarkeit P2 | — | 3/4 |
| Y | Kein OpenTelemetry-Decorator | Observability P2 | — | 4/4 |
| Z | Kein PHPBench-Suite | Performance P2 | — | 3/4 |
| Z1 | Symfony-Bundle fehlt vollständig | Ökosystem P1 | — | 4/4 |

---

## Release-Plan

```
v2.1.1  Critical Hotfix     kein BC    2–3 Tage   Findings A, B, C, E, G, H
v2.1.2  CI-Quality-Patch    kein BC    1 Woche    Findings D, I, J
v2.2.0  Minor (additiv)     kein BC    4–6 Wochen Findings K–Z
v2.3.0  Symfony-Bundle      neues Repo 8–12 Wochen Findings Z1
v3.0.0  Major (BC-Breaks)   BC         ~6 Monate  Findings F, V, W
```

---

## Milestone v2.1.1 — Critical Hotfix

**Scope:** Sicherheits- und Korrektheits-Regressionen, die im Feld sofort
schaden können. Kein BC-Break.

**PR-Vorschlag:** `fix(v2.1): TLS pool-key isolation, preview 200/206 verdict, stale SECURITY.md`

- [x] **v2.1.1-A** `AmpConnectionPool::key()` um TLS-Context-Fingerprint erweitern.
  Übergang: `spl_object_hash($tls)` als Suffix; deterministischer Hash (z.B.
  SHA-256 über Peer-Name + Cert-Path + CA-File) in v2.2 folgen.
  **+ Cross-TLS-Isolation-Test in `tests/Transport/AmpConnectionPoolTest.php`**
  (muss vor dem Fix rot sein).
  *Datei: `src/Transport/AmpConnectionPool.php:130-133` — Quelle: 4/4*
  ✅ PR #65 (v2.1.1), Tag `v2.1.1`

- [x] **v2.1.1-B** `DefaultPreviewStrategy::handlePreviewResponse()` für
  200/206 erweitern: Virus-Header vorhanden → `ABORT_INFECTED`,
  sonst → `ABORT_CLEAN`. Macht den toten Branch in `IcapClient.php:388`
  erreichbar. **+ Test `200 + X-Virus-Name: Eicar-Test`** in
  `tests/DefaultPreviewStrategyTest.php`.
  *Dateien: `src/DefaultPreviewStrategy.php:35-42` — Quelle: Claude, Codex ×2*
  ✅ PR #65 (v2.1.1), Tag `v2.1.1`

- [x] **v2.1.1-C** `SECURITY.md` Z. 73-75 aktualisieren: „does not retry /
  cache / pool" durch aktuelle Aussagen zu `RetryingIcapClient`,
  `InMemoryOptionsCache` und `AmpConnectionPool` ersetzen. Hinweis auf
  TLS-Pool-Key-Fix ergänzen.
  *Datei: `SECURITY.md:73-75` — Quelle: Claude, Jules*
  ✅ PR #65 (v2.1.1), Tag `v2.1.1`

- [x] **v2.1.1-E** Phpdoc-Verweis auf `NullConnectionPool` aus
  `ConnectionPoolInterface.php:36` entfernen (Klasse folgt in v2.2).
  *Datei: `src/Transport/ConnectionPoolInterface.php:36` — Quelle: Codex*
  ✅ PR #65 (v2.1.1), Tag `v2.1.1`

- [x] **v2.1.1-G** Cookbook `03-options-request.php` Z. 11-13:
  „next milestone after v2.0.0"-Stale-Claim entfernen.
  *Datei: `examples/cookbook/03-options-request.php` — Quelle: Claude*
  ✅ PR #65 (v2.1.1), Tag `v2.1.1`

- [x] **v2.1.1-H** Cookbook `02-custom-preview-strategy.php`:
  McAfee-Strategy korrigieren — 200-Antwort mit Virus-Header richtig
  behandeln, Caveat zum Anti-Pattern ergänzen.
  *Datei: `examples/cookbook/02-custom-preview-strategy.php` — Quelle: Claude*
  ✅ PR #65 (v2.1.1), Tag `v2.1.1`

**Abhängigkeiten:** v2.1.1-A + Cross-TLS-Test im selben Commit.
v2.1.1-B inkl. Test für `200 + Virus-Header`.
**Security-Advisory** für Finding A erforderlich (Cross-Tenant-Leakage,
CVE-würdig in Multi-Tenant-Deployments).

---

## Milestone v2.1.2 — CI-Quality-Patch

**Scope:** Streaming-OOM-Fix + CI-Hygiene. Bewusst getrennt von v2.1.1,
damit der Hotfix klein und eigenständig reviewbar bleibt. Kein BC-Break.

**PR-Vorschlag:** `fix(v2.1): streaming remainder, failOnRisky, PHPStan memory-limit`

- [x] **v2.1.2-D** `IcapClient::scanFileWithPreviewStrict()` Z. 399:
  `stream_get_contents($stream)` durch neue Methode
  `ChunkedBodyEncoder::encodeRemainderFromStream(resource $stream): iterable<string>`
  ersetzen (liest in bounded Chunks, puffert keinen vollständigen Body).
  Referenzimplementierung: `scanFileWithPreviewLegacy()` ab Z. 426.
  **+ Memory-Watermark-Test** in `tests/PreviewContinueStrictTest.php`
  (128-KiB-Stream mit Chunk-Level-Payload-Verifikation).
  *Dateien: `src/IcapClient.php:399`, `src/ChunkedBodyEncoder.php` — Quelle: Claude, Codex ×2*
  ✅ PR #66 (v2.1.2), Tag `v2.1.2`. Closes #56

- [x] **v2.1.2-I** 3 risky Unit-Tests entkernen (fehlende Assertions ergänzen
  oder explizit als `markTestIncomplete`), dann `failOnRisky="true"` +
  `failOnWarning="true"` in `phpunit.xml.dist` setzen.
  *Datei: `phpunit.xml.dist` — Quelle: Codex-PR*
  ✅ PR #66 (v2.1.2), Tag `v2.1.2`

- [x] **v2.1.2-J** `composer stan`-Skript um `--memory-limit=1G` ergänzen;
  CI-Job in `.github/workflows/ci.yml:43` entsprechend anpassen.
  *Dateien: `composer.json`, `.github/workflows/ci.yml:43` — Quelle: Codex-DR*
  ✅ PR #66 (v2.1.2), Tag `v2.1.2`

---

## Milestone v2.2.0 — Minor (additiv)

**Scope:** Funktionale Lücken schließen, Observability + Test-Reife heben.
Alle Items additiv; kein BC-Break.

### OPTIONS-getriebenes Pool-/Preview-Tuning

- [x] **v2.2-K** OPTIONS-driven Preview-Size: `scanFileWithPreview()` ohne
  expliziten `$previewSize`-Parameter befragt den OPTIONS-Cache und nutzt
  den Server-`Preview`-Header-Wert. Intern über bestehendes
  `OptionsCacheInterface` — kein neues Public-API nötig.
  *Datei: `src/IcapClient.php:269-322` — Quelle: 4/4*
  ✅ PR #72, Closes #58.

- [x] **v2.2-L** Max-Connections aus OPTIONS für Pool-Cap:
  `effectiveCap = min(localCap, serverMaxConnections)`,
  via `AmpConnectionPool::__construct(serverMaxConnections: $max)`.
  *Dateien: `src/Transport/AmpConnectionPool.php:106-110` — Quelle: 4/4*
  ✅ PR #73, Closes #59.

- [x] **v2.2-T** OPTIONS-Cache ISTag-Invalidation:
  `OptionsCacheInterface::set()` um `?string $istag`-Parameter erweitert;
  `InMemoryOptionsCache` eviktet alle Einträge bei ISTag-Wechsel.
  ✅ PR #78.
  *Dateien: `src/Cache/OptionsCacheInterface.php`, `src/Cache/InMemoryOptionsCache.php` — Quelle: Claude*

- [x] **v2.2-U** Injectable `(Closure(): int)|null $clock` als Konstruktor-Parameter
  in `InMemoryOptionsCache`; ermöglicht deterministische TTL-Tests.
  `advanceClockForTesting()` entfernt.
  ✅ PR #78.
  *Datei: `src/Cache/InMemoryOptionsCache.php` — Quelle: Claude*

- [x] **v2.2-E2** `NullConnectionPool` anlegen:
  implementiert `ConnectionPoolInterface`, jede `acquire()`-Anfrage
  öffnet eine frische Verbindung, `release()` schließt sie.
  Nützlich für explizite Pool-Off-Konfigs und Tests.
  *Datei: `src/Transport/NullConnectionPool.php` (neu) — Quelle: Codex*
  ✅ PR #71, Closes #57.

- [ ] **v2.2-X** PSR-6/16 OPTIONS-Cache-Adapter als Optional-Deps:
  `Cache/Psr16OptionsCache.php`, `Cache/Psr6OptionsCache.php`.
  *Quelle: 3/4*

### CI-Härtung & Test-Reife

- [x] **v2.2-M** Mutation-Testing wieder als Required-CI-Job:
  `composer mutation` (`--min=65`, kein `continue-on-error`).
  Sollte früh im v2.2-Zyklus landen, damit Folge-PRs davon profitieren.
  *Datei: `.github/workflows/ci.yml:130-135` — Quelle: 4/4*
  ✅ PR #69, Closes #60. MSI 68.47% im ersten CI-Lauf.

- [x] **v2.2-N** Integration-CI aus `continue-on-error: true` herausführen:
  entweder hartes Gate auf PRs oder Nightly-Workflow mit
  Required-Status-Check. Nightly empfohlen wegen flaky ClamAV-Image.
  *Datei: `.github/workflows/ci.yml:63` — Quelle: 3/4*
  ✅ PR #74, Closes #61. Nightly + push-to-main, kein continue-on-error.

- [ ] **v2.2-O** Coverage-Push auf Hotspot-Klassen. Ziele:
  `AmpConnectionPool` 54 → ≥ 90 %, `SynchronousStreamTransport` 41 → ≥ 85 %,
  Async-Socket-Error-Handling 63 → ≥ 85 %.
  Konkrete fehlende Test-Cases:
  - Cross-TLS-Pool-Isolation (bereits in v2.1.1, hier counted)
  - Concurrent-Acquire-Race (Fiber)
  - `0; ieof`-Recv-Pfad in `ResponseFrameReaderTest`
  - Multi-Section Encapsulated (`req-hdr + res-hdr + req-body`)
  - Cancellation mid-write / mid-read / Composite (Timeout + explizit)
  - `Options-TTL=0` (kein-Caching-Pfad)
  - `SynchronousIcapClient::scanFileWithPreview()`
  - Logger Sensitive-Header-Regression
  *Dateien: `tests/Transport/`, `tests/Wire/`, `tests/CancellationTest.php`,
  `tests/SynchronousIcapClientTest.php`, `tests/LoggerIntegrationTest.php`,
  `tests/OptionsCacheTest.php` — Quelle: Jules + Claude + Codex*

### Korrektheit & Robustheit

- [ ] **v2.2-P** Strict-§4.5-Path: per-IO-Timeout-Reset statt
  Session-Lifetime-`TimeoutCancellation`. Alternativ: explizites Caveat
  in Phpdoc + CHANGELOG.
  *Datei: `src/Transport/AsyncAmpTransport.php:111-114` — Quelle: Claude*

- [x] **v2.2-Q** Pool-Idle-Eviction mit konfigurierbarem `maxIdleSeconds`
  (Default 30 s): Beim `acquire()` abgelaufene Idle-Einträge verwerfen.
  ✅ PR #77, Closes #64.
  *Datei: `src/Transport/AmpConnectionPool.php` — Quelle: 4/4*

- [x] **v2.2-R** obs-fold (RFC 7230) im `Encapsulated`-Header:
  `ResponseFrameReader::findEncapsulatedHeader()` faltet Continuation-Lines
  vor dem Zeilenweise-Split auf.
  ✅ PR #76, Closes #63.
  *Datei: `src/Transport/ResponseFrameReader.php:144-155` — Quelle: Claude, Codex*

- [x] **v2.2-S** Header-Name-Validation auf RFC-7230-§3.2.6-Token-Set
  verschärfen: `[!#$%&'*+\-.^_` + "`" + `|~0-9a-zA-Z]+`.
  ✅ PR #75, Closes #62.
  *Datei: `src/IcapClient.php:637` — Quelle: Claude*

### Doku & Tooling

- [ ] **v2.2-cookbook** 4 neue Cookbook-Files:
  `04-tls-mtls.php`, `05-retry-decorator.php`,
  `06-pool-tuning.php`, `07-cancellation-from-upload.php`.
  *Dateien: `examples/cookbook/` — Quelle: Claude, Codex*

- [ ] **v2.2-readme** Connection-Pool-Konfiguration im README-Config-Block
  dokumentieren.
  *Datei: `README.md:89-129` — Quelle: Claude*

- [ ] **v2.2-compliance** `docs/compliance.md` mit BSI OPS.1.1.4 / APP.4.4 /
  DSGVO Art. 32-Mapping + AI-Disclaimer-Verlinkung.
  *Datei: `docs/compliance.md` (neu) — Quelle: Claude*

- [ ] **v2.2-contrib** CONTRIBUTING.md um Conventional-Commits-Konvention
  ergänzen (Typen, Scopes, Body-Pflicht).
  *Datei: `CONTRIBUTING.md` — Quelle: Claude*

- [ ] **v2.2-Y** OpenTelemetry-Decorator `OtelTracingIcapClient` als
  Optional-Dep auf `open-telemetry/api`.
  *Datei: `src/Tracing/OtelTracingIcapClient.php` (neu) — Quelle: 4/4*

- [ ] **v2.2-Z** PHPBench-Suite: Pool-Throughput, Strict-§4.5-Latency,
  Chunked-Encoder-Durchsatz.
  *Verzeichnis: `benchmarks/` (neu) — Quelle: 3/4*

- [ ] **v2.2-sbom** SBOM (CycloneDX/SPDX) in CI generieren.
  *Datei: `.github/workflows/ci.yml` — Quelle: Claude*

- [x] **v2.2-F** `IcapResponseException`: PHP 8.4 `#[\Deprecated]` mit
  konkretem `v3.0.0`-Removal-Tag auf dem Konstruktor gesetzt.
  ✅ PR #79.
  *Datei: `src/Exception/IcapResponseException.php` — Quelle: Claude, Codex*

### P3 — Nice-to-Have

- [ ] Property-based Tests für `ResponseParser` / `ResponseFrameReader`
  (z.B. mit `eris-php`).
  *Verzeichnis: `tests/Property/` (neu) — Quelle: 3/4*

- [ ] Fuzz-Korpus für `ResponseParser` (PHPUnit-Data-Provider-basiert oder
  nativer Fuzzer).
  *Verzeichnis: `tests/Fuzz/` (neu) — Quelle: Claude*

---

## Milestone v2.3.0 — Symfony-Bundle (separates Repo)

**Scope:** Eigenes Repo `ndrstmr/icap-flow-bundle`, abhängig auf `^2.2`.
Nicht Teil dieses Repos.

Initial-Scope des neuen Repos:
- `IcapFlowBundle` mit Configuration-Tree + `IcapFlowExtension`
- Auto-DI: `IcapClientInterface` → `RetryingIcapClient` → inner `IcapClient`
- Tagged-Services für Multi-Client (`icap_flow.client.<name>`)
- Symfony Profiler DataCollector
- Monolog-Channel `icap`
- Console-Commands `icap:scan`, `icap:options`, `icap:health`
- Validator-Constraint `#[IcapClean]`
- Flex-Recipe für `symfony/recipes-contrib`
- Adapter für VichUploaderBundle / OneupUploaderBundle

*Quelle: 4/4. Blockiert auf v2.2.0-Release.*

---

## Milestone v3.0.0 — BC-Breaks (gesammelt)

Nur relevasen, wenn mindestens zwei der folgenden Punkte durch User-Feedback
erzwungen werden.

- [ ] **v3-V** `IcapClient::executeRaw()` → `protected` **oder** in
  `IcapClientInterface` heben.
  *Datei: `src/IcapClient.php:144`, `src/IcapClientInterface.php` — Quelle: Claude, Codex*

- [ ] **v3-W** `options()` zu `Future<IcapResponse>` umstellen
  (BC-Break direkt, kein additives `optionsRaw()`).
  *Dateien: `src/IcapClient.php:157`, `src/IcapClientInterface.php`,
  `src/SynchronousIcapClient.php` — Quelle: Claude, Codex*

- [ ] **v3-F** `IcapResponseException` entfernen (Deprecation einlösen).
  *Datei: `src/Exception/IcapResponseException.php` + alle Call-Sites — Quelle: Claude, Codex*

---

## Abhängigkeitskarte

| Item | Blockiert durch | Blockiert |
|---|---|---|
| v2.1.1-A | — | Cross-TLS-Test (gleicher PR) |
| v2.1.2-D | v2.1.1 (Hotfix erst raus) | Memory-Watermark-Test (gleicher PR) |
| v2.2-K + v2.2-L | v2.1.2 | v2.3 Bundle-DI-Config |
| v2.2-T + v2.2-U | — | zusammen mergen für testbare Fixtures |
| v2.2-E2 | — | v2.3 Bundle-DI-Default-Pool |
| v2.2-M | v2.2-O (Coverage-Lücken zuerst schließen) | alle v2.2-Folge-PRs |
| v2.2-Y | v2.2-E2 | v2.3 Bundle-Tracing |
| v2.3 Bundle | v2.2.0-Release | — |

---

## Kritische Dateien

| Datei | Betroffene Findings |
|---|---|
| `src/Transport/AmpConnectionPool.php` | A, L, Q |
| `src/IcapClient.php` | B, D, K, S, V, W |
| `src/DefaultPreviewStrategy.php` | B |
| `src/Cache/InMemoryOptionsCache.php` | T, U |
| `src/Cache/OptionsCacheInterface.php` | T |
| `src/Transport/ConnectionPoolInterface.php` | E |
| `src/Transport/AsyncAmpTransport.php` | P |
| `src/Transport/ResponseFrameReader.php` | R |
| `src/Exception/IcapResponseException.php` | F |
| `src/IcapClientInterface.php` | V, W |
| `SECURITY.md` | C |
| `examples/cookbook/02-…` | H |
| `examples/cookbook/03-…` | G |
| `phpunit.xml.dist` | I |
| `.github/workflows/ci.yml` | J, M, N |

---

## Verifizierung je Milestone

**Nach v2.1.1:**
- `composer test` grün; `AmpConnectionPoolTest::testCrossTlsIsolation` schlägt vor dem Fix fehl, danach grün.
- `DefaultPreviewStrategyTest`: `200 + X-Virus-Name: Eicar-Test` → `ABORT_INFECTED`.
- `SECURITY.md` Z. 73-75 beschreibt `RetryingIcapClient`, `InMemoryOptionsCache`, `AmpConnectionPool`.
- Manuell gegen `docker compose up icap-clamav`: Eicar im 1-KiB-Preview → `ScanResult(isInfected=true)`, keine Exception.

**Nach v2.1.2:**
- Memory-Watermark-Test: `php -d memory_limit=128M` mit 2-GB-Stream durch `scanFileWithPreviewStrict()` — kein OOM.
- `composer test` mit `failOnRisky=true` + `failOnWarning=true` grün.
- CI-PHPStan-Job ohne OOM.

**Nach v2.2.0:**
- `composer mutation --min=65` als Required-Status-Check auf PRs.
- Coverage: `AmpConnectionPool` ≥ 90 %, `SynchronousStreamTransport` ≥ 85 %, Socket-Error ≥ 85 %.
- Integration-CI als Required-Status (oder Nightly mit Issue-on-Failure).
- `phpbench run benchmarks/ --report=default` liefert Baseline.
