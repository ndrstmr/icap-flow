# Due-Diligence Analyse `ndrstmr/icap-flow` — Phase 5 (Testing & Qualitätssicherung)

Stand: 2026-04-24

---

## 5.1 Testinventar & Testarchitektur

### Fakten

- Test-Stack: Pest/PHPUnit laut `composer.json` (`pestphp/pest`, `phpunit/phpunit`) und `phpunit.xml.dist`.
- Testdateien sind in einer einzigen Suite `./tests` gebündelt (`phpunit.xml.dist:6-10`).
- Es gibt keine explizite Trennung in `unit`, `integration`, `functional` Suites.
- Quantitativ enthält das Repo aktuell **29 Testfälle** (`it(...)`/`test(...)`) über die vorhandenen Testdateien.
- `tests/IcapClientTest.php` trägt den größten Anteil der inhaltlichen Orchestrierungs-Tests.

### Bewertung

- **Positiv**: Für ein kleines Library-Projekt ist eine solide Basis vorhanden.
- **Kritisch**: Fehlende Suite-Trennung erschwert gezielte CI-Stufen (schnell/isoliert vs. langsam/integration).

### Maßnahmen

- P1: PHPUnit-Suites in `unit`, `integration`, `functional` aufteilen.
- P1: CI so aufteilen, dass Unit bei jedem Push läuft, Integration z. B. via Matrix/Nightly.

---

## 5.2 Coverage-Analyse (Lines/Branches/Methods)

### Fakten

- CI erzeugt HTML-Coverage (`.github/workflows/ci.yml:45-55`, `phpunit.xml.dist:17-21`).
- Ein harter Mindestwert (z. B. `--min`) oder `failOn*` Coverage-Gates ist nicht konfiguriert.
- `CHANGELOG.md` behauptet „>80% coverage“, aber im Repo gibt es keinen versionierten Coverage-Snapshot als Beleg.
- Lokale Reproduktion der Coverage in dieser Umgebung war nicht möglich, da `composer install` wegen PHP-8.5.3-dev vs. Lockfile-Constraints fehlschlug.

### Bewertung

- **Neutral**: Coverage-Reporting existiert.
- **Kritisch**: Ohne harte Coverage-Gates ist Regression leicht möglich.
- **Kritisch**: Branch-/Path-Coverage für Parser/Protocol-Ecken ist nicht sichtbar gemacht.

### Maßnahmen

- P0: Coverage-Gates in CI ergänzen (z. B. min line/method/branch).
- P1: Coverage-Summary als PR-Check annotieren.

---

## 5.3 Qualität der bestehenden Tests (inhaltlich)

### Fakten

- Gute Basistests für DTO-Immutability (`tests/DTO/*`) und Formatter/Parser-Happy-Path (`tests/RequestFormatterTest.php`, `tests/ResponseParserTest.php`).
- `IcapClient`-Orchestrierung ist gut mit Mocks getestet (Formatter/Transport/Parser/PreviewStrategy).
- Negative/Edge-Cases sind vorhanden, aber begrenzt (z. B. malformed status line, connection failure).
- Stark unterrepräsentiert bzw. fehlend:
  - robuste Parser-Fuzz-/Property-Cases,
  - Header-Injection/CRLF-Tests,
  - große Payload-/Timeout-Stresstests,
  - echte Server-Integrationstests.

### Bewertung

- **Positiv**: Unit-Tests decken Kernpfade und API-Verhalten ab.
- **Kritisch**: Sicherheits- und Protokollrandfälle sind deutlich untertestet.

### Maßnahmen

- P0: Parser-Edge-Case-Suite (ungültige Header, zu lange Zeilen, inkonsistente chunk data).
- P1: Property-based Tests für Parser/Formatter (roundtrip/robustness).

---

## 5.4 Mutation Testing (Infection)

### Fakten

- Kein `infection/infection` in `composer.json`.
- Keine `infection.json`/`infection.php` Konfiguration im Repo.
- Keine MSI-/Covered MSI-Kennzahlen in CI oder Doku.

### Bewertung

- **Kritisch**: Aussagekraft der Tests gegenüber logischen Mutationen ist derzeit unbekannt.

### Maßnahmen

- P1: Infection einführen und Zielwerte setzen (z. B. MSI >= 75, Covered MSI >= 85 als Start).
- P1: Mutations-Profile für Parser/Protocol-Code priorisieren.

---

## 5.5 Integrationstests (reale ICAP-Server)

### Fakten

