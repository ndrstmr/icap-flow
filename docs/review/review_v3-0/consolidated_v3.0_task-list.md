# Konsolidierte Task-Liste — v3.0.x / v3.1.0 Findings & Roadmap

> **Basis:** Synthese der drei unabhängigen Audits zu v3.0.0
> in `docs/review/review_v3-0/` (Codex, Jules, Claude),
> verifiziert am `src/`-Stand bei Tag `v3.0.0`.
>
> **Scores:** Jules 265/280 (TRL-8), Codex TRL 8/9, Claude 227/280 (~81%, TRL-8).
> Konsens: **TRL-8, produktionsreif mit P1-Code-Fixes.**
>
> v3.0.0 ist ein konsequenter Cleanup-Major: `executeRaw()` protected,
> `options(): Future<IcapResponse>`, `IcapResponseException` entfernt.
> Fail-Secure-Matrix, Parser-Limits, TLS-Pool-Isolation und
> Injection-Guards sind intakt. **Vier P1-Findings** erfordern Patches
> vor AAA-Class-Produktion: Statuscode-Backstop, Host-Header-Hijack,
> IcapTimeoutException dead code, Coverage-Hotspots.

---

## Reviewer-Konsens-Matrix

| Finding | Jules | Codex | Claude-Code | Claude-Audit | Konsens |
|---|---|---|---|---|---|
| `assertSuccessfulStatus()` Fail-Open für 1xx≠100/3xx/6xx im `options()`-Pfad | — | — | P1 | P2 (F-7-1) | **P1 (verifiziert)** |
| `IcapTimeoutException` ist toter Code — wird nirgends geworfen | — | — | — | P1 (F-4-1) | **P1** |
| Host-Header-Hijack via `extraHeaders` — Library-managed-Prinzip verletzt | — | — | — | P1 (F-2-1) | **P1** |
| PSR-Cache `__icap_keys` Race-Condition (read-modify-write ohne Atomarität) | P2 | P1 | — | P1 (F-3-1) | **3/4 P1** |
| Coverage-Hotspots nicht erreicht (SyncTransport 41%, AsyncTransport 64%) | — | — | P2 | P1 (F-9-1) | **P1** |
| TLS-Fingerprint `spl_object_hash()` statt deterministischem Hash | — | — | P2 | P2 (F-5-1) | **2/4 P2** |
| `tuneFromOptions()` nicht automatisch im `options()`-Hot-Path | — | — | P2 | — | 1/4 P2 |
| Symfony-Bundle fehlt | P1 | P1 | — | — | **2/4 P1** |
| PSR-Cache Key-Pruning (`__icap_keys` Wachstum) | P2 | P1 | — | — | **2/4 P1–P2** |
| PSR-Cache Cross-Process Race-Doku | P2 (impl.) | P1 (doku) | — | P1 (F-3-1) | **3/4 P1** |
| `options()` Cache-Hit: synchrone Exception + fehlendes Logging | — | — | — | P2 (F-2-2/3) | 1/4 P2 |
| `stream_set_timeout` verliert Sub-Sekunden-Präzision (0.5→0) | — | — | — | P2 (F-4-2) | 1/4 P2 |
| `Config` Konstruktor ohne Validation (leerer Host, negatives Port) | — | — | — | P2 (F-5-3) | 1/4 P2 |
| ISTag-Meta-Key in PSR-Cache ohne TTL (LRU-Eviction-Problem) | — | — | — | P2 (F-3-2) | 1/4 P2 |
| OTel-Decorator | P3 | P1/P2 | — | — | **2/4 P2** |
| Globaler Request-Deadline (`maxSessionDuration`) | — | P2 | — | P2 (F-5-2) | **2/4 P2** |
| MSI-Schwelle stufenweise erhöhen | — | P2 | — | P2 (F-9-2) | **2/4 P2** |
| SBOM-Export (CycloneDX/SPDX) | — | P2 | — | — | 1/4 P2–P3 |
| PR-Integration-Smoke (label-basiert) | — | P1/P2 | — | — | 1/4 P2 |
| PHPBench-Suite | P3 | P2 | — | — | **2/4 P2–P3** |
| OPTIONS-Body in Cache nullen (bis 10 MiB möglich) | — | — | — | P3 (F-8-1) | 1/4 P3 |
| `Config::withTlsContext(null)` deaktiviert TLS geräuschlos | — | — | — | P3 (F-8-2) | 1/4 P3 |
| PSR-20 `ClockInterface`-Adapter | — | P2 | — | — | 1/4 P3 |
| Property Hooks in DTOs | P3 | — | — | — | 1/4 P3 |

