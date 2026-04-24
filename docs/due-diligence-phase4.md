# Due-Diligence Analyse `ndrstmr/icap-flow` — Phase 4 (Security & Compliance)

Stand: 2026-04-24

## Quellenbasis (extern)

- BSI IT-Grundschutz-Kompendium (Startseite): https://www.bsi.bund.de/grundschutz
- BSI C5/Cloud & Sicherheitsleitlinien (allg. Orientierung): https://www.bsi.bund.de
- GDPR / DSGVO Volltext (EUR-Lex): https://eur-lex.europa.eu/eli/reg/2016/679/oj
- EUPL 1.2 (EU-Seite): https://interoperable-europe.ec.europa.eu/collection/eupl/eupl-text-eupl-12
- OpenCoDE Plattform: https://opencode.de/
- Composer Security Advisories: `composer audit --locked` / `composer audit --locked --no-dev`

---

## 4.1 Security-Assessment

### 4.1.1 Input Validation / Parser-Härtung

#### Fakten

- `ResponseParser` parst Headerzeilen per `explode(':', $line, 2)` ohne Delimiter-Guard (`src/ResponseParser.php:45`), was bei malformed Zeilen zu unsauberem Verhalten führen kann.
- Es gibt keine expliziten Maximalgrenzen für Header-Anzahl, Header-Größe, Statusline-Länge oder Body-Größe (`src/ResponseParser.php:21-67`).
- `RequestFormatter` übernimmt Headernamen/-werte ungefiltert in den Raw-Request (`src/RequestFormatter.php:33-36`) und verhindert keine CRLF-Injection.

#### Bewertung

- **Kritisch**: Bei untrusted ICAP-Servern fehlt defensive Parsing-Härtung (DoS-/Malformed-Response-Risiko).
- **Kritisch**: Outbound Header-Injection ist möglich, wenn aufrufender Code unvalidierte Header durchreicht.

#### Maßnahmen

- P0: Strict Header-Parser mit Längenlimits, Delimiter-Checks und kontrollierter `IcapMalformedResponseException`.
- P0: Request-Header sanitizen (CRLF verbieten, RFC-konforme Headernamen erzwingen).

---

### 4.1.2 Integer-/Offset-Sicherheit & RFC-Strukturfelder

#### Fakten

- Encapsulated-Offsets werden nicht berechnet/validiert (Default immer `null-body=0`) (`src/RequestFormatter.php:28-30`).
- Parser wertet `Encapsulated` nicht strukturell aus (`src/ResponseParser.php:40-66`).

#### Bewertung

- **Kritisch**: Fehlende Offset-Validierung kann zu Protokollinkonsistenz und Parser-Fehlinterpretationen führen.

#### Maßnahmen

- P0: Typed `Encapsulated`-Parser mit Bereichsprüfungen (`>=0`, monotone Offsets, Header/Body-Konsistenz).

---

### 4.1.3 DoS-Resistenz / Ressourcensteuerung

#### Fakten

- Kein Schutz gegen sehr große Antworten (kein Hard-Limit beim Lesen in String-Akkumulatoren) in Async- und Sync-Transport (`src/Transport/AsyncAmpTransport.php:37-40`, `src/Transport/SynchronousStreamTransport.php:29`).
- `scanFileWithPreview` lädt komplette Datei in den Speicher (`file_get_contents`) (`src/IcapClient.php:134`).
- Sync-Transport ignoriert konfigurierbare Stream-Timeouts (`src/Transport/SynchronousStreamTransport.php:23` mit hartem `5`, kein `stream_set_timeout`).

#### Bewertung

- **Kritisch**: Speicher- und Timeout-Strategie ist nicht robust für große Payloads/Slowloris-ähnliche Gegenstellen.

#### Maßnahmen

- P0: Globales Maximum für Response-Bytes + Header-Bytes einführen.
- P0: Preview-Workflow stream-basiert umsetzen (kein Full-Buffer Read).
- P0: Sync-Timeouts über `Config` vollständig anwenden.

---

### 4.1.4 TLS / `icaps://` / Zertifikatsprüfung

#### Fakten

- Transporte bauen ausschließlich `tcp://host:port` auf (`src/Transport/AsyncAmpTransport.php:28`, `src/Transport/SynchronousStreamTransport.php:22`).
- `Config` enthält keine TLS-Parameter (CA, Peer-Verifikation, SNI, Client-Cert) (`src/Config.php:18-24`).

#### Bewertung

- **Blocker für viele Behörden-Setups**: Ohne `icaps://` bzw. TLS-Konfigurationsoptionen ist Betrieb in streng abgesicherten Netzen oft nicht zulässig.

#### Maßnahmen

- P0: TLS-fähige Transportkonfiguration (`tls://`/Amp TLS context), standardmäßig mit Peer-Verification ON.
- P1: Zertifikat-Pinning optional ergänzen.

---

### 4.1.5 Credentials/Secrets/Logging

#### Fakten

- Es gibt keine integrierte Auth-Schicht im Core (kein dedizierter Auth-Header-Helper).
- Es gibt zugleich keine Logger-Integration (kein PSR-3), daher auch keine eingebauten Redaction-Regeln.

#### Bewertung

- **Gemischt**: Kein eingebautes Secret-Leakage durch Logging im Core; aber fehlende Logging-Hooks erschweren sichere Operationalisierung.

#### Maßnahmen

- P1: PSR-3 Logger optional integrieren + Default-Redaction für sensible Header (`Authorization`, `Proxy-Authorization`, vendor tokens).

---

### 4.1.6 Dependency Security / Supply Chain

#### Fakten

