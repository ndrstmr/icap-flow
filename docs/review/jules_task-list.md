# Taskliste: IcapFlow zum PHP-Referenz-Client machen

Diese Taskliste priorisiert die notwendigen Schritte, um die in der Due-Diligence-Analyse identifizierten Schwachstellen zu beheben. Ziel ist es, `icap-flow` zu einem echten Referenz-ICAP-Client für das PHP- und Symfony-Ökosystem zu machen.

## Phase 1: Kritische Architektur- und RFC-Fixes (OOM & Protokoll-Fehler)
**Priorität:** P0 (Blocker) - *Müssen sofort in `v1.1.0` behoben werden.*

- [ ] **T1.1: Streaming überarbeiten (OOM-Fix)**
  - *Problem:* `RequestFormatter` lädt komplette Dateien via `fread` in den RAM.
  - *Lösung:* Refactoring des `TransportInterface` (`AsyncAmpTransport` und `SynchronousStreamTransport`), sodass Streams (Ressourcen) blockweise via `Socket\write()` bzw. `fwrite()` direkt an den ICAP-Server gesendet werden, ohne sie vorher in einem String zu puffern. Der `RequestFormatter` liefert dann nur noch ein DTO oder ein Generator/Iterable zurück, bestehend aus Headern und dem Body-Stream.
- [ ] **T1.2: `Encapsulated`-Header-Berechnung korrigieren**
  - *Problem:* Pauschal `null-body=0` ist falsch.
  - *Lösung:* Implementierung eines Algorithmus, der dynamisch prüft, ob HTTP-Request-Header, HTTP-Response-Header und/oder ein Body gesendet werden (z.B. `req-hdr=0, res-hdr=45, res-body=120`). Dies erfordert zwingend T1.3.
- [ ] **T1.3: HTTP-in-ICAP Nesting implementieren**
  - *Problem:* ICAP erwartet laut RFC 3507 zwingend, dass die Nutzlast ein gekapselter HTTP-Request oder -Response ist (samt zugehörigen HTTP-Headern wie `Host`, `Content-Length`).
  - *Lösung:* Anpassung der `IcapRequest` DTOs, um echte HTTP-Header (oder PSR-7 Requests/Responses) aufzunehmen, und Einbettung dieser Header durch den `RequestFormatter` vor dem tatsächlichen Datei-Body.
- [ ] **T1.4: `Allow: 204` Header im Preview-Modus ergänzen**
  - *Problem:* Die 204-Optimierung wird blockiert.
  - *Lösung:* In `IcapClient::scanFileWithPreview()` den Header `Allow: 204` zum initialen Preview-Request hinzufügen, damit Server ohne Virus direkt antworten können.

## Phase 2: Feature-Vollständigkeit & Robustheit (ICAP RFC)
**Priorität:** P1 (Kritisch) - *Um das Protokoll komplett abzubilden.*

- [ ] **T2.1: `REQMOD` Methode implementieren**
  - *Problem:* Derzeit ist nur `RESPMOD` als Funktion vorhanden.
  - *Lösung:* Neue Methode `IcapClient::scanRequest(string $service, mixed $requestBody)` hinzufügen, die einen `REQMOD`-Request an den ICAP-Server sendet.
- [ ] **T2.2: `OPTIONS` Capabilities caching und auswerten**
  - *Problem:* Server-Funktionen (`Max-Connections`, `Options-TTL`) werden ignoriert.
  - *Lösung:* Eine `Capabilities`-Klasse erstellen, die das Ergebnis von `OPTIONS` parst, speichert (ggf. PSR-16 Cache) und in zukünftigen Requests (z. B. für die Preview-Size-Aushandlung) berücksichtigt.
- [ ] **T2.3: Parser robuster machen & Exceptions verbessern**
  - *Problem:* Unbekannte Statuscodes werfen eine Exception ohne Body.
  - *Lösung:* Erstellung spezialisierter Exceptions (z.B. `IcapProtocolException`) und immer den Original-Body/Status in der Exception speichern, um besseres Debugging zu ermöglichen.
- [ ] **T2.4: Chunking-Logik validieren**
  - *Problem:* Chunk-Extensions und `ieof` werden missachtet.
  - *Lösung:* Sicherstellen, dass das letzte Preview-Chunk mit einem korrekten End-of-File-Marker (`0\r\n\r\n` bzw. `0; ieof\r\n\r\n`) abgeschlossen wird, je nach Aushandlung.

## Phase 3: Ökosystem-Fit & Symfony Integration
**Priorität:** P1 (Kritisch) - *Für Enterprise-Bereitschaft im Dataport/CCW-Umfeld.*

- [ ] **T3.1: PSR-3 Logger-Integration in die Core-Library**
  - *Problem:* Keine Auditierbarkeit von Virus-Funden.
  - *Lösung:* Dependency Injection für `Psr\Log\LoggerInterface` im `IcapClient` und/oder den Transporten ergänzen. Wichtige Events (Verbindungsaufbau, Scan-Start, Scan-Ergebnis) protokollieren.
- [ ] **T3.2: Neues Repository: `ndrstmr/icap-flow-bundle`**
  - *Problem:* Keine Symfony-Out-of-the-Box Experience.
  - *Lösung:* Erstellung eines separaten Repositories.
    - **T3.2.1:** Implementierung einer `Configuration.php` (`icap_flow.servers.default...`).
    - **T3.2.2:** Service-Autowiring für `IcapClient` und `SynchronousIcapClient`.
    - **T3.2.3:** Integration des `LoggerInterface` über einen dedizierten Monolog-Channel (`icap`).
- [ ] **T3.3: Symfony Profiler DataCollector**
  - *Problem:* Mangelnde Observability im Dev-Modus.
  - *Lösung:* Erstellung eines `IcapDataCollector` im Bundle, der Requests, Responses, Status-Codes und Dauer (Time) aufzeichnet und im Symfony Web Profiler visuell darstellt.
- [ ] **T3.4: (Optional) PSR-7 Kompatibilität prüfen**
  - *Problem:* Eigene DTOs.
  - *Lösung:* Evaluieren, ob `IcapRequest` intern PSR-7 Interfaces (z. B. für den gekapselten Payload) nutzen sollte.

## Phase 4: Qualitätssicherung & Tests
**Priorität:** P2 - *Zur langfristigen Stabilisierung.*

- [ ] **T4.1: Integrationstests gegen echte ICAP Server**
  - *Problem:* Mocks decken Protokollfehler nicht auf.
  - *Lösung:* `docker-compose.yml` hinzufügen, die `c-icap` und/oder `squid` mit ClamAV startet. Integrationstests schreiben (`tests/Integration`), die gegen diese Server echte Viren-Dateien (EICAR) und saubere Dateien scannen. Diese Tests im GitHub Actions Workflow ausführen.
- [ ] **T4.2: Connection-Pooling (Keep-Alive)**
  - *Problem:* Für jeden Call wird ein neuer Socket geöffnet.
  - *Lösung:* Im `AsyncAmpTransport` einen Connection-Pool (z.B. basierend auf `amphp/socket` Pools) implementieren, um TCP-Handshakes wiederzuverwenden.
- [ ] **T4.3: Mutation Testing einrichten**
  - *Problem:* Coverage sagt nicht alles aus.
  - *Lösung:* `infection/infection` via Composer installieren und in CI/CD pipeline aufnehmen.
