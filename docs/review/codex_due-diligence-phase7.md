# Due-Diligence Analyse `ndrstmr/icap-flow` — Phase 7 (Wettbewerbsvergleich)

Stand: 2026-04-24

## Quellenbasis (extern)

- Packagist: `ndrstmr/icap-flow` und `nathan242/php-icap-client`
- Go Packages: `opencloud-eu/icap-client`, `solidwall/icap-client`
- PyPI: `icapclient`, `pyicap`
- npm: ICAP-Paketsuche / `expansejs-icap`
- NuGet: `IcapClient`
- Maven/Java: `toolarium-icap-client`, `Waarp IcapClient`
- c-icap Referenz:
  - Projektseite: https://c-icap.sourceforge.net/
  - GitHub: https://github.com/c-icap/c-icap-server
  - Debian Manpage `c-icap-client`: https://manpages.debian.org/unstable/c-icap/c-icap-client.8.en.html

> **Wichtig:** Der ICAP-Client-Markt ist klein und fragmentiert. Für mehrere Ökosysteme existieren nur wenige, teils ältere Pakete. Aussagen zur „Popularität“ sind daher bewusst konservativ formuliert.

---

## 7.1 PHP-Welt (Packagist)

### Faktische Marktbeobachtung

- In Packagist ist die Auswahl an dedizierten ICAP-Client-Libraries sehr klein.
- Als direkt vergleichbares Paket ist v. a. `nathan242/php-icap-client` sichtbar (historisch, PHP >=5.3, letzte stabile Version 0.5.1).
- Gesuchte historische Namen (`m4c/icap-client`, `wiseacre/icap`) waren im aktuellen öffentlichen Index nicht belastbar auffindbar.

### Vergleichstabelle (PHP)

| Kriterium | `ndrstmr/icap-flow` | `nathan242/php-icap-client` |
|---|---|---|
| PHP-Target | modern (>=8.3) | legacy-kompatibel (>=5.3) |
| API-Modell | DTO + Async/Sync-Wrapper | eher prozedural/array-orientiert |
| Async-Fähigkeit | ja (Amp/Revolt-basiert) | nein (klassisch blocking) |
| Architektur | Transport/Formatter/Parser abstrahiert | einfacher monolithischer Clientstil |
| Test-/QA-Anspruch | PHPStan/CI/Tests vorhanden | deutlich geringerer Modernisierungsgrad |
| RFC-Härtung (nach Phase 3) | teilweise, mit klaren Gaps | ebenfalls kein Hinweis auf vollständige RFC-Härtung |

### Kurzfazit PHP

- Innerhalb der sichtbaren PHP-Landschaft ist `icap-flow` modern aufgestellt und architektonisch klar überlegen.
- Der reale Wettbewerb in PHP ist aktuell eher „dünn“, was eine Chance für De-facto-Standardisierung ist — sofern RFC-/Security-/Interop-Gaps geschlossen werden.

---

## 7.2 Andere Sprachen als Benchmark

### Vergleichsüberblick

| Sprache | Beispielprojekt(e) | Beobachtung | Relevanz für `icap-flow` |
|---|---|---|---|
| Go | `opencloud-eu/icap-client`, `solidwall/icap-client` | mehrere kleine ICAP-Client-Implementierungen mit einfacher `Do(Request)`-API | zeigt pragmatische, schlanke Client-Patterns |
| Python | `icapclient` (PyPI), `pyicap` (server-fokussiert) | teils alte Pakete; client-seitig begrenzte moderne Auswahl | bestätigt Fragmentierung im ICAP-Ökosystem |
| Node.js | npm keyword `icap`, `expansejs-icap` | wenige Pakete, teils alt/unklar gepflegt | keine klare „Goldstandard“-Implementierung |
| .NET | NuGet `IcapClient` | einzelne Pakete vorhanden, aber geringe Markttiefe | ähnliches Fragmentierungsbild wie PHP |
| Java | `toolarium-icap-client`, `Waarp IcapClient` | einige dedizierte Clients mit API-Dokumentation | nützlich als Feature-Referenz (z. B. Preview-Konfig, receive length) |

### Technische Muster, die man in anderen Stacks häufiger sieht

1. **Klare Request/Response-Objekte + `Do(req)`-Signatur** (besonders Go/Java).
2. **Konfigurierbare Preview-/Receive-Limits** als first-class API.
3. **Kleinere, fokussierte Libraries** statt „Framework im Framework“.

### Übertragbarer Nutzen für `icap-flow`

- `icap-flow` sollte die eigene Stärken (moderne PHP-Typisierung + Async) halten, aber die API um robuste Protokollfunktionen (Encapsulated, ieof, Status-/Fehlerklassen) vollständig schließen.

---

## 7.3 c-icap als Referenzimplementierung

### Fakten

- c-icap ist seit Jahren die verbreitetste Open-Source-Referenz im ICAP-Umfeld (Projektseite + GitHub + Tooling/Manpages).
- `c-icap-client`-Tooling zeigt viele praxisrelevante Schalter:
  - TLS (`-tls`, `-tls-no-verify`),
  - Preview/204/206-bezogene Flags (`-nopreview`, `-no204`, `-206`),
  - Header-Injektion für Testzwecke (`-x`, `-hx`, `-rhx`).

### Benchmark-Bedeutung für `icap-flow`

- c-icap liefert einen de-facto Referenzrahmen für:
  1. Interop-Verhalten,
  2. Protokoll-Edge-Cases,
  3. CLI-gestützte Diagnosepfade.
- Für `icap-flow` bedeutet das: Integrationstests gegen c-icap sind kein Nice-to-have, sondern Pflichtbestandteil vor „Security-Kontrollpunkt“-Einsatz.

---

## Ehrlicher Gesamtvergleich: Wo `icap-flow` heute steht

### Stärken gegenüber Markt

- Moderne PHP-Basis (8.3+, readonly/enums, Async-ready, Test/CI-Grundgerüst).
- Architektonische Zerlegung (Transport/Formatter/Parser/Strategy) ist für PHP-ICAP überdurchschnittlich.

### Lücken gegenüber „Best-in-class“-Anspruch

- RFC-Edge-Cases (Encapsulated-Offsets, `ieof`, Statusmatrix) noch unvollständig.
- Fehlende reproduzierbare Interop-Matrix (c-icap/Squid/Vendor).
- Kein offizielles Symfony-Bundle trotz klarer Zielgruppe im Symfony-Ökosystem.

### Wettbewerbsurteil (Phase 7)

- **Im PHP-Markt bereits vorne**, aber noch **nicht unangreifbar produktionsreif** für kritische Behörden-Use-Cases.
- Der Weg zur Marktführerschaft ist realistisch, wenn die Phase-3/4/5/6-P0-Pakete konsequent umgesetzt werden.

---

## Konkrete Next Steps aus dem Wettbewerbsvergleich

1. **Kurzfristig (P0):** RFC-Vollständigkeit + c-icap-Integrationstests.
2. **Mittelfristig (P1):** Symfony-Bundle + Observability + strengere QA-Gates (Coverage/Mutation/Fuzzing).
3. **Strategisch (P2/P3):** dokumentierte Vendor-Kompatibilitätsmatrix und öffentliche Benchmark-Suite.