- `composer audit --locked` meldet am 2026-04-24 zwei Advisories im **dev-Tree** (`phpunit/phpunit`, `symfony/process`).
- `composer audit --locked --no-dev` meldet keine Advisories für Runtime-Abhängigkeiten.
- Direkte Runtime-Abhängigkeit ist schlank (`amphp/socket` + Transitives, `composer.json`).

#### Bewertung

- **Runtime aktuell unauffällig** (laut Audit-Stand vom 24.04.2026).
- **Dev-Supply-Chain** ist verwundbar und sollte zeitnah aktualisiert werden, um CI/Contributor-Umfeld zu härten.

#### Maßnahmen

- P1: Dev-Dependencies auf advisories-freie Versionen anheben (insb. PHPUnit >= 11.5.50, Symfony Process >= 7.3.11).
- P1: Dependabot/Renovate + Security-Update-Policy verbindlich dokumentieren.

---

## 4.2 Public-Sector Compliance (BSI / DSGVO / EUPL / Digitale Souveränität)

### 4.2.1 EUPL-1.2

#### Fakten

- Repository-Lizenz ist `EUPL-1.2` in `composer.json` gesetzt.
- Volltext-Lizenzdatei ist vorhanden (`LICENSE`).
- Es gibt keine konsistenten Lizenzheader in Source-Dateien.

#### Bewertung

- **Grundsätzlich geeignet** für öffentliche Verwaltung (stark positives Signal).
- **Verbesserungspotenzial**: SPDX-Header pro Datei/Template für klare Nachnutzbarkeit.

#### Maßnahmen

- P1: SPDX-Header-Policy (`SPDX-License-Identifier: EUPL-1.2`) für neue Dateien.

---

### 4.2.2 BSI-Grundschutz-Nähe (technisch)

#### Fakten

- Security-Funktionen für robusten produktiven Netzbetrieb sind noch unvollständig: fehlendes TLS/`icaps://`, fehlende Parser-Limits, begrenzte Fehler-/Statusmodellierung (siehe oben).
- CI enthält `composer audit`, `phpstan`, Tests (`.github/workflows/ci.yml:36-47`).

#### Bewertung

- **Teilweise anschlussfähig**, aber aktuell nicht auf dem Härtungsniveau, das in hochkritischen Schutzbedarfen typischerweise erwartet wird.

#### Maßnahmen

- P0/P1 aus 4.1 als Voraussetzung für behördentauglichen Einsatz in Security-kritischen Upload-Workflows übernehmen.
- Ergänzend: dokumentiertes Threat Model + Betriebshandbuch (Timeouts, Retry, Fail-Closed/Fallback-Policy).

---

### 4.2.3 DSGVO-Relevanz

#### Fakten

- Der Core loggt selbst keine Inhaltsdaten.
- Gescannte Inhalte werden an externe ICAP-Server übertragen (durch Library-Funktionalität inhärent).
- Es fehlt eine Datenschutz-/Datenfluss-Dokumentation (z. B. welche Metadaten/Header typischerweise versendet werden sollten/nicht sollten).

#### Bewertung

- **DSGVO-konform betreibbar**, aber nur mit sauberer Betriebsdokumentation und datenschutzkonfigurierten Aufrufern.

#### Maßnahmen

- P1: Privacy-Hinweise im README (Datenminimierung, Header-Whitelist, Protokollierung ohne Payload).
- P2: optionale Callback-Hooks für Audit-Events mit Redaction.

---

### 4.2.4 Digitale Souveränität / OpenCoDE-Fit

#### Fakten

- Abhängigkeiten sind Open-Source-Pakete; keine SaaS-Laufzeitabhängigkeit.
- Repo-Struktur und Lizenz sind grundsätzlich OpenCoDE-fähig.
- Es fehlen bisher Verwaltungsartefakte wie SBOM, SECURITY.md, klarer Responsible-Disclosure-Prozess.

#### Bewertung

- **Gute Basis**, aber für institutionelle Nachnutzung sollten Governance-Artefakte ergänzt werden.

#### Maßnahmen

- P1: `SECURITY.md`, `SUPPORT.md`, `CODEOWNERS`, SBOM (CycloneDX) ergänzen.
- P2: OpenCoDE-Metadaten und Bereitstellungsvorlagen hinzufügen.

---

## Phase-4 Scorecard (nur Security/Compliance)

| Bereich | Einschätzung | Begründung |
|---|---|---|
| Parser/Protocol Hardening | 🔴 Kritisch | Fehlende Limits, fehlende strukturierte Encapsulated-Validierung |
| Transport Security (TLS/icaps) | 🔴 Kritisch | Keine TLS-Optionen im Core |
| Runtime Dependency Security | 🟢 Derzeit gut | `composer audit --no-dev` ohne Treffer |
| Dev Supply-Chain | 🟡 Mittel | Advisories in Dev-Tree vorhanden |
| Lizenz-Compliance (EUPL) | 🟢 Gut | EUPL gesetzt + LICENSE vorhanden |
| DSGVO-Betriebsfähigkeit | 🟡 Mittel | technisch möglich, aber Doku/Leitplanken fehlen |
| BSI-Nähe | 🟡 bis 🔴 | Härtungsmaßnahmen vor produktiv-kritischem Einsatz nötig |
| Digitale Souveränität | 🟢 Basis gut | OSS-Stack, keine SaaS-Abhängigkeit |

---

## Zwischenfazit Phase 4

Für **allgemeine Integrationen** ist das Projekt sicherheitstechnisch auf gutem Weg, aber für **kritische Behörden-Use-Cases (Upload-Malware-Scanning als Security-Kontrollpunkt)** bestehen noch klare P0-Blocker: TLS/icaps, Parser-Limits, Encapsulated-Validierung, stream-sichere Verarbeitung und robuste Timeout-Policy.
