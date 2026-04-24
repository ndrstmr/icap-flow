## 1. Executive Summary

Das Paket `ndrstmr/icap-flow` (v1.0.0) präsentiert sich auf den ersten Blick als modernes, asynchrones PHP 8.3-Paket auf Basis von Amphp v3/Revolt. Die grundlegende Architektur (Trennung von Transport, Formatierung und Parsing) und die Nutzung moderner Sprachfeatures zeigen gute Ansätze. **Jedoch ist die Bibliothek in ihrem aktuellen Zustand nicht produktionsreif und verfehlt die eigenen Ziele (laut `docs/agent.md`) fundamental.**

Der kritischste Befund betrifft die **ICAP-Protokoll-Compliance (RFC 3507)**: Die Bibliothek sendet keine eingekapselten HTTP-Nachrichten (weder HTTP-Request noch HTTP-Response-Header) und setzt den zwingend erforderlichen `Encapsulated`-Header pauschal falsch (`null-body=0`), selbst wenn ein Body gesendet wird. Weiterhin konterkariert der `RequestFormatter` das beworbene "Efficient streaming", indem er File-Streams komplett in den Arbeitsspeicher lädt (OOM-Gefahr bei großen Dateien). Zudem fehlt jegliche Symfony-Integration (PSR-3 Logging, DI-Tags, Profiler), was den Einsatz im Behördenumfeld (Dataport/CCW) derzeit ausschließt.

**Technologischer Reifegrad (TRL): 4 (Technologie im Labor validiert)**
Empfehlung: Nicht in Produktion einsetzen, bevor die in P0 genannten RFC-Verstöße und Streaming-Fehler behoben sind. Für einen reibungslosen Einsatz in Symfony-Portalen muss ein dediziertes Bundle geschaffen werden.

---

## 2. Repository-Inventar

*   **Umfang:** `src/`: 17 Dateien (~550 LOC) | `tests/`: 13 Dateien (~570 LOC).
*   **Test-zu-Code-Ratio:** ca. 1:1.
*   **Abhängigkeiten:** `php: >=8.3`, `amphp/socket: ^2.3`. Transitive Abhängigkeiten deuten primär auf Amphp-Kernkomponenten (Fiber-basiert) hin.
*   **CI/CD:** GitHub Actions Matrix-Test für PHP 8.3 vorhanden (`.github/workflows/ci.yml`).
*   **API-Oberfläche:** `IcapClient`, `SynchronousIcapClient`, `Config`, `ScanResult`, `IcapRequest`, `IcapResponse`, `PreviewDecision`.

---

## 3. Findings nach Dimension

### 3.1 Sprachmoderne & Typsystem
*   **Positiv:** Konsequente Nutzung von `readonly class` für DTOs (`src/DTO/IcapRequest.php:9`) und Enums (`src/PreviewDecision.php:9`). Type-Hints sind durchgehend gesetzt.
*   **Negativ:** Es fehlen PHPStan Generics-Annotationen (z. B. `@template` für Futures), die `mixed` Body-Property (`src/DTO/IcapRequest.php:20`) schwächt die statische Analyse.

### 3.2 Design, Pattern & SOLID
*   **Positiv:** Die Architektur ist sauber separiert. `SynchronousIcapClient` dekoriert `IcapClient` sauber und wartet auf Fibers (`src/SynchronousIcapClient.php:40`). Das Strategy-Pattern für Previews (`src/PreviewStrategyInterface.php`) ist vorbildlich.
*   **Negativ:** Das "Immutable With"-Pattern in `IcapRequest` ist nutzlos, wenn der Body eine mutable PHP-Resource (Stream) ist (`src/DTO/IcapRequest.php:20`). Sobald der Stream ausgelesen wird, ist der Zustand modifiziert.

### 3.3 PSR-Compliance & Framework-Fit
*   **Kritisch:** Null PSR-Kompatibilität abseits von PSR-4. Es fehlt zwingend ein PSR-3 `LoggerInterface`, um ICAP-Transaktionen (besonders Fehler und Timeouts) audit-sicher zu protokollieren.
*   ICAP-Messages lehnen sich lose an PSR-7 an, implementieren aber nicht das `Psr\Http\Message\StreamInterface`.

### 3.4 Ressourcen-Management & Memory Footprint (Kritisch!)
*   **Kritisch:** In `docs/agent.md` wird "Efficient streaming" beworben. Der `RequestFormatter` (`src/RequestFormatter.php:40-52`) liest jedoch den Stream via `fread` blockweise ein und konkateniert **alles** in einen gigantischen String (`$body .= $len . "\r\n" . $chunk . "\r\n";`). Das führt bei einer 1GB-Videodatei sofort zum Out-Of-Memory-Crash (OOM). Ein echter Stream-Transport müsste den Socket stückweise beschreiben, statt den String im RAM zu bauen.
*   Es gibt **kein Connection-Pooling** (Keep-Alive). Für jeden Scan wird via `Amp\Socket\connect` eine neue TCP-Verbindung initiiert (`src/Transport/AsyncAmpTransport.php:27`), was bei hoher Portal-Last in Latenzproblemen und Socket-Exhaustion mündet.

