# Unabhängige Due-Diligence: `ndrstmr/icap-flow` (v1.0.0)

Stand: 2026-04-24

---

## 1) Executive Summary

`ndrstmr/icap-flow` ist eine modern angelegte, schlanke PHP-ICAP-Library mit klarer Grundarchitektur (Transport/Formatter/Parser/Client), guter Readability und solider Ausgangsbasis für Async/Sync-Nutzung. Für einfache Scanszenarien ist sie funktional nutzbar.

Für den Einsatz als **kritische Security-Komponente** in Symfony-basierten Verwaltungsportalen (Upload-Malware-Scanning) ist der aktuelle Stand jedoch **noch nicht ausreichend gehärtet**. Die zentralen Defizite sind: unvollständige RFC-3507-Robustheit (insb. Encapsulated-Offsets, `ieof`, Statuscode-Matrix), fehlendes TLS/`icaps://`, begrenzte Parser-Härtung gegen malformed/untrusted Antworten, fehlende reproduzierbare Interop-Tests gegen reale ICAP-Server sowie fehlendes offizielles Symfony-Bundle.

Positiv: Runtime-Supply-Chain wirkte im Audit unauffällig (`composer audit --locked --no-dev`), Lizenzlage (EUPL-1.2) ist für öffentliche Verwaltung grundsätzlich gut, und die Architektur bietet gute Ansatzpunkte für gezielte Nachhärtung.

**Kernempfehlung:**
1. Erst P0-Sicherheits-/RFC-Punkte schließen.
2. Danach QA-Härtung (Integration, Mutation, Fuzzing, Coverage-Gates).
3. Parallel ein separates `icap-flow-bundle` liefern (DI/Profiler/Messenger/Validator/Observability).

**Gesamt-Readiness:** aktuell **„mit deutlichen Einschränkungen“**.

**TRL-Einschätzung:** **TRL 6** (funktionsfähiger Prototyp/technologischer Demonstrator mit teilweiser Validierung, aber ohne belastbare, reproduzierbare Betriebsvalidierung in produktionsnahen Multi-Vendor-Szenarien).

---

## 2) Repository-Inventar (kompakt)

- Umfang: `src/` 813 LOC, `tests/` 598 LOC, LOC-Test/Code-Ratio ~73,6%.
- Öffentliche API bereits relativ breit (Client, DTOs, Transport, Parser/Formatter, Preview-Strategie).
- Runtime-Dependency schlank (`amphp/socket` + transitive Amp/Revolt-Komponenten).
- CI/Static Analysis/Style-Checks vorhanden (GitHub Actions, PHPStan L9, php-cs-fixer).

---

## 3) Findings nach Dimension (Phase 2–6 konsolidiert)

### Architektur & Sprache

**Fakten**
- Konsistente strict types, readonly DTOs/Config, enum-Nutzung.
- Gute Basistrennung über `TransportInterface`, `RequestFormatterInterface`, `ResponseParserInterface`.
- `IcapRequest::$body` bleibt `mixed`, Preview-Flow liest gesamte Datei in Memory.

**Bewertung**
- Gute Grundlage, aber nicht maximal typsicher/stream-sicher.

### RFC-3507

**Fakten**
- OPTIONS/RESPMOD vorhanden; REQMOD nur generisch.
- Encapsulated-Offsets nicht korrekt modelliert (`null-body=0` als Default), `ieof` nicht implementiert.
- Statushandling fokussiert nur auf 100/200/204.

**Bewertung**
- Für einfache Flows okay, für RFC-harte Interop unzureichend.

### Security/Compliance

**Fakten**
- Parser ohne harte Input-Limits, keine CRLF-Sanitization für Header im Formatter.
- Keine TLS/icaps-Optionen im Config/Transport.
- Lizenz EUPL-1.2 sauber gesetzt; Runtime-Audit aktuell ohne Treffer, Dev-Tree mit Advisories.

**Bewertung**
- Für Security-kritische Behördenpfade derzeit noch P0-Blocker.

### Testing/QA

**Fakten**
- Solide Unit-Basis; keine Mutation/Fuzzing/realen ICAP-Integrationstests.
- Coverage-Report vorhanden, aber ohne harte Gate-Policies.

**Bewertung**
- Funktionale Absicherung okay, Resilienz-/Interop-Nachweis unzureichend.

### Symfony-Ökosystem

**Fakten**
- Kein offizielles Bundle, keine DI-Integration, kein Profiler/Messenger/Validator/Monolog-Channel.

**Bewertung**
- Für Symfony-Landschaft nur mit erhöhtem Integrationsaufwand nutzbar.

---

## 4) ICAP RFC 3507 Compliance-Checkliste (Kurzfassung)