> **Korrektur / Nicht-Todos:**
> - Cache-Race ist **kein Bug** — Fail-Secure-Verhalten fängt den Worst-Case.
>   Lediglich Dokumentation fehlt.
> - Vendor-Interop-CI-Matrix (Symantec/Sophos/Kaspersky) ist wünschenswert,
>   aber ohne Zugang zu proprietären Appliances nicht umsetzbar.

---

## Verifizierte Findings

| ID | Finding | Schweregrad | Evidenz | Quelle |
|---|---|---|---|---|
| A | PSR-Cache `__icap_keys` Meta-Array wächst unbegrenzt bei hochdynamischen Service-Pfaden | Robustheit P2 | `src/Cache/Psr6OptionsCache.php`, `Psr16OptionsCache.php` | 2/2 |
| B | PSR-Cache Cross-Process-Konsistenzmodell undokumentiert (TOCTOU bei ISTag-Flush) | Doku P1 | `src/Cache/Psr6OptionsCache.php`, `Psr16OptionsCache.php` | 2/2 |
| C | Kein globales Hard-Deadline über mehrphasige Preview-Operationen | Robustheit P2 | `src/Transport/AmpTransportSession.php` | Codex |
| D | Kein OpenTelemetry-Decorator (Tracing/Metrics) | Observability P2 | — | 2/2 |
| E | MSI-Schwelle bei 65% — konservativ für Security-kritische Lib | Testing P2 | `.github/workflows/ci.yml` | Codex |
| F | Kein SBOM-Export für Public-Sector-Beschaffung | Compliance P3 | CI | Codex |
| G | Integration-Tests nicht auf PRs (nur push/schedule) | CI P2 | `.github/workflows/ci.yml` | Codex |
| H | PHPBench-Suite fehlt | Performance P3 | — | 2/2 |
| I | Symfony-Bundle fehlt vollständig | Ökosystem P1 | — | 2/2 |
| J | `assertSuccessfulStatus()` wirft nicht für 1xx≠100, 3xx, 6xx+ — im `options()`-Pfad fehlt Backstop → Response wird gecacht/zurückgegeben | **Fail-Secure P1** | `src/IcapClient.php:624-649` (Helper), Z. 197 (`options()`) | Claude-Code |
| K | TLS-Pool-Key nutzt `spl_object_hash($tls)` — zwei äquivalente TLS-Contexts → getrennte Pool-Buckets → Pool-Reuse bricht im DI-Container | Performance P2 | `src/Transport/AmpConnectionPool.php:199-207` | Claude-Code |
| L | `tuneFromOptions()` nur manuell aufrufbar — Default-Client mit Default-Pool braucht zwei OPTIONS-Round-Trips vor Tuning | Ergonomie P2 | `src/Transport/AmpConnectionPool.php:173-179` | Claude-Code |
| M | 8 konkrete Test-Coverage-Lücken (Backstop-Codes, Legacy-Preview, Multi-Value-Header, Connection-close, Pre-fired-Cancellation, Cross-Process-Cache, maxHeaderCount, fragmentierte Reads) | Testing P2 | diverse | Claude-Code |
| N | `AmpConnectionPool::tuneFromOptions()` Phpdoc-Beispiel zeigt `$result->originalResponse` (v2-API) — nach v3-W liefert `options()` direkt `IcapResponse`, `@see ScanResult::$originalResponse` ist stale | Doku P2 | `src/Transport/AmpConnectionPool.php:169-171` | Claude-Code |
| O | `IcapTimeoutException` ist toter Code — wird nirgends geworfen, `Amp\CancelledException` (kein `IcapExceptionInterface`!) propagiert stattdessen | **API-Kontrakt P1** | `src/Exception/IcapTimeoutException.php`, `src/Transport/AsyncAmpTransport.php`, `SynchronousStreamTransport.php` | Claude-Audit F-4-1 |
| P | `RequestFormatter.php:152` setzt Host nur per `if (!isset(...))` — Caller-supplied `Host` via `extraHeaders` gewinnt, CLAUDE.md-Designregel verletzt | **Security P1** | `src/RequestFormatter.php:152-154`, `src/IcapClient.php:696-706` | Claude-Audit F-2-1 |
| Q | `stream_set_timeout($stream, (int) $timeout)` — Sub-Sekunden-Präzision geht verloren (0.5s → 0 = kein Timeout!) | Korrektheit P2 | `src/Transport/SynchronousStreamTransport.php:81` | Claude-Audit F-4-2 |
| R | `options()` Cache-Hit: `assertSuccessfulStatus()` wirft synchron vor Future-Rückgabe — API-Inkonsistenz | API P2 | `src/IcapClient.php:174-180` | Claude-Audit F-2-2 |
| S | `options()` Cache-Hit: kein `logger->info()` — Operator sieht nicht ob live oder Cache | Observability P2 | `src/IcapClient.php:174-180` | Claude-Audit F-2-3 |
| T | `Config` Konstruktor ohne Validation — `new Config('')` oder `port: -1` knallt erst im Connect | Defensive P2 | `src/Config.php:53-66` | Claude-Audit F-5-3 |
| U | ISTag-Meta-Key in PSR-Cache ohne `expiresAfter()` — bei LRU-Eviction (Memcached) fliegt Key weg, stale Cache bleibt | Cache-Konsistenz P2 | `src/Cache/Psr6OptionsCache.php:121-123` | Claude-Audit F-3-2 |