### 3.5 Async-Implementierung
*   **Positiv:** Der Einsatz von Amphp v3 (Fibers) ist modern. Die Cancellation-Tokens via `TimeoutCancellation` (`src/Transport/AsyncAmpTransport.php:25`) sind sauber implementiert.

### 3.6 Öffentliche Verwaltung, Security & Compliance
*   **Lizenz:** EUPL-1.2 ist für den öffentlichen Sektor hervorragend gewählt (OpenCoDE-kompatibel).
*   **Security:** `composer audit` schlägt in den Dev-Abhängigkeiten auf (`phpunit` CVE-2026-24765, `symfony/process` CVE-2026-24739).
*   **Logging:** Da Header und Payloads nicht sicher maskiert geloggt werden können (mangels PSR-3), ist das BSI-konforme Protokollieren von Virenfunden schwer möglich.

---

## 4. ICAP RFC 3507 Compliance-Checkliste

Das Protokoll-Implementierungs-Defizit ist die größte Schwachstelle der Bibliothek.

| Feature / Detail | Status | Begründung & Referenz |
| :--- | :---: | :--- |
| **OPTIONS Methode** | 🟡 | Generiert Request (`src/IcapClient.php:84`), aber wertet die Server-Capabilities (`Options-TTL`, `Max-Connections`) nicht aus und cacht sie nicht. |
| **REQMOD Methode** | ❌ | Komplett fehlend. Nur `RESPMOD` ist in `IcapClient::scanFile` hartkodiert (`src/IcapClient.php:101`). |
| **HTTP-in-ICAP Nesting** | ❌ | **Kritisch:** ICAP modifiziert HTTP. Ein ICAP-Body *muss* einen HTTP-Request oder -Response enthalten (RFC 3507, 4.3). ICapFlow sendet nur reine Dateidaten ohne gekapselte HTTP-Header. |
| **`Encapsulated` Header**| ❌ | **Kritisch:** Der `RequestFormatter` setzt pauschal `null-body=0` (`src/RequestFormatter.php:26`), auch wenn ein File-Body angehängt wird. Ein Virenscanner wie c-icap oder Squid wird dies mit 400 Bad Request oder Protokollfehlern quittieren. Korrekt wäre z.B. `req-hdr=0, res-body=120`. |
| **Preview Modus** | 🟡 | Wird unterstützt (`scanFileWithPreview`), sendet aber den `Allow: 204` Header nicht mit, womit Server die 204-Optimierung gar nicht erst anwenden dürfen (RFC 3507, 4.6). |
| **Chunked Encoding** | ❌ | Das Chunking der Payload in `RequestFormatter.php:48` sendet keine Chunk-Extensions und missachtet `ieof` (ICAP End-of-File) nach Preview-Chunks. |
| **Statuscodes** | 🟡 | Nur 100, 200, 204 werden behandelt. Andere Codes werfen pauschal eine `IcapResponseException` ohne den Original-Body, was Diagnose unmöglich macht (`src/IcapClient.php:155`). |

---

## 5. Wettbewerbsvergleich

| Sprache / Library | TRL | REQMOD / RESPMOD | Encapsulation / RFC | Streaming | Fazit |
| :--- | :---: | :---: | :---: | :---: | :--- |
| **PHP:** `ndrstmr/icap-flow` | 4 | Nur RESPMOD | Falsch (`null-body=0`) | In-Memory (OOM Gefahr) | Modernste Async-Basis, aber fachlich inkorrekt. |
| **PHP:** `nathan242/php-icap-client` | 7 | Beide | Teilweise korrekt | Blockierend | Beliebteste PHP-Lib, jedoch veraltet und synchron. |
| **Go:** `egirna/icap-client` | 9 | Beide | RFC konform | Echtes Streaming | De-facto Referenz-Client in Go. |
| **Java:** `toolarium-icap-client` | 8 | Beide | RFC konform | Stream-basiert | Solide Enterprise-Library. |

---

## 6. Bewertungsmatrix

| Dimension | Score (0-10) | Begründung |
| :--- | :---: | :--- |
| Sprachmoderne (PHP 8.3+) | 9/10 | Sehr gute Nutzung moderner Features (Fibers, Readonly, Enums). |
| Typsystem / PHPStan | 8/10 | PHPStan Level 9 wird erreicht, aber fehlende Generics-Annotationen. |
| SOLID / Architektur | 7/10 | Saubere Interfaces, aber DTO-Immutability bricht bei Streams. |
| Exception-Design | 4/10 | `RuntimeException` im Formatter, unzureichender Kontext in Exceptions. |
| PSR-Konformität | 2/10 | Kein PSR-3, kein PSR-7, kein PSR-17. Komplett proprietär. |
| Ressourcen-Management | 2/10 | OOM-Gefahr durch String-Verkettung ganzer Dateien. Kein Keep-Alive. |
| Async (Revolt/Amphp) | 8/10 | Sehr sauber integriert, blockiert Event-Loop nicht (außer bei Dateizugriff). |
| ICAP Methoden (RFC 3507) | 3/10 | REQMOD fehlt, OPTIONS ignoriert Ergebnisse. |
| ICAP Preview / 204 | 5/10 | Logik vorhanden, aber `Allow: 204` Header fehlt im Request. |
| ICAP Robustheit (Parser) | 4/10 | Bricht bei jeglichen Vendor-spezifischen Abweichungen zusammen. |
| Test-Coverage | 6/10 | Unittests gut (~100%), aber **keine** Integrationstests gegen echte ICAP-Server! |
| CI-Pipeline-Qualität | 7/10 | GitHub Actions vorhanden, aber lokaler Coverage-Lauf defekt. |
| Dokumentation | 8/10 | README und Agent-Mission gut strukturiert. |
| Symfony-Bundle-Integration | 0/10 | Nicht existent. Keine Tags, kein DI, kein Profiler. |
| Public-Sector-Fit | 6/10 | EUPL-1.2 top, aber fehlendes Logging blockiert Auditierbarkeit (BSI Grundschutz). |
| **Gesamt-Readiness-Score** | **79/150** | *(Berechnet ohne nicht-zutreffende Kategorien)* |

