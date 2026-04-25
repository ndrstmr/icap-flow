# Konsolidierte Task-Liste — v2.0.0 RFC-Compliance & Hardening

> **Status (25.04.2026):** ✅ **v2.0.0 ready to tag.** Alle ursprünglichen P0/P1-Findings (A–N) und alle vier M3-Follow-ups (Cancellation, OPTIONS-Cache, 503-Retry, Response-Framing) sind geschlossen.
>
> | Block | PR |
> |---|---|
> | M0 Foundation | #36 |
> | M1 RFC Wire-Format | #37 |
> | M2 Security Hardening | #38 |
> | M3 Core (Logger, Multi-Vendor, Custom-Headers) | #39 |
> | M4 Test Infrastructure | #40 |
> | M5 Docs + AI Disclaimer | #41 |
> | M3-ext Cancellation | #42 |
> | M3-ext OPTIONS Cache | #43 |
> | M3-ext 503 Retry Decorator | #44 |
> | M3-ext Response Framing (drops `Connection: close`) | #45 |
>
> **Verifiziert end-to-end** gegen `mnemoshare/clamav-icap` Integration-Stack (OPTIONS, EICAR-Detection via `X-Violations-Found`, Clean-File). Final unit suite: **84 passed, 167 assertions**, PHPStan L9 + bleedingEdge clean, CS-Fixer clean.
>
> **Geplant für v2.1.0:** Keep-Alive Connection-Pooling in `AsyncAmpTransport` (Framing-Voraussetzung ist mit M3-ext erledigt; jetzt reines Transport-Refactor).

**Ziel:** `ndrstmr/icap-flow` zum quasi-Standard PHP-ICAP-Client ausbauen — RFC-3507-konform, fail-secure, streaming-sicher, Symfony-ready.

**Basis:** Synthese der drei unabhängigen Due-Diligence-Reviews in `docs/review/` (Claude, Codex, Jules), verifiziert am aktuellen `src/`-Stand bei commit `ec16785`.

**Strategie:** v1.0.0 wird **deprecated**. Die erforderlichen Wire-Format-Fixes sind per Definition breaking → **v2.0.0** direkt.

**Vorgehen:** Test-driven. Pro Finding ein roter Test (Regression-Dokument), dann Minimal-Implementation, dann Refactor. Ein PR pro Milestone.

**Reihenfolge:** M0 → M1 → M2 → M3 → M4 → M5 → (danach: separates Repo `icap-flow-bundle`).

---

## Verifizierte Findings

| ID | Finding | Schweregrad | Code-Nachweis | Quelle |
|---|---|---|---|---|
| A | `Encapsulated`-Header hardcoded `null-body=0` | RFC-Blocker | `RequestFormatter.php:28-30` | Claude, Codex, Jules |
| B | Keine HTTP-in-ICAP-Kapselung (`req-hdr`/`res-hdr` fehlen) | RFC-Blocker | `RequestFormatter.php:17-55` | Claude, Jules |
| C | String-Body nicht chunked encoded | RFC-Blocker | `RequestFormatter.php:51-53` | Claude, Jules |
| D | Kein `; ieof`-Terminator im Preview-Flow | RFC-Blocker | `RequestFormatter.php:50` | Claude, Codex, Jules |
| E | `scanFileWithPreview` lädt komplette Datei in RAM | OOM / Streaming-Regression | `IcapClient.php:134` | alle |
| F | `ResponseParser` wertet `Encapsulated`-Header nicht aus | RFC-Blocker | `ResponseParser.php:40-49` | Claude, Codex |
| **G** | **Fail-Open: Status 100 → `ScanResult(isInfected=false)`** | **Security-Critical** | `IcapClient.php:175-177` | Claude |
| H | CRLF-Injection via `$service` | Security | `IcapClient.php:97,116,139` | Claude |
| I | `SynchronousStreamTransport`: hardcoded 5s, kein `finally`, kein Length-Limit | Resource-Leak / DoS | `SynchronousStreamTransport.php:23-30` | Claude, Codex |
| J | Kein TLS (`icaps://`) | Security | `AsyncAmpTransport.php:28` | alle |
| K | Test zementiert Bug A | Test-Qualität | `tests/RequestFormatterTest.php:35-43` | Claude |
| L | Kein `Allow: 204`-Header | RFC-Optimierung | grep leer | Codex, Jules |
| M | Status-Code-Matrix lückenhaft (206, 400, 403, 500, 503) | RFC / Security | `IcapClient.php:158-180` | alle |
| N | Parser ohne Max-Header-Size/Count | DoS | `ResponseParser.php:40-49` | Claude, Codex |

---

## Milestone M0 — Fundament

**Aufwand:** 1–2 PT
**PR:** `chore(v2): exception hierarchy, interfaces, final`

