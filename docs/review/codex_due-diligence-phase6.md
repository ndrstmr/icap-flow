# Due-Diligence Analyse `ndrstmr/icap-flow` — Phase 6 (Symfony-Integration & Ökosystem-Fit)

Stand: 2026-04-24

## Externe Referenzen (für Empfehlungen)

- Symfony Bundle Konfiguration: https://symfony.com/doc/current/bundles/configuration.html
- Symfony Bundle Best Practices: https://symfony.com/doc/7.4/bundles/best_practices.html
- Symfony Messenger: https://symfony.com/doc/current/messenger/.html
- Symfony Profiler / Data Collector: https://symfony.com/doc/current/profiler.html
- Symfony Logging / Monolog: https://symfony.com/doc/current/logging.html
- Symfony Flex Recipes: https://github.com/symfony/recipes und https://github.com/symfony/recipes-contrib

---

## 6.1 Bundle-Frage: Core-only vs. separates Symfony Bundle

### Fakten

- Das aktuelle Paket ist framework-agnostisch und enthält keine Symfony-Bundle-Struktur (`Bundle`-Klasse, `DependencyInjection/Extension`, `Configuration`-Tree fehlen komplett).
- In `composer.json` gibt es keine Symfony Runtime-Dependencies (nur `amphp/socket` als Runtime) und keine Flex-Recipe-Artefakte.
- Die API ist rein Library-zentriert (`IcapClient`, `SynchronousIcapClient`, Formatter/Parser/Transport) ohne Framework-Hooks.

### Bewertung

- **Positiv**: Core bleibt schlank, portabel und framework-unabhängig.
- **Empfehlung klar**: Für Symfony-Ökosystem-Fit sollte ein **separates** Begleitpaket `icap-flow-bundle` entstehen, statt Symfony-spezifische Abhängigkeiten in den Core zu ziehen.

### Konkrete Zielarchitektur

1. `ndrstmr/icap-flow` bleibt Core-Library.
2. Neues Repo/Paket: `ndrstmr/icap-flow-bundle` mit:
   - `IcapFlowBundle` (Bundle-Klasse)
   - `DependencyInjection/IcapFlowExtension.php`
   - `DependencyInjection/Configuration.php`
   - `Resources/config/services.php` oder `services.yaml`
3. Flex-Recipe in `symfony/recipes-contrib` für schnelle Standardintegration.

---

## 6.2 DI-Integration & Konfigurationsmodell (Symfony)

### Fakten

- Aktuell keine native DI-Integration; Client wird manuell mit Transport/Formatter/Parser konstruiert (auch in `examples/`).
- Es gibt keine typed Bundle-Konfiguration für Host/Port/Timeouts/Preview/Headers.

### Bewertung

- **Kritisch für DX**: In realen Symfony-Projekten (TYPO3/Shopware-nahe Enterprise-Stacks) wird Service-Wiring ohne Bundle unnötig schwer und fehleranfällig.

### Konkrete Empfehlung (Bundle API)

Vorschlag für Konfigurationsbaum:

```yaml
icap_flow:
  default_client: default
  clients:
    default:
      host: '%env(ICAP_HOST)%'
      port: '%env(int:ICAP_PORT)%'
      socket_timeout: '%env(float:ICAP_SOCKET_TIMEOUT)%'
      stream_timeout: '%env(float:ICAP_STREAM_TIMEOUT)%'
      virus_found_header: 'X-Virus-Name'
      transport: 'async' # async|sync
      tls:
        enabled: false
        verify_peer: true
```

Service-Konzept:
- Alias `Ndrstmr\Icap\IcapClientInterface` (neu) -> `icap_flow.client.default`.
- Tagged Services für `PreviewStrategyInterface` (z. B. `icap_flow.preview_strategy`).
- Optional Factory für Multi-Tenant/Mehrmandanten-Setups (`IcapClientRegistry`).

---

## 6.3 Framework-Features (Profiler, Logging, Messenger, Console, Validator)

### Fakten