---

## Release-Plan

```
v3.0.1  Patch (Code + Doku)    kein BC    sofort     Findings B, J, N, O, P, Q, R, S, T, U
v3.1.0  Minor (additiv)        kein BC    6–10 Wo.   Findings A, C, D, E, G, K, L, M
v3.2.0  Minor (Tooling)        kein BC    danach     Findings F, H
Bundle  icap-flow-bundle v1.0  neues Repo parallel   Finding I
v4.0.0  nur bei echtem Bedarf  —          nicht absehbar
```

---

## Milestone v3.0.1 — Patch (Code + Doku)

**Scope:** Fail-Secure-Lücke im `options()`-Pfad schließen + Cache-Doku. Kein BC-Break.

**PR-Vorschlag:** `fix(security): backstop for unhandled status codes in assertSuccessfulStatus()`

- [ ] **v3.0.1-J** `assertSuccessfulStatus()` um Backstop-Throw erweitern:
  Codes die weder 100, noch 2xx, noch 4xx, noch 5xx sind → `IcapProtocolException`.
  Damit werden 1xx≠100 (z.B. 102), 3xx, 6xx+ im `options()`-Pfad
  als Protokollfehler behandelt statt gecacht/zurückgegeben.
  Der bestehende Backstop in `interpretResponse()` wird dann unreachable
  (Defense-in-Depth, kann mit `@codeCoverageIgnore` annotiert bleiben).
  **+ Test** in `tests/Security/`: Mock-Transport liefert 301/102/600 →
  Exception auf `options()` UND `request()`.
  *Datei: `src/IcapClient.php:624-649`*
  *Quelle: Claude-Code P1 — am Code verifiziert*

  ```php
  // Am Ende von assertSuccessfulStatus(), nach dem 5xx-Block:
  if ($code < 200 || $code >= 300) {
      throw new IcapProtocolException(
          sprintf('Unexpected ICAP status (%d) — neither success nor recognized failure.', $code),
          $code,
      );
  }
  ```