| Bereich | Status | Kurzbegründung |
|---|---|---|
| OPTIONS senden | 🟡 | vorhanden, aber Capability-Auswertung/Caching fehlt |
| REQMOD | 🟡 | nur generisch über `request()` |
| RESPMOD | 🟢 | Basispfad vorhanden |
| Encapsulated korrekt | 🔴 | keine RFC-konforme Offset-Berechnung |
| Preview Header | 🟢 | vorhanden |
| `ieof` | 🔴 | fehlt |
| `Allow: 204` Negotiation | 🔴 | fehlt |
| Statuscode-Matrix | 🔴 | nur 100/200/204 differenziert |
| Parser-Robustheit | 🔴 | fehlende harte Limits/Defensive Parsing |
| Multi-Server-Interop-Tests | 🔴 | nicht reproduzierbar vorhanden |

---

## 5) Wettbewerbsvergleich (Kurzfazit)

- PHP-ICAP-Ökosystem ist klein; `icap-flow` ist modern positioniert.
- Cross-Language zeigt ähnliche Fragmentierung (Go/Python/Node/.NET/Java mit wenigen, teils älteren Clients).
- c-icap bleibt de-facto Referenz für Interop; entsprechende Integrationstests sind Pflicht.

---

## 6) Bewertungsmatrix (0–10 je Dimension, max. 210)

| Dimension | Score | Begründung | Kritische Findings |
|---|---:|---|---|
| Sprachmoderne (PHP 8.3+ Features) | 7 | strict types/readonly/enum vorhanden | kein Override-Attribut, Body-Typ schwach |
| Typsystem / PHPStan-Strenge | 6 | PHPStan L9 ohne Baseline | `mixed` beim zentralen Body-Typ |
| SOLID / Architektur-Klarheit | 7 | gute Layer-Trennung | IcapClient teils überladen |
| Exception-Design | 4 | Basis-Exceptions vorhanden | Taxonomie zu flach |
| PSR-Konformität | 5 | PSR-4/12 okay | PSR-3/20-Hooks fehlen |
| Ressourcen-/Connection-Management | 4 | Sockets werden geschlossen | Timeouts inkonsistent, kein Pooling |
| Async-Implementierung (Revolt/Amphp) | 6 | Future-basierte API vorhanden | fehlende externe Cancellation |
| ICAP RFC 3507 Methoden-Vollständigkeit | 5 | OPTIONS/RESPMOD da | REQMOD nur rudimentär |
| ICAP Preview / 204-Optimierung | 4 | Preview-Basis vorhanden | `ieof`/Allow-204 policy fehlt |
| ICAP Robustheit (Parser, Edge Cases) | 3 | einfache Parserlogik | fehlende Limits/defensive Checks |
| Security-Posture | 4 | Basis okay | kein TLS/icaps, Parserhärtung fehlt |
| Test-Coverage (Lines/Branches) | 5 | Coverage-Reporting da | keine harten Gates/Branchsicht |
| Mutation Testing | 0 | nicht vorhanden | Infection fehlt |
| Integration-Testing gegen echte Server | 1 | keine reproduzierbare Matrix | c-icap/Squid/vendor nicht automatisiert |
| CI-Pipeline-Qualität | 6 | lint+stan+tests+audit vorhanden | geringe Matrix/keine harten QA-Gates |
| Dokumentation (README, docs/, Docblocks) | 7 | gut lesbar, viele Dokuartefakte | einige Behauptungen ohne Nachweise |
| Example-/Cookbook-Qualität | 6 | mehrere Beispiele vorhanden | kaum produktionsnahe Edge-Cases |
| Symfony-Bundle-Integration | 1 | kein Bundle | DI/Profiler/Messenger fehlen |
| Observability / Profiler / Logging | 1 | keine dedizierten Hooks | kein Logger/Telemetry-Konzept |
| Release-Management / Semver / Changelog | 6 | Changelog + SemVer-Claim | geringe Historie, v1.0.0 jung |
| Public-Sector-Fit (EUPL, BSI, Souveränität) | 5 | EUPL stark, OSS-Basis gut | Härtung/Artefakte für Behörden fehlen |
| **Gesamt-Readiness-Score** | **98 / 210** | **technisch brauchbare Basis, aber deutliche Produktionslücken** | **mehrere P0-Punkte offen** |

---

## 7) Produktionsreife-Gate

### 7.1 Ist die Bibliothek heute produktionsreif?

- **Interne Tools / Prototypen:** **Ja, mit Einschränkungen**.
- **Symfony-Applikationen in Dataport-CCW-Projekten (TYPO3/Shopware/Bürgerportale):** **Mit Einschränkungen (deutlich)**.
- **Kritische Security-Komponente (Virenscan auf Upload):** **Nein (aktuell)**.

### 7.2 Warum?

