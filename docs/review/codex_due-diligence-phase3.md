# Due-Diligence Analyse `ndrstmr/icap-flow` — Phase 3 (RFC‑3507 Compliance-Check)

Stand: 2026-04-24

## Quellenbasis (Phase 3)

- RFC 3507 (ICAP): https://www.rfc-editor.org/rfc/rfc3507
- RFC 3507 Errata (u. a. Preview/`100 Continue` Verhalten): https://www.rfc-editor.org/errata/rfc3507
- Draft `draft-stecher-icap-subid-00` (de-facto Header-Erweiterungen): https://datatracker.ietf.org/doc/html/draft-stecher-icap-subid-00

> **Hinweis**: Diese Phase bewertet den aktuellen Codezustand gegen RFC-Anforderungen punktweise. Fakten und Urteile sind getrennt.

---

## 3.1 Methoden (OPTIONS / REQMOD / RESPMOD)

### Fakten

- `IcapClient::options()` erzeugt `OPTIONS`-Requests (`src/IcapClient.php:95-100`).
- `scanFile()` und `scanFileWithPreview()` nutzen `RESPMOD` (`src/IcapClient.php:117,142,148`).
- API erlaubt `REQMOD` nur indirekt über `request(new IcapRequest('REQMOD', ...))`; es gibt keinen dedizierten Convenience-Flow.
- Auswertung von OPTIONS-Capabilities (`Methods`, `ISTag`, `Max-Connections`, `Options-TTL`, `Transfer-*`) ist nicht implementiert.

### Urteil

- **Teilweise compliant**: Methoden können syntaktisch versendet werden.
- **Nicht compliant auf Feature-Ebene**: Capability Discovery wird nicht semantisch umgesetzt/cached, obwohl RFC‑relevant für robusten Betrieb.

---

## 3.2 Nachrichtenformat (Startzeile, Header, Encapsulated, Chunking, `ieof`)

### Fakten

- Startzeile wird als `METHOD URI ICAP/1.0` formatiert (`src/RequestFormatter.php:22`).
- `Host` wird gesetzt, falls nicht vorhanden (`src/RequestFormatter.php:25-27`).
- `Encapsulated` wird defaultmäßig pauschal auf `null-body=0` gesetzt (`src/RequestFormatter.php:28-30`) — unabhängig vom realen Encapsulation-Typ.
- Bei Stream-Body sendet Formatter ICAP-Chunking (`hex-size`, CRLF, Chunk, CRLF, terminierendes `0\r\n\r\n`) (`src/RequestFormatter.php:40-50`).
- Bei String-Body wird unchunked, roh angehängt (`src/RequestFormatter.php:51-53`).
- `ieof`-Chunk-Extension wird nirgends erzeugt.
- ResponseParser verarbeitet einfache Chunk-Body-Decodierung, aber ohne Encapsulated-Offsetvalidierung (`src/ResponseParser.php:51-63`).

### Urteil

- **Kritischer RFC-Gap**: Encapsulated-Header ist inhaltlich nicht korrekt modelliert (Offsets/sektionen fehlen).
- **Kritischer RFC-Gap**: `ieof`-Semantik für Preview-Ende fehlt.
- **Fragil**: Gemischte Body-Behandlung (Resource chunked, String unchunked) ohne klare RFC-konsistente Regeln.

---

## 3.3 Preview-Feature (`Preview`, `Allow: 204`, `100 Continue`)

### Fakten

- Preview-Request setzt `Preview: <size>` (`src/IcapClient.php:142`).
- Nach Preview wird abhängig von Strategy entschieden (`src/IcapClient.php:144-150`, `src/DefaultPreviewStrategy.php:18-24`).
- `Allow: 204` wird nicht aktiv gesetzt/verhandelt.
- RFC‑spezifische `ieof`-Behandlung fehlt vollständig.
- OPTIONS-basierte Aushandlung der maximalen Preview-Größe ist nicht im Core implementiert (nur Beispielcode liest Header blind aus `options()`-Antwort, `examples/cookbook/03-options-request.php:11-17`).

### Urteil

- **Teilweise implementiert**: Grundidee stop-and-wait + 100/204-Handling vorhanden.
- **Nicht ausreichend RFC‑robust**: Ohne `ieof` und ohne klare `Allow: 204`/OPTIONS-Policy drohen Interop-Probleme mit strikten Servern.

---

## 3.4 Statuscodes

### Fakten

- Explizit behandelt in `interpretResponse()` werden nur `100`, `200`, `204`; alles andere führt zu `IcapResponseException` (`src/IcapClient.php:160-179`).
- Kein differenziertes Handling für `206`, `400`, `403`, `404`, `405`, `408`, `500`, `501`, `502`, `503`, `505`.
- Preview-Strategy default behandelt ebenfalls nur `100` und `204` (`src/DefaultPreviewStrategy.php:20-24`).

### Urteil

- **Nicht ausreichend** für produktionsnahe RFC-Interoperabilität.
- Fehlende Statusdifferenzierung erschwert Recovery-/Retry-Entscheidungen und vendorübergreifendes Verhalten.

