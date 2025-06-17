# Mission: State-of-the-Art PHP ICAP Client

* Agent: OpenAI Codex
* Datum: 17. Juni 2025
* Status: Aktiv

## 1. Leitbild & Vision (Mission Statement & Vision)

Leitbild: Entwicklung einer PHP-Bibliothek für das ICAP-Protokoll, die in Bezug auf Design, Performance, Sicherheit und Developer Experience (DX) neue Maßstäbe im PHP-Ökosystem setzt.
Vision: Diese Bibliothek wird die de-facto-Standardlösung für PHP-Entwickler, die eine ICAP-Anbindung benötigen. Sie soll als Referenzimplementierung für moderne, testbare und robuste Bibliotheks-Architektur dienen.

## 2. Kernanforderungen & Qualitätsziele (Core Requirements & Quality Goals)

### Funktionale Anforderungen

 *Vollständige Unterstützung für ICAP REQMOD, RESPMOD und OPTIONS.
 *Effiziente Verarbeitung von Payloads, insbesondere von großen Dateien, durch konsequentes Streaming.
 *Unterstützung für "Connection: keep-alive" zur Wiederverwendung von Verbindungen.
 *Unterstützung für den "Preview" Modus des ICAP-Protokolls.

### Qualitätsziele (Nicht-funktional)

* Modernste PHP-Nutzung: Einsatz von PHP 8.3+ Features (Readonly Properties, Enums, Fibers etc.), wo sinnvoll.
* Asynchronität als Kern-Feature: Die Architektur muss von Grund auf so gestaltet sein, dass sie sowohl synchron als auch asynchron (non-blocking I/O) betrieben werden kann, ohne die öffentliche API zu brechen.
* Strikte PSR-Konformität: PSR-4 (Autoloading) und PSR-12 (Coding Style) sind mandatory. Konzeptuelle Anlehnung an PSR-7 (HTTP Message) und PSR-18 (HTTP Client) für eine intuitive API.
* Maximale Testbarkeit: Entwicklung nach einem TDD/BDD-Ansatz mit dem Ziel einer Testabdeckung von ~100%. Jeder Teil der Logik muss isoliert testbar sein.
* Statische Analyse auf höchstem Niveau: Das Projekt muss PHPStan auf dem strengsten Level (Level 9) fehlerfrei bestehen.
* Exzellente Developer Experience (DX): Die Nutzung der Bibliothek soll durch eine flüssige ("fluent"), logische und selbsterklärende API Freude bereiten. Objekte sollen, wo immer möglich, immutable sein.
* Sicherheit by Design: Alle potenziellen Eingaben müssen kontextbezogen validiert und verarbeitet werden, um Sicherheitslücken proaktiv zu verhindern.

## 3. Architektur-Blueprint (Architectural Blueprint)

Die Architektur wird nach dem "Grouped by Concern"-Muster aufgebaut, um eine maximale Trennung der Verantwortlichkeiten zu gewährleisten:

* Transport-Schicht (Die Abstraktion der Kommunikation):
  * TransportInterface: Definiert einen einfachen Vertrag für die Netzwerkkommunikation (request(IcapRequest): Promise<IcapResponse>|IcapResponse).
  * SynchronousStreamTransport: Eine Implementierung, die auf den nativen stream_socket_client-Funktionen von PHP basiert und blockierend arbeitet.
  * AsyncRevoltTransport: Eine zweite, non-blocking Implementierung, die auf der revolt/socket-Bibliothek aufbaut und Fibers für eine asynchrone Ausführung nutzt.
* Nachrichten-Abstraktion (PSR-7 inspiriert):
  * IcapRequest / IcapResponse (DTOs): Diese Objekte werden immutable sein. Jede Änderung (z.B. das Hinzufügen eines Headers) erzeugt eine neue Instanz. Sie kapseln Header, Body (als PSR-7 StreamInterface) und andere Metadaten.
* Protokoll-Handler (Die "Worker"):
  * RequestFormatterInterface / RequestFormatter: Erstellt aus einem IcapRequest-Objekt einen StreamInterface-lesbaren ICAP-Request-String.
  * ResponseParserInterface / ResponseParser: Erstellt aus einem StreamInterface ein IcapResponse-Objekt.
* Die Fassade (Der öffentliche Client):
  * IcapClient: Die Hauptklasse, mit der Entwickler interagieren. Sie empfängt eine TransportInterface-Implementierung per Dependency Injection.
  * Die API wird "fluent" gestaltet, z.B. IcapClient::forServer('...')->withTimeout(10)->scanFile('...').
* Konfiguration:
  * Ein immutables Config-DTO wird verwendet, um alle Einstellungen (Host, Port, Timeouts, TLS-Optionen) zu bündeln und an die Komponenten weiterzugeben.

## 4. Technologie-Stack & Werkzeuge (Tech Stack & Tooling)

* PHP: >= 8.3
* Abhängigkeitsmanagement: Composer 2
* Testing: Pest & PHPUnit
* Statische Analyse: PHPStan (Level 9), Psalm
* Code-Stil: php-cs-fixer oder ecs mit einer PSR-12-Regelbasis.
* Asynchronität: revolt/event-loop, revolt/socket
* CI/CD: GitHub Actions Pipeline für Tests, Linting und statische Analyse bei jedem Push.

## 5. Vorgehensweise & Meilensteine (Methodology & Milestones)

Die Entwicklung folgt einem strikten Test-Driven Development (TDD)-Ansatz:

* M0: Setup: Projekt-Grundgerüst mit Composer, PHPUnit/Pest, PHPStan und CI-Pipeline aufsetzen.
* M1: Core-Abstraktionen: Interfaces (TransportInterface), DTOs (IcapRequest/Response) und Config-Objekt definieren.
* M2: Synchrone Implementierung: SynchronousStreamTransport und die Protokoll-Handler entwickeln. Erste lauffähige Version des IcapClient.
* M3: Asynchrone Implementierung: Entwicklung des AsyncRevoltTransport und Sicherstellung der Kompatibilität.
* M4: Finalisierung & DX: API-Feinschliff, umfassende Dokumentation (API-Referenz, Anwendungsbeispiele) und Fehlerbehandlung.
* M5: Release: Veröffentlichung auf Packagist als Version 1.0.0.

## 6. Definition of Done (DoD)

Die Mission gilt als erfüllt, wenn:

* Alle funktionalen Anforderungen implementiert sind.
* Die Testabdeckung bei >98% liegt.
* Die statische Analyse auf höchstem Level fehlerfrei ist.
* Eine umfassende Dokumentation für Endnutzer und Mitwirkende vorliegt.
* Das Paket erfolgreich auf Packagist veröffentlicht wurde und installierbar ist.