- [ ] **M0.1** Exception-Hierarchie
  - `IcapExceptionInterface` (Marker)
  - `IcapProtocolException extends RuntimeException implements IcapExceptionInterface`
  - `IcapTimeoutException`
  - `IcapClientException` (4xx)
  - `IcapServerException` (5xx)
  - `IcapMalformedResponseException extends IcapProtocolException`
  - Bestehende `IcapConnectionException`, `IcapResponseException` implementieren das Marker-Interface
- [ ] **M0.2** `IcapClientInterface` extrahieren, `IcapClient` implementiert sie
- [ ] **M0.3** `final` setzen: `IcapClient`, `RequestFormatter`, `ResponseParser`, `DefaultPreviewStrategy`, `SynchronousStreamTransport`
- [ ] **M0.4** `#[\Override]`-Attribute an Interface-Implementierungen
- [ ] **M0.5** `revolt/event-loop` explizit in `composer.json:require`
- [ ] **M0.6** Baseline-Tests grün halten, PHPStan L9 clean

---

## Milestone M1 — RFC-3507-Korrektheit

**Aufwand:** 3–4 PT
**PR:** `feat(v2): RFC 3507 wire format (encapsulated, chunked, ieof)`
**Findings:** A, B, C, D, E, F, K, L

- [ ] **M1.1** Neue DTOs `HttpRequest` / `HttpResponse` (method/uri/headers/body) als Encapsulated-Payload-Träger
- [ ] **M1.2** `IcapRequest` erweitern: `?HttpRequest $encapsulatedRequest`, `?HttpResponse $encapsulatedResponse` — `mixed $body` entfernen
- [ ] **M1.3** `RequestFormatter` neu schreiben:
  - Encapsulated-Offsets **berechnen** (`req-hdr=0, req-body=N` usw.)
  - String-UND-Stream-Body chunked encoden
  - `; ieof` bei Preview-Complete-Szenario (Dateigröße ≤ Preview)
  - Streaming-Interface: gibt Iterable/Generator (Chunks) zurück — kein großer String mehr
- [ ] **M1.4** `TransportInterface` erweitern: akzeptiert Iterable<string> für Request, streamt auf Socket
- [ ] **M1.5** `Allow: 204` default im Preview-Flow
- [ ] **M1.6** `ResponseParser` Encapsulated-aware: trennt ICAP-Header / HTTP-Header / HTTP-Body
- [ ] **M1.7** `scanFileWithPreview` streaming-basiert (OOM-Fix, E)
- [ ] **M1.8** Tests komplett neu: `tests/RequestFormatterTest.php` ersetzen; neue Fixtures aus echten c-icap-Wire-Bytes (EICAR, saubere Dateien, Preview-Continue, Preview-Complete-mit-`ieof`)
- [ ] **M1.9** Bug-zementierender Test K zuerst red → dann green mit korrektem Wire-Format

---

## Milestone M2 — Security-Härtung

**Aufwand:** 2 PT
**PR:** `feat(v2): fail-secure, TLS, DoS limits, status matrix`
**Findings:** G, H, I, J, M, N

- [ ] **M2.1** **Fix Fail-Open G:** Status 100 außerhalb Preview-Flow ist `IcapProtocolException`, nicht "clean". Expliziter Regression-Test (Security-Gate).
- [ ] **M2.2** URI-/Service-Validation gegen CRLF-Injection (RFC 3507 §7.3)
- [ ] **M2.3** `Config` erweitern: `tlsContext`, `maxResponseSize` (default 10 MB), `maxHeaderCount` (100), `maxHeaderLineLength` (8 KB)
- [ ] **M2.4** `AsyncAmpTransport` TLS via `Socket\connectTls()` bei `icaps://`-Schema (RFC 3507 verwendet Port 11344 für TLS de-facto)
- [ ] **M2.5** `SynchronousStreamTransport` fix: `$config->getSocketTimeout()`/`getStreamTimeout()` nutzen, `finally { fclose() }`, `stream_set_timeout()`, Read-Length-Limit
- [ ] **M2.6** Parser-Limits durchsetzen (N)
- [ ] **M2.7** Status-Code-Matrix:
  - 100 nur im Preview-Context legitim
  - 204 clean
  - 200 mit `X-Virus-Name`/Vendor-Header prüfen
  - 206 Partial Content
  - 403 → je nach Vendor-Profil als "virus found" interpretierbar
  - 4xx → `IcapClientException` mit vollem Response-Kontext
  - 5xx → `IcapServerException` (503-Retry-fähig)

---

## Milestone M3 — Ökosystem-Fit

**Aufwand:** 2 PT
**PR:** `feat(v2): logger, cancellation, OPTIONS cache, pooling, multi-vendor`