---

## 3.5 Robustheit (Malformed Input, Header-Injection, Limits)

### Fakten

- Header-Parsing verwendet `explode(':', $line, 2)` ohne vorherige Validierung des Delimiters (`src/ResponseParser.php:45`).
- Keine expliziten Grenzwerte für Headergröße/Anzahl/Zeilenlänge.
- Keine Validierung auf CRLF-Injection in Headernamen/-werten beim Formatieren (`src/RequestFormatter.php:33-36`).
- Keine Validierung von `Encapsulated`-Offsets bzw. Konsistenzprüfung zwischen Header und Body.
- Parser testet nur einfache Happy-/Basic-Error-Cases (`tests/ResponseParserTest.php:6-30`), kein Fuzz/Property-Test.

### Urteil

- **Sicherheits- und Robustheitsdefizit**: Für untrusted ICAP-Serverantworten fehlt defensive Parsing-Härtung.

---

## 3.6 Kompatibilitätstests gegen ICAP-Server

### Fakten

- Vorhandene Tests sind überwiegend Unit/Mock-basiert; kein Docker-/Integrationstest gegen reale ICAP-Server vorhanden (`tests/*`).
- Es gibt keine nachgewiesenen automatisierten Tests gegen c-icap, Squid, Kaspersky, Symantec, Sophos, TrendMicro etc.
- Beispielskripte gehen von lokalem Server aus, ohne reproduzierbares Test-Setup (`examples/*.php`).

### Urteil

- **Nicht ausreichend belegt** für produktive Interoperabilität im heterogenen Enterprise-Umfeld.

---

## RFC‑3507 Compliance-Checkliste (Phase 3)

| Prüfkriterium | RFC-Erwartung (kurz) | Ist-Stand im Code | Status |
|---|---|---|---|
| OPTIONS vorhanden | Capability Discovery verfügbar | `IcapClient::options()` vorhanden | 🟡 Teilweise |
| OPTIONS ausgewertet | `Methods`, `Preview`, `ISTag`, TTL etc. nutzen | Keine strukturierte Auswertung/Caching | 🔴 Nein |
| REQMOD unterstützt | Request-Modifikation möglich | Nur generisch über `request()` | 🟡 Teilweise |
| RESPMOD unterstützt | Response-Modifikation möglich | `scanFile*` senden RESPMOD | 🟢 Ja (Basis) |
| Korrekte Encapsulation | `Encapsulated` Offsets korrekt | Default `null-body=0`, keine Offset-Berechnung | 🔴 Nein |
| Preview Header | `Preview: n` | Vorhanden in Preview-Flow | 🟢 Ja (Basis) |
| `ieof` Support | korrektes Preview-Ende signalisieren | Nicht implementiert | 🔴 Nein |
| `100 Continue` Pfad | korrektes Continue-Verhalten | Basislogik vorhanden | 🟡 Teilweise |
| `Allow: 204` Nutzung | 204-Optimierung sauber verhandeln | Keine aktive Verhandlung | 🔴 Nein |
| ICAP Statuscode-Matrix | relevante Codes differenzieren | Nur 100/200/204 explizit | 🔴 Nein |
| Parser-Härtung | malformed input robust behandeln | Nur rudimentär, keine harten Limits | 🔴 Nein |
| Interop-Tests realer Server | c-icap/Squid/vendor | Nicht vorhanden | 🔴 Nein |

---

## Konkrete P0/P1-Maßnahmen aus Phase 3

### P0 (vor Produktionsbetrieb)

1. **Encapsulated korrekt implementieren**
   - RequestFormatter muss Encapsulated-Sektionen und Offsets RFC-konform berechnen.
2. **Preview + `ieof` korrekt implementieren**
   - Finale Preview-Chunks mit/ohne `ieof` semantisch korrekt senden.
3. **Statuscode- und Error-Taxonomie ausbauen**
   - ICAP-4xx/5xx und 206 differenziert behandeln.
4. **Parser-Härtung**
   - Header-Parsing defensiv (Delimiter-Checks, Max-Limits, kontrollierte Exceptions).

### P1 (kritisch für belastbare Interoperabilität)

1. OPTIONS-Capabilities als typed DTO + Cache (inkl. `Options-TTL`).
2. `Allow: 204` Policy und serverabhängige Negotiation explizit machen.
3. Reproduzierbare Integrationstests gegen mindestens c-icap + Squid (Docker Compose), danach vendor matrix schrittweise erweitern.

---

## Zwischenfazit Phase 3

**Kurzurteil (nur RFC-Compliance):**
- Der aktuelle Stand ist **funktional nutzbar für einfache Flows**, aber **nicht RFC‑3507-hart** genug für sicherheitskritische Produktion.
- Hauptabweichung ist nicht „ein einzelner Bug“, sondern die Kombination aus
  1) unvollständiger Encapsulation,
  2) unvollständiger Preview/`ieof`-Semantik,
  3) zu schmaler Status-/Fehlermodellierung,
  4) fehlender Interop-Testbasis.
