# Due-Diligence Analyse `ndrstmr/icap-flow` — Phase 2 (Code- & Architektur-Analyse)

Stand: 2026-04-24

> **Methodik**: Diese Phase trennt strikt zwischen **Fakten aus dem Code** und **Bewertung/Empfehlung**.

---

## 2.1 Sprachmoderne & Typsystem

### Fakten (Codebelege)

1. `declare(strict_types=1)` ist konsistent in allen `src/`-Dateien vorhanden.
   - Beispiele: `src/IcapClient.php:3`, `src/ResponseParser.php:3`, `src/Transport/AsyncAmpTransport.php:3`.
2. `readonly` wird teilweise eingesetzt:
   - `final readonly class Config` (`src/Config.php:10`)
   - `final readonly class IcapRequest` (`src/DTO/IcapRequest.php:10`)
   - `final readonly class IcapResponse` (`src/DTO/IcapResponse.php:10`)
   - `final readonly class ScanResult` (`src/DTO/ScanResult.php:10`)
3. `enum` wird genutzt: `PreviewDecision` als backed enum (`string`) in `src/PreviewDecision.php:10-15`.
4. Asynchroner Rückgabetyp ist explizit als `Amp\Future` typisiert (`IcapClient::request`, `TransportInterface::request`).
5. Gleichzeitig existiert weiterhin `mixed` bei `IcapRequest::$body` (`src/DTO/IcapRequest.php:25`), obwohl der Docblock `resource|string` intendiert.
6. `#[\Override]`-Attribute werden nicht genutzt (weder in Implementierungen von Interfaces noch bei Vererbungen).
7. Keine `never`-Returntypen, keine Attribute-basierte API, keine `@template`-Generics in den eigenen Interfaces/DTOs.
8. `phpstan.neon` setzt Level 9 ohne Baseline/ignoreErrors (`phpstan.neon:1-8`).

### Bewertung

- **Positiv**: modernes Fundament (strict types, readonly DTOs, enum, Future-Typisierung).
- **Schwachpunkt**: Der wichtigste Payload-Typ (`IcapRequest::$body`) bleibt technisch unpräzise (`mixed`) und entwertet einen Teil der statischen Garantien.
- **Schwachpunkt**: Kein `#[\Override]` erhöht das Risiko stiller Signaturdrifts bei Refactorings.

### Konkrete Empfehlungen

- `IcapRequest::$body` auf präzises Union-Typing umstellen, z. B. `string|resource` via Runtime-Guard + PHPStan-Annotationen.
- `#[\Override]` auf alle Interface-Implementierungen setzen (Formatter, Parser, Transport, PreviewStrategy).
- Optional: eigene Typalias-Doku für Header-Strukturen (`array<string, list<string>>`) konsequent vereinheitlichen.

---

## 2.2 Design, Pattern & SOLID

### Fakten (Codebelege)

1. `IcapClient` kapselt mehrere Verantwortungen:
   - Request-Orchestrierung (`request`),
   - Convenience-Methoden (`options`, `scanFile`),
   - Preview-Workflow (`scanFileWithPreview`),
   - Status-Interpretation (`interpretResponse`).
   → `src/IcapClient.php:74-179`.
2. Strategy-Pattern ist vorhanden (`PreviewStrategyInterface`, `DefaultPreviewStrategy`).
3. Adapter/Ports-Ansatz ist teilweise vorhanden über Interfaces:
   - `TransportInterface`, `RequestFormatterInterface`, `ResponseParserInterface`.
4. Factory-Methoden mit Defaults existieren:
   - `IcapClient::forServer`, `IcapClient::create`, `SynchronousIcapClient::create`.
5. Sync-Client ist Wrapper über Async-Client (`SynchronousIcapClient::$asyncClient`), keine harte Codeduplizierung der Kernlogik (`src/SynchronousIcapClient.php:21-77`).
6. `IcapClient::scanFileWithPreview` liest die komplette Datei in den Speicher (`file_get_contents`) statt Stream-Pipeline (`src/IcapClient.php:134`).

### Bewertung