- P0-Blocker: TLS/icaps, Parser-Härtung mit Limits, RFC-Encapsulation/`ieof`, Interop-Nachweise gegen reale Server.
- Fehlender Symfony-Produktionsfit (Bundle/Profiler/Messenger/Validator/Observability).

---

## 8) Priorisierte Gap-Liste

### P0 (Blocker vor produktiv-kritischem Einsatz)

1. RFC-konforme Encapsulated-Offsets + `ieof` implementieren.
2. TLS/icaps inkl. Zertifikatsprüfung einführen.
3. Parser defensiv härten (Limits, malformed header handling, klare Exceptions).
4. Timeouts konsistent (sync + async) und stream-sicheres Preview ohne Full-Buffer.
5. Reproduzierbare Integrationstests gegen c-icap (+ ideal Squid) in CI.

### P1 (kritisch für Ökosystem-Fit)

1. Exception-Taxonomie ausbauen (Protocol/Timeout/Malformed/Status).
2. Mutation/Fuzzing einführen; Coverage-/Branch-Gates härten.
3. Separates `icap-flow-bundle` mit DI, Console, Messenger, Profiler, Validator.
4. PSR-3 Logging + Redaction-Regeln, Observability-Hooks (OTel/Prometheus).

### P2 (Differenzierung)

1. Multi-Client Registry, Health-Checks, Vendor-Kompatibilitätsprofile.
2. Performance-Benchmarks + Regression-Budgets.
3. Erweiterte Cookbook-Szenarien (Fail-Closed/Fail-Open, Retry-Policy, Large-file streaming).

### P3 (Langfristige Vision)

1. Öffentliche Interop-Benchmark-Suite.
2. Dokumentierte Vendor-Matrix (c-icap/Squid/Kaspersky/Symantec/Sophos etc.).
3. Governance-Artefakte für Behördenadoption (SECURITY.md, SBOM, Disclosure-Prozess).

---

## 9) Roadmap

### v1.1.x (sofort: Bugfix/Security/DX)

- PR-Set A: Parser-Härtung + Header-Sanitization + klare Parse-Exceptions.
- PR-Set B: Sync-Timeout-Fix (`Config` vollständig anwenden), stream-safe Preview-Basis.
- PR-Set C: TLS/icaps Transportkonfiguration (mind. verify_peer default true).
- PR-Set D: CI-Härtung (Coverage gates, stricter PHPUnit flags).

### v1.2.0 (Minor, ohne BC-Bruch)

- Encapsulated/Preview (`ieof`) vollständig und testbar.
- Integrationstest-Pipeline (c-icap/Squid via Docker).
- Erweiterte Status-/Exception-Matrix.
- Beobachtbarkeit: optionale Event-Hooks + PSR-3.

### v2.0.0 (Major, sauber dokumentierte Breaking Changes)

- API-Bereinigung:
  - präziser Body-Typ statt `mixed`,
  - klarere Client-Schnittstellen (async/sync getrennt oder strikt policy-gesteuert),
  - ggf. neue typed Options/Capabilities DTOs.
- Vollständig dokumentiertes Fehler-/Retry-Modell.
- Stabilitätszusagen für erweiterte Public API.

### Begleit-Repo `icap-flow-bundle`

- Start parallel zu `v1.2.0` sinnvoll (spätestens mit stabilen Core-Hooks).
- `v0.1` mit DI + Console + Monolog, `v0.2` mit Profiler/Messenger, `v1.0` mit Validator/Health/Recipe-Reife.

---

## 10) Quellenverzeichnis

### Repositorium (primär)

- `src/*`, `tests/*`, `examples/*`, `.github/workflows/ci.yml`, `composer.json`, `composer.lock`, `phpunit.xml.dist`, `phpstan.neon`, `CHANGELOG.md`, `README.md`, `LICENSE`.

### Externe Quellen (primär)

- RFC 3507: https://www.rfc-editor.org/rfc/rfc3507
- RFC 3507 Errata: https://www.rfc-editor.org/errata/rfc3507
- draft-stecher-icap-subid-00: https://datatracker.ietf.org/doc/html/draft-stecher-icap-subid-00
- Symfony Docs:
  - https://symfony.com/doc/current/bundles/configuration.html
  - https://symfony.com/doc/current/messenger/.html
  - https://symfony.com/doc/current/profiler.html
  - https://symfony.com/doc/current/logging.html
- c-icap:
  - https://c-icap.sourceforge.net/
  - https://github.com/c-icap/c-icap-server
  - https://manpages.debian.org/unstable/c-icap/c-icap-client.8.en.html
- Public Sector / Compliance:
  - https://www.bsi.bund.de/grundschutz
  - https://eur-lex.europa.eu/eli/reg/2016/679/oj
  - https://interoperable-europe.ec.europa.eu/collection/eupl/eupl-text-eupl-12
  - https://opencode.de/