- Keine Docker-Compose/Testcontainer-Setups für c-icap/Squid im Repo.
- Keine automatisierten Interop-Tests gegen reale ICAP-Produkte in `tests/`.
- Beispiele (`examples/*.php`) sind manuell ausführbar, aber kein reproduzierbares CI-Integrationssetup.

### Bewertung

- **Blocker für Produktionsvertrauen** bei heterogenen Zielumgebungen.

### Maßnahmen

- P0: Integrationstest-Stufe mit c-icap + ggf. squidclamav in Docker hinzufügen.
- P1: Erweiterung auf mindestens einen kommerziellen Vendor in separater, optionaler Pipeline.

---

## 5.6 Benchmarking / Performance-Regressionsschutz

### Fakten

- Kein `phpbench/phpbench` und kein `benchmarks/`-Verzeichnis.
- Keine Performance-Budgets in CI.

### Bewertung

- **Mittel**: Für Security-Scanning-Pfade ist Latenz/Throughput relevant; aktuell kein Schutz gegen Performance-Regression.

### Maßnahmen

- P2: phpbench-Baselines für `RequestFormatter`, `ResponseParser`, große Streams, Parallelität.

---

## 5.7 Fuzzing / Robustheits-Testing

### Fakten

- Kein Fuzzing-Setup erkennbar (keine Harnesses/Corpus/CI-Job).
- Parser ist string-basiert und damit ein natürlicher Kandidat für fuzz/property tests.

### Bewertung

- **Kritisch**: Untrusted-Input-Komponente ohne Fuzz-Disziplin erhöht Risiko für ungeplante Failure-Modes.

### Maßnahmen

- P1: Parser-Fuzzing (z. B. generated malformed ICAP responses) ergänzen.

---

## 5.8 Test-Determinismus & Flakiness

### Fakten

- Viele Tests arbeiten mock-basiert → grundsätzlich deterministisch.
- Async-Tests nutzen Revolt EventLoop (`tests/AsyncTestCase.php:10-14`).
- Einige Transporttests hängen von Netzwerkfehlern ab (invalid host/port), was umgebungsabhängig sein kann (`tests/Transport/*TransportTest.php`).

### Bewertung

- **Überwiegend stabil**, aber Netzwerknegativtests können in exotischen Umgebungen flaky sein.

### Maßnahmen

- P2: Netzwerkfehlerfälle mit lokalen Fake-Servern deterministischer machen.

---

## 5.9 PHPUnit-/Pest-Konfigurationshärte

### Fakten

- `phpunit.xml.dist` enthält keine strikten Flags wie `failOnRisky`, `failOnWarning`, `beStrictAboutOutputDuringTests`, Deprecation-Tracking.
- Suite und Coverage sind konfiguriert, aber ohne harte Qualitätsgates.

### Bewertung

- **Kritisch**: QA-Konfiguration ist funktional, aber nicht maximal streng für Enterprise-Library-Anspruch.

### Maßnahmen

- P1: Strict PHPUnit-Flags aktivieren.
- P1: Deprecation-Handling und Error-Conversion-Policy dokumentieren.

---

## Coverage-/Mutation-/Integration-Gap-Matrix

| Bereich | Ist-Stand | Reifegrad | Gap |
|---|---|---|---|
| Line Coverage | vorhanden (Report) | 🟡 | kein harter Mindestwert |
| Branch Coverage | unklar/nicht ausgewiesen | 🔴 | fehlende Branch-Gates |
| Mutation Testing | nicht vorhanden | 🔴 | Infection fehlt |
| Integration c-icap/Squid | nicht vorhanden | 🔴 | keine reale Interop-Pipeline |
| Vendor-Interop | nicht vorhanden | 🔴 | keine Nachweise |
| Fuzz/Property Tests | nicht vorhanden | 🔴 | Parser-Robustheit unbewiesen |
| Performance Regression | nicht vorhanden | 🟡/🔴 | kein Benchmarking |

---

## Phase-5 Zwischenfazit

Die aktuelle Testbasis ist für **funktionale Unit-Absicherung** ordentlich, aber für den Anspruch „produktive, sicherheitskritische ICAP-Kernbibliothek“ fehlen zentrale QA-Säulen: **harte Coverage-Gates, Mutation Testing, reproduzierbare Integrationstests gegen reale ICAP-Server und Fuzzing**. Daraus ergibt sich für Produktionsreife aktuell ein klarer P0/P1-Nachrüstbedarf.