- [ ] **M3.1** PSR-3 `LoggerInterface` optional injizierbar (Connect, Scan-Start, Scan-Result, Timeout als strukturierte Log-Events)
- [ ] **M3.2** `Cancellation` in Public-API von `IcapClient::scanFile` / `request` durchreichen
- [ ] **M3.3** OPTIONS-Response-Cache (PSR-16 optional, TTL aus `Options-TTL`, Key host:port/service)
- [ ] **M3.4** `Config::maxConnections` + einfaches Keep-Alive-Pooling in `AsyncAmpTransport`
- [ ] **M3.5** `Config::virusFoundHeaders: array<string>` (Multi-Vendor: `X-Virus-Name`, `X-Infection-Found`, `X-Violations-Found`)
- [ ] **M3.6** Custom-Request-Header-Support (`X-Client-IP`, `X-Authenticated-User`) über `IcapRequest`
- [ ] **M3.7** Retry-Policy mit Exponential-Backoff für 503

---

## Milestone M4 — Test-Infrastruktur

**Aufwand:** 2 PT
**PR:** `test(v2): integration suite, coverage/mutation gates, PHP 8.4 matrix`

- [ ] **M4.1** `docker-compose.yml` (Projektroot): c-icap + ClamAV-Service + optional Squid
- [ ] **M4.2** `tests/Integration/`: EICAR-Testfile, clean file, preview-continue, preview-complete, 200-with-virus, 503-retry
- [ ] **M4.3** CI-Workflow erweitern: Integration-Job mit Docker-Compose-Services, Matrix `['8.3', '8.4']`
- [ ] **M4.4** `phpunit.xml.dist`: Coverage-Threshold (`enforceCheckCoverage`, `min-lines=90`, `min-branches=85`)
- [ ] **M4.5** `infection/infection` installieren, MSI-Gate ≥ 70 %
- [ ] **M4.6** PHPStan `^1.11` → `^2.1`
- [ ] **M4.7** `roave/security-advisories` in dev-deps (dev-master)
- [ ] **M4.8** `phpbench/phpbench` für Throughput-Benchmarks (Baseline für zukünftige Regressionen)

---

## Milestone M5 — Governance & Doku

**Aufwand:** 1 PT
**PR:** `docs(v2): SECURITY.md, SPDX headers, migration guide, AI disclaimer`

- [x] **M5.1** `SECURITY.md` mit Disclosure-Prozess + AI-Origin-Disclaimer
- [x] **M5.2** SPDX-Header (`SPDX-License-Identifier: EUPL-1.2`) via `.php-cs-fixer.dist.php`-Regel auf allen `src/` + `tests/` Dateien
- [x] **M5.3** `README.md` ehrlich gemacht: prominenter AI-Origin-Disclaimer + Production-Caveat oben, v2-API-Beispiele, `docs/agent.md` als Historical-Document gekennzeichnet
- [x] **M5.4** `docs/migration-v1-to-v2.md` — Breaking-Change-Guide
- [x] **M5.5** `CHANGELOG.md` v2.0.0-Entry, v1.0.0 als deprecated markiert
- [x] **M5.6** Cookbook-Files (`examples/01-sync-scan.php`, `02-async-scan.php`, `cookbook/01..03.php`) auf v2-API umgeschrieben — Multi-Vendor-Header, `executeRaw`-Pattern, Exception-Taxonomie
- [ ] **M5.7** v2.0.0-Tag (separater Commit nach M5-Merge, kein Inhalt im PR)
- [ ] **M5.8** `README.de.md` — deutsche Fassung für Public-Sector-Adoption (verschoben in M5-Follow-up; nicht release-blocking)

---

## Post-v2.0.0 — Separates Repo `icap-flow-bundle`

**Nicht Teil dieser PR-Serie. Start nach Release v2.0.0.**

- Symfony DI-Extension, Autowiring
- Monolog-Channel `icap`
- Console-Commands `icap:options`, `icap:scan`, `icap:health`
- Symfony Profiler DataCollector
- Messenger-Handler für async Upload-Scanning
- Validator-Constraint `#[IcapClean]`
- VichUploader-/OneupUploader-Adapter

---

## Gesamt-Aufwand v2.0.0 Core

**~10–12 PT** für einen Entwickler (M0–M5), ohne Bundle.

## Definition of Done v2.0.0

- [ ] Alle P0-Findings A–N geschlossen mit Regression-Test
- [ ] Coverage ≥ 90 % Lines, ≥ 85 % Branches
- [ ] Infection MSI ≥ 70 %
- [ ] Integration-Tests grün gegen c-icap + ClamAV (EICAR + Clean)
- [ ] CI-Matrix PHP 8.3 + 8.4 grün
- [ ] PHPStan Level 9 clean
- [ ] `composer audit` clean (Runtime + Dev)
- [ ] Migration-Guide + deutsche README publiziert
- [ ] SECURITY.md + SPDX-Header