- [ ] **v3.0.1-B** Konsistenzmodell für PSR-6/16-Cache-Adapter dokumentieren:
  - Erwartetes Verhalten bei konkurrierenden Workern (TOCTOU-Fenster).
  - Empfohlene Backend-Klassen (APCu für Single-Node, Redis mit Vorbehalt).
  - Fail-Secure-Garantie: veraltete Capabilities führen schlimmstenfalls zu
    4xx/Retry, nie zu falschem Clean-Verdict.
  *Ziel: Absatz in README oder dediziertes `docs/cache-consistency.md`.*
  *Quelle: Jules P2, Codex P1*

- [ ] **v3.0.1-N** `AmpConnectionPool::tuneFromOptions()` Phpdoc korrigieren:
  - Z. 169: `$result->originalResponse` → `$response` (nach v3-W ist `options()` direkt `Future<IcapResponse>`).
  - Z. 171: `@see ScanResult::$originalResponse` entfernen (stale Referenz).
  *Datei: `src/Transport/AmpConnectionPool.php:167-172`*
  *Quelle: Claude-Code — am Code verifiziert*

- [ ] **v3.0.1-O** `IcapTimeoutException` aktivieren: Interne Timeouts
  (`Amp\CancelledException` aus `TimeoutCancellation` und
  `stream_get_meta_data()['timed_out']`) abfangen und in
  `IcapTimeoutException` wrappen. Nur interne Timeouts — User-supplied
  `Cancellation` propagiert weiterhin als `CancelledException`.
  **+ Test:** `expect(fn() => ...)->toThrow(IcapTimeoutException::class)`
  mit Socket-Pair ohne Server-Antwort.
  *Dateien: `src/Transport/AsyncAmpTransport.php`, `src/Transport/SynchronousStreamTransport.php`*
  *Quelle: Claude-Audit F-4-1 — P1*

- [ ] **v3.0.1-P** Host/Connection als Library-managed Headers erzwingen:
  `RequestFormatter` setzt `$headers['Host'] = [$host]` unbedingt
  (nicht nur `if (!isset(...)`).\
  `IcapClient::validateIcapHeaders()` erweitern: Reserved-Names
  (`Host`, `Encapsulated`, `Connection`) in `extraHeaders` ablehnen
  via `\InvalidArgumentException`.
  **+ Test:** `expect(fn() => $client->scanFile(..., ['Host' => 'evil']))->toThrow(InvalidArgumentException::class)`
  *Dateien: `src/RequestFormatter.php:152-154`, `src/IcapClient.php:665-684`*
  *Quelle: Claude-Audit F-2-1 — P1 (Security)*

- [ ] **v3.0.1-Q** `stream_set_timeout` mit Mikrosekunden-Präzision:
  ```php
  $seconds = (int) $timeout;
  $microseconds = (int) (($timeout - $seconds) * 1_000_000);
  stream_set_timeout($stream, $seconds, $microseconds);
  ```
  *Datei: `src/Transport/SynchronousStreamTransport.php:81`*
  *Quelle: Claude-Audit F-4-2 — P2*

- [ ] **v3.0.1-R** `options()` Cache-Hit in Future wrappen für API-Konsistenz:
  ```php
  return \Amp\async(function () use ($cached): IcapResponse {
      $this->assertSuccessfulStatus($cached->statusCode);
      return $cached;
  });
  ```
  *Datei: `src/IcapClient.php:174-180`*
  *Quelle: Claude-Audit F-2-2 — P2*