- **Positiv**: Solide Trennung in Parser/Formatter/Transport als austauschbare Bausteine.
- **Kritisch**: `IcapClient` verletzt SRP teilweise (Transport-Orchestrierung + Protokoll-Flows + Dateizugriff + Semantikinterpretation).
- **Kritisch**: Preview-Implementierung ist architektonisch nicht „stream-first“ und gefährdet Memory-Verhalten bei großen Dateien.

### Konkrete Empfehlungen

- `IcapClient` in Services schneiden:
  - `IcapRequestExecutor`,
  - `PreviewScanWorkflow`,
  - `IcapResponseInterpreter`.
- Preview vollständig stream-basiert umsetzen (kein Full-Buffer-Read).
- File-I/O optional nach außen verlagern (`scanStream`) und `scanFile` als Convenience belassen.

---

## 2.3 PSR-Compliance

### Fakten (Codebelege)

1. PSR-4 ist korrekt definiert (`composer.json` → `Ndrstmr\\Icap\\` auf `src/`).
2. PSR-12-Style wird via php-cs-fixer geregelt (`.php-cs-fixer.dist.php`).
3. Kein PSR-3 `LoggerInterface` in Konstruktoren/APIs → keine standardisierte Logging-Einspeisung.
4. Keine PSR-7/17-Integration; intern proprietäre DTOs (`IcapRequest`, `IcapResponse`).
5. Keine PSR-20 Clock-Abstraktion für timeout/retry deterministische Zeitlogik.
6. Keine PSR-11 Container-Annahme im Core (für Library neutral grundsätzlich okay).

### Bewertung

- **Positiv**: PSR-4/12-Basis sauber.
- **Neutral bis kritisch**: Für Enterprise/Symfony-Betrieb fehlt PSR-3-Hook; erschwert Observability und Incident-Triage.
- **Neutral**: PSR-7-Verzicht ist bei ICAP vertretbar, muss aber bewusst dokumentiert werden (Abgrenzung HTTP-vs-ICAP).

### Konkrete Empfehlungen

- Optionalen `Psr\Log\LoggerInterface` im Client-Stack einführen (NullLogger als Default).
- Entscheidung „kein PSR-7/17“ explizit im Architekturkapitel dokumentieren.
- Retry-/Backoff-Komponenten an Clock abstrahieren (PSR-20-kompatibel).

---

## 2.4 Fehlerbehandlung & Exception-Design

### Fakten (Codebelege)

1. Es existieren nur zwei eigene Exceptions:
   - `IcapConnectionException`
   - `IcapResponseException`
2. `IcapClient::interpretResponse` wirft bei unbekanntem Status pauschal `IcapResponseException` (`src/IcapClient.php:179`).
3. Parser kann bei Header-Parsing auf `explode(':', $line, 2)` implizit fehlschlagen, falls Header-Zeile kein `:` enthält (`src/ResponseParser.php:45`).
4. In `scanFile` / `scanFileWithPreview` werden `\RuntimeException` mit generischer Meldung geworfen („Unable to open/read file“), ohne Pfad-Kontext (`src/IcapClient.php:114,136`).
5. Async-Transport chaint Connect-Exceptions korrekt als `previous` (`src/Transport/AsyncAmpTransport.php:43-48`).

### Bewertung

- **Positiv**: Grundlegendes Exception-Chaining ist vorhanden.
- **Kritisch**: Exception-Taxonomie ist zu flach für Produktionsbetrieb (Timeout vs Protocol vs InvalidHeader vs UnsupportedStatus etc. nicht sauber trennbar).
- **Kritisch**: Parser ist bei malformed Headern potenziell „notice-driven“ statt kontrolliertem Domain-Error.

### Konkrete Empfehlungen

- Exception-Hierarchie ausbauen:
  - `IcapProtocolException`
  - `IcapTimeoutException`
  - `IcapMalformedResponseException`
  - `IcapUnsupportedStatusException`
- Parser robust machen: Vor `explode` auf `str_contains($line, ':')` prüfen und klaren Parse-Fehler werfen.
- Dateibezogene Runtime-Fehler mit sicherem Kontext anreichern (z. B. basename, nicht vollständiger Pfad).

---

## 2.5 Ressourcen-Management & Connection-Handling

### Fakten (Codebelege)