- Es gibt aktuell keine Integration in Symfony Profiler/DataCollector.
- Kein dedizierter Monolog-Channel (`icap`) und kein PSR-3 Hook im Core.
- Keine Messenger-Message/Handler-Vorlagen.
- Keine Console-Kommandos (`icap:scan`, `icap:options`) vorhanden.
- Keine Symfony-Validator-Constraint für Upload-Scanning.

### Bewertung

- **Großer Ökosystem-Gap**: Für Symfony-Betrieb fehlen die typischen Betriebs- und Debug-Werkzeuge.

### Konkrete Empfehlungen

#### (a) Profiler/DataCollector
- DataCollector für:
  - ICAP URI, Method, Duration, Status, Decision (clean/infected), Fehlerklasse.
- Toolbar-Panel + Profiler-Panel bereitstellen.

#### (b) Monolog Channel
- Standardkanal `icap` mit strukturierter Kontextausgabe (ohne Payload-Leakage).

#### (c) Messenger-Integration
- Message `ScanUploadMessage(fileId, path, correlationId)`.
- Handler `ScanUploadHandler` mit Retry-Strategie (recoverable network errors).

#### (d) Console-Kommandos
- `bin/console icap:options <service>`
- `bin/console icap:scan <service> <path> [--preview]`

#### (e) Validator-Constraint
- `#[IcapClean(service: '/service')]` für Upload-DTOs/Form-Modelle.

---

## 6.4 TYPO3-/Shopware-/Portal-Fit

### Fakten

- Der Core ist unabhängig und kann in beliebigen PHP-Anwendungen genutzt werden.
- Es fehlen aber Standard-Bausteine für einfache Einbettung in Symfony-zentrierte Commerce-/Portal-Plattformen.

### Bewertung

- **Mit Einschränkungen** nutzbar: technisch integrierbar, aber mit hohem Integrationsaufwand pro Projekt.

### Empfehlungen

- Bundle mit klaren Integrationsadaptern/Beispielen:
  - Upload-Lifecycle Hooks,
  - async Scan via Messenger,
  - Fail-Closed/Fail-Open Policy pro Kontext (z. B. Bürgerportal vs. Backoffice).

---

## 6.5 Observability (OpenTelemetry, Prometheus, Health)

### Fakten

- Keine Telemetrie-Hooks/Events im Core.
- Keine Metrik-/Tracing-Schnittstellen.

### Bewertung

- **Kritisch für Betrieb**: Ohne Observability ist Incident-Diagnose (Timeout-Wellen, AV-Server-Ausfälle, Scan-Latenzspitzen) erschwert.

### Konkrete Empfehlungen

1. Event-Hooks im Core (framework-agnostisch):
   - `RequestStarted`, `RequestFinished`, `RequestFailed`.
2. Bundle-seitig Instrumentierung:
   - OpenTelemetry Spans (`icap.request`),
   - Prometheus Counter/Histogram (`icap_requests_total`, `icap_request_duration_seconds`).
3. Health-Indicator:
   - `icap:options` Probe gegen konfigurierten Service.

---

## 6.6 Konkreter Umsetzungsplan für `icap-flow-bundle`

### v0.1.0 (MVP)
- Bundle-Skeleton + DI-Konfiguration + 1 Default-Client.
- `icap:options` und `icap:scan` Console.
- Monolog Channel `icap`.

### v0.2.0
- DataCollector + Toolbar Panel.
- Messenger Message/Handler + Retry-Blueprint.
- Flex-Recipe in recipes-contrib.

### v0.3.0
- Validator Constraint `IcapClean`.
- Multi-Client Registry.
- Health-Check Integration.

### v1.0.0
- Dokumentierte Integrationspfade für Symfony, Shopware, TYPO3-nahe Architekturen.
- Stabilitätszusagen (SemVer) für Bundle-Config + Service-IDs.

---

## Phase-6 Zwischenfazit

Für den Anspruch „de-facto Standard in Symfony-basierten Portal-/Commerce-Systemen“ reicht der aktuelle Core alleine nicht. Die richtige Strategie ist: **Core schlank lassen, aber parallel ein dediziertes Symfony-Bundle liefern**, inklusive DI-Konfiguration, Profiler, Logging, Messenger, Console und Validator-Integration.