- [ ] **v3.0.1-S** `options()` Cache-Hit: Logger-Eintrag ergänzen:
  `$this->logger->info('ICAP options served from cache', [...])`
  *Datei: `src/IcapClient.php:174-180`*
  *Quelle: Claude-Audit F-2-3 — P2*

- [ ] **v3.0.1-T** `Config` Konstruktor-Validation:
  Leerer Host, Port außerhalb 1–65535, negative Timeouts → `\InvalidArgumentException`.
  *Datei: `src/Config.php:53-66`*
  *Quelle: Claude-Audit F-5-3 — P2*

- [ ] **v3.0.1-U** ISTag-Meta-Key mit TTL speichern:
  `$item->expiresAfter(86400)` (24h) in `Psr6OptionsCache` und
  `$this->cache->set($key, $istag, 86400)` in `Psr16OptionsCache`.
  *Dateien: `src/Cache/Psr6OptionsCache.php:121-123`, `src/Cache/Psr16OptionsCache.php:113`*
  *Quelle: Claude-Audit F-3-2 — P2*

---

## Milestone v3.1.0 — Minor (additiv)

**Scope:** Robustheit, Observability, Testing-Governance. Kein BC-Break.

- [ ] **v3.1-A** PSR-Cache Key-Pruning: `__icap_keys`-Array begrenzen.
  Strategie: LRU oder TTL-basiertes Pruning bei `set()`. Maximale
  Key-Anzahl als Config-Option (Default: 256).
  *Dateien: `src/Cache/Psr6OptionsCache.php`, `src/Cache/Psr16OptionsCache.php`*
  *Quelle: Jules P2, Codex P1*

- [ ] **v3.1-C** Optionalen `maxSessionDuration` in `Config` ergänzen.
  In `AmpTransportSession` als äußere Cancellation-Boundary neben Per-IO-Timeout.
  Default: `null` (kein Limit, Rückwärtskompatibel).
  *Dateien: `src/Config.php`, `src/Transport/AmpTransportSession.php`*
  *Quelle: Codex P2*

- [ ] **v3.1-D** OpenTelemetry-Decorator (`OtelTracingIcapClient`):
  - Span pro `request()`/`scanFile*()`/`options()`.
  - Attributes: `icap.method`, `icap.host`, `icap.status_code`, `icap.infected`.
  - Optional, keine harte Abhängigkeit (suggest in `composer.json`).
  *Ziel: `src/OtelTracingIcapClient.php` (Decorator, nicht Subklasse)*
  *Quelle: 2/2*

- [ ] **v3.1-E** MSI-Schwelle auf 70% anheben, gezielte Tests für
  überlebende Mutanten in Status-/Parser-/Cache-Pfaden ergänzen.
  *Datei: `composer.json` (mutation script), ggf. neue Testdateien*
  *Quelle: Codex P2*

- [ ] **v3.1-G** PR-Integration-Smoke: label-gesteuerter, optionaler
  Integration-Workflow auf PRs (`integration-test`-Label triggert Job).
  *Datei: `.github/workflows/ci.yml`*
  *Quelle: Codex P2*

- [ ] **v3.1-K** TLS-Pool-Key deterministisch machen: `spl_object_hash($tls)`
  durch SHA-256 über stabile TLS-Felder ersetzen (`peerName`, `certFile`,
  `caFile`, `cipherList`). Sicherheitsneutral (aktuelles Verhalten ist
  fail-safe = mehr Sockets), aber Performance-relevant für DI-Container
  die pro Request neue `ClientTlsContext`-Instanzen bauen.
  *Datei: `src/Transport/AmpConnectionPool.php:199-207`*
  *Quelle: Claude-Code P2*