1. Async-Transport schließt Socket in `finally` (`src/Transport/AsyncAmpTransport.php:49-53`).
2. Sync-Transport schließt Stream via `fclose` (`src/Transport/SynchronousStreamTransport.php:30`).
3. `SynchronousStreamTransport` ignoriert konfigurierbare Timeouts:
   - Harte `5` in `stream_socket_client(..., 5)` statt `Config::getSocketTimeout()` (`src/Transport/SynchronousStreamTransport.php:23`).
   - Kein `stream_set_timeout` für Read/Write (`streamTimeout` ungenutzt).
4. Kein Connection-Pooling/Keep-Alive; pro Request neue TCP-Verbindung in beiden Transporten.
5. `scanFile` öffnet Datei als Stream (`fopen`) und übergibt an Formatter, aber schließt den File-Handle nicht selbst (`src/IcapClient.php:112-118`); implizites Schließen bleibt dem GC überlassen.
6. RequestFormatter chunked-streamt nur bei Resource-Body, aber nicht bei String-Body (`src/RequestFormatter.php:40-53`).

### Bewertung

- **Positiv**: Basis-Ressourcenhygiene auf Socket-Ebene ist in beiden Transporten vorhanden.
- **Kritisch**: Timeout-Konfiguration ist inkonsistent umgesetzt (insb. synchroner Pfad).
- **Kritisch**: Kein Connection-Reuse und kein kontrolliertes Stream-Lifecycle-Management für Datei-Handles.

### Konkrete Empfehlungen

- `SynchronousStreamTransport` auf Config-Timeouts umstellen (`connect` + `read/write`).
- In `scanFile` Handle-Lifecycle eindeutig regeln (z. B. formatter/transport übernimmt + dokumentiert, oder `try/finally` im Client).
- Optional Pooling-Schicht für persistente ICAP-Verbindungen (Keep-Alive).

---

## 2.6 Async-Implementierung (Revolt/Amphp)

### Fakten (Codebelege)

1. Async-Pfade laufen über `Amp\async` und `Future::await` (`IcapClient`, `AsyncAmpTransport`).
2. `TimeoutCancellation` wird im Async-Transport genutzt (`src/Transport/AsyncAmpTransport.php:31`).
3. Externe Cancellation-Tokens werden vom API-Call nicht entgegengenommen/weitergereicht.
4. `SynchronousIcapClient` blockt per `await()` auf Futures (`src/SynchronousIcapClient.php:50-77`), wodurch identische Kernlogik genutzt wird.
5. `IcapClient::request` kann mit Sync-Transport verwendet werden (`forServer` nutzt `SynchronousStreamTransport`), d. h. „async core“ enthält faktisch auch blocking Transportmöglichkeiten.

### Bewertung

- **Positiv**: Ein konsistentes Future-basiertes API für async/sync-Nutzung ist vorhanden.
- **Kritisch**: Cancellation ist nicht first-class; für robuste Produktions-Workloads fehlt kooperative Abbruchsteuerung.
- **Kritisch**: Architektur kommuniziert „async core“, erlaubt aber stillschweigend blocking Transporte im gleichen Typ — potenziell irreführend.

### Konkrete Empfehlungen

- API um optionale Cancellation erweitern (`request(IcapRequest, ?Cancellation $cancellation = null)`).
- Async- und Sync-Factory-Semantik klarer trennen (`AsyncIcapClient` vs `BlockingIcapClient` oder klar dokumentierte Transport-Policy).
- Last-/Concurrency-Tests ergänzen (gleichzeitige 100+ Futures, Timeout/Cancel-Verhalten).

---

## Zwischenfazit Phase 2

**Kurzurteil (Phase 2 only):**
- Die Architektur ist für v1.0.0 **solide angelegt**, aber noch **nicht auf „Enterprise-grade ICAP-Kernkomponente“ gehärtet**.
- Größte technischen Defizite in dieser Phase: 
  1) präzise Typisierung des Bodys,
  2) robuste Parser-/Exception-Taxonomie,
  3) konsequente Timeout-/Ressourcenpolitik,
  4) stream-first Preview-Workflow.

**Priorität aus Phase 2:**
- **P0:** Timeout-/Ressourcenkonsistenz, Parser-Härtung.
- **P1:** Exception-Hierarchie, Cancellation-Support, SRP-Schnitt.
- **P2:** zusätzliche Sprachmoderne (`#[\Override]`) und API-Feinschliff.