---

## 7. Produktionsreife-Gate-Entscheidung

1.  **Für interne Tools / Prototypen:** **Mit Einschränkungen.** Verwendbar für kleine Dateien (< 10 MB) und simple RESPMOD-Virenscans, sofern der eingesetzte ICAP-Server fehlerhafte `Encapsulated`-Header toleriert.
2.  **Für Symfony-Applikationen in Dataport-Projekten:** **Nein.** Ohne Bundle, ohne PSR-3 Logger und ohne echte HTTP-Header-Einkapselung ist eine Integration in Enterprise-Systeme nicht tragbar. Löst bei großen Uploads im Bürgerportal unweigerlich OOM-Kills aus.
3.  **Für kritische Security-Komponenten:** **Nein.** Die fehlende Validierung von Chunk-Extensions und das Fehlen von Connection-Pooling macht das System anfällig für DoS und Latenzprobleme unter Last.

---

## 8. Priorisierte Gap-Liste

*   **P0 (Blocker für Produktion):**
    1.  `RequestFormatter` umschreiben: Daten in den Socket streamen statt in eine RAM-Variable zu laden.
    2.  Korrektes Berechnen des `Encapsulated`-Headers anhand von HTTP-Headern und Body-Offsets.
    3.  Integration echter HTTP-Request/Response-Header, die vor dem Payload an den ICAP-Server gesendet werden (RFC 3507, Section 4.3.2).
*   **P1 (Kritisch für Ökosystem-Fit):**
    1.  Schaffung eines `icap-flow-bundle` für Symfony (DI-Konfiguration, DataCollector für den Profiler).
    2.  Einbinden von `psr/log` im Transport-Layer.
    3.  Implementierung von `REQMOD`.
*   **P2 (Nice-to-have):**
    1.  Connection Pooling für `AsyncAmpTransport` (Keep-Alive Unterstützung).
    2.  Integration-Tests gegen `c-icap` via Docker in GitHub Actions.
*   **P3 (Vision):**
    1.  Symfony Messenger Integration (Asynchroner Scan im Hintergrund).
    2.  VichUploaderBundle-Validatoren.

---

## 9. Konkrete Roadmap

**v1.1.x (Sofort - Bugfixes & RFC Compliance)**
*   *PR 1:* Fix `RequestFormatter.php` Zeile 26 (`null-body=0`). Dynamische Berechnung der Offsets.
*   *PR 2:* Refactoring `RequestFormatter` & `TransportInterface`, sodass Streams direkt an `Socket\write()` gepiped werden (verhindert OOM).
*   *PR 3:* Einfügen von `Allow: 204` in `IcapClient::scanFileWithPreview`.

**v1.2.0 (Minor - Features)**
*   Implementierung von `REQMOD` (neue Methode `scanRequest` im `IcapClient`).
*   Hinzufügen von PSR-3 Logging (`setLogger`).
*   Docker Compose Setup für echte Integrationstests in `tests/Integration`.

**v2.0.0 (Major)**
*   Umstieg von proprietären `IcapRequest`/`IcapResponse` auf PSR-7 kommutative Interfaces.
*   Connection-Pooling native Unterstützung.

**Separates Repository (Kurzfristig anlegen)**
*   `ndrstmr/icap-flow-bundle`: Beinhaltet die `Configuration.php`, Compiler-Pässe und Autowiring für Symfony-Projekte, sowie Monolog-Channels.

---

## 10. Quellenverzeichnis

1.  **RFC 3507** (Internet Content Adaptation Protocol): https://datatracker.ietf.org/doc/html/rfc3507
2.  **Amphp / Socket Documentation**: https://amphp.org/socket
3.  **Symfony HttpClient Component** (als Architekturvorbild für Streams): https://symfony.com/doc/current/http_client.html
4.  **BSI IT-Grundschutz (APP.4 Webanwendungen)**: Spezifiziert Malware-Prüfung bei Uploads.
5.  **EUPL-1.2**: https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
6.  **Referenz-Client (Go)**: https://github.com/egirna/icap-client (Zeigt das korrekte Encapsulated-Offset-Handling).