- [ ] **v3.1-L** Optional: `autoTuneFromOptions`-Parameter auf `IcapClient`
  (Default `false`, BC-safe). Bei `true` wird nach `options()` automatisch
  `pool->tuneFromOptions()` aufgerufen, wenn der Transport ein Pool-Object
  exponiert. Alternative: als Bundle-Feature dokumentieren.
  *Datei: `src/IcapClient.php`*
  *Quelle: Claude-Code P2*

- [ ] **v3.1-M** Gezielte Tests für 8 identifizierte Coverage-Lücken:
  1. `interpretResponse()` Backstop (1xx≠100, 3xx, 6xx) — direkt testen
  2. `scanFileWithPreviewLegacy()` Pfad (Non-SessionAware-Transport)
  3. `validateIcapHeaders()` mit Multi-Value-Array inkl. Injection
  4. `AmpConnectionPool` Server-`Connection: close`-Honor
  5. `AmpTransportSession` Pre-fired external Cancellation
  6. PSR-Cache-Adapter Cross-Process ISTag-Race
  7. `ResponseParser` `maxHeaderCount`-Limit direkt
  8. `ResponseFrameReader` fragmentierte 1-Byte-Server-Reads
  *Dateien: `tests/Security/`, `tests/Transport/`, `tests/Wire/`, `tests/Cache/`*
  *Quelle: Claude-Code P2*

---

## Milestone v3.2.0 — Tooling & Benchmarks

- [ ] **v3.2-F** SBOM-Export (CycloneDX) als CI-Artefakt bei Release.
  *Datei: `.github/workflows/ci.yml`*
  *Quelle: Codex P2*

- [ ] **v3.2-H** PHPBench-Suite: Definierte Filegrößen (1 KB, 1 MB, 100 MB),
  Preview-Profile (mit/ohne), Pool-Szenarien (cold/warm).
  *Ziel: `benchmarks/` Ordner*
  *Quelle: 2/2*

---

## Begleit-Repo: `ndrstmr/icap-flow-bundle` v1.0.0

**Scope:** Offizielles Symfony-Bundle auf `^3.0`. Höchste Ökosystem-Priorität.

- [ ] **Bundle-I.1** `IcapFlowBundle` + Configuration Tree:
  `host`, `port`, `tls.*`, `virus_found_headers[]`, `limits.*`,
  `pool.*`, `retry.*`, `options_cache` (in_memory/psr6/psr16).

- [ ] **Bundle-I.2** Service-Wiring:
  `Config` → `AmpConnectionPool` → `AsyncAmpTransport` → `IcapClient`
  (+ optional `RetryingIcapClient` als public alias).

- [ ] **Bundle-I.3** Multi-Client-Support (`icap_flow.clients.<name>`).

- [ ] **Bundle-I.4** Monolog-Channel `icap` + PSR-6-Bridge zu `cache.app`.

- [ ] **Bundle-I.5** CLI Commands: `icap:options`, `icap:scan`.

- [ ] **Bundle-I.6** Optional: `#[IcapClean]` Validator-Constraint.

- [ ] **Bundle-I.7** Optional: Symfony DataCollector (nach OTel-Decorator).

---

## Nicht-Todos (bewusst ausgeschlossen)

| Thema | Begründung |
|---|---|
| Core-Security-Fixes (außer J, O, P) | Bis auf Backstop, Timeout-Wrapping und Host-Header-Hijack keine gefunden — Parser-Limits, TLS-Isolation intakt |
| Cache-Race „Fix" | Kein Bug, sondern dokumentierter Trade-off (fail-secure fängt Worst-Case) |
| Vendor-Interop-CI (Symantec/Sophos etc.) | Kein Zugang zu proprietären Appliances |
| Property Hooks in DTOs | Rein kosmetisch, kein Mehrwert |
| `ClockInterface`-Adapter | Pragmatischer Closure-Ansatz reicht; kein externer Bedarf |
| v4.0.0-Planung | Kein Designdruck; v3.0 hat alle Schulden abgebaut |
