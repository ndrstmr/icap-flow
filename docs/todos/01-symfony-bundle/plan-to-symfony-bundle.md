# Bundle-Strategie

## Repo-Setup

- Neues Repo `ndrstmr/icap-flow-bundle`, eigene Versionierung, Library als Hard-Dependency `"ndrstmr/icap-flow": "^3.0"`.
- Symfony 7.4 LTS als Pflicht (Mai 2026 GA, nächste LTS bis November 2029) — kein 6.4-Backport, keine Multi-Major-Matrix. Eine LTS-Linie ist genug für Public-Sector-Adoption.
- PHP 8.4+ identisch zur Library (kein Mehraufwand für die CI-Matrix).
- Lizenz EUPL-1.2 identisch zur Library.
- Bundle-Skelett: `AbstractBundle` (Symfony 6.1+ Style), nicht der alte Bundle+Extension+Configuration-Dreiklang. Das ist heute der idiomatische Weg und drückt die Konfiguration in eine einzige Klasse.

## Lieferung in drei Stufen, nicht in einem Big-Bang

Ich würde den 8-Item-Scope auf drei Releases verteilen, damit jeder Release einzeln review- und produktionsreif ist.

### v0.1.0 — Minimum Viable Bundle (Ziel: 2-3 Sessions Arbeit)

Das absolute Kern-Pattern, das einem Anwender erlaubt, in `config/packages/icap_flow.yaml` zu konfigurieren und dann `IcapClientInterface` per autowire zu injizieren:

- `IcapFlowBundle` (AbstractBundle-Style) mit Configuration-Tree:
    - `host`, `port`, `socket_timeout`, `stream_timeout`
    - `tls.enabled`, `tls.peer_name`, `tls.ca_file`, `tls.client_cert`, `tls.client_key` (mTLS)
    - `virus_found_headers` (List)
    - `limits.max_response_size`, `limits.max_header_count`, `limits.max_header_line_length`
    - `pool.enabled` (default `true`), `pool.max_connections_per_host`, `pool.max_idle_seconds`
    - `retry.enabled` (default `true`), `retry.max_attempts`, `retry.base_delay_seconds`, `retry.max_delay_seconds`, `retry.backoff_factor`
    - `options_cache.enabled` (default `true`), `options_cache.adapter` (in_memory | service-id für PSR-6/PSR-16)
- Service-Definitionen für `Config`, `AmpConnectionPool` (oder `NullConnectionPool` wenn `pool.enabled=false`), `AsyncAmpTransport`, `RequestFormatter`, `ResponseParser`, `IcapClient`, `RetryingIcapClient` (wenn enabled), `InMemoryOptionsCache`.
- Auto-DI-Aliase: `IcapClientInterface` → `RetryingIcapClient` → inner `IcapClient` (oder `IcapClient` direkt wenn `retry.enabled=false`).
- PSR-3-Logger via `LoggerInterface` autowire (Standard-Symfony).
- Functional Tests mit `KernelTestCase` gegen die Test-Suite des Library-Repos.
- README mit Quickstart + Konfigurations-Referenz.

Bewusst draußen in v0.1: Multi-Client-Support, Profiler-DataCollector, Console-Commands, Validator-Constraint, Vich/Oneup-Adapter, Flex-Recipe.

### v0.2.0 — Symfony-DX

- Symfony Profiler DataCollector für ICAP-Aufrufe (Zeitleiste mit Method/URI/Status/Duration, Header-Inspektion, Cache-Hit-Anzeige).
- Monolog-Channel `icap` vorgemappt.
- Console-Commands: `icap:scan <file> [--service=]`, `icap:options [--service=]`, `icap:health` (OPTIONS-Probe als Liveness-Check).
- Tagged-Services für Multi-Client: mehrere `icap_flow.client.<name>`-Konfigurationen, jede mit eigener Config. Auto-Wiring auf benannte Argumente (`#[Target('avscan')]`).

### v0.3.0 — Application-Hooks

- Validator-Constraint `#[IcapClean]` für File-Upload-Validation. Funktioniert auf `UploadedFile`, `SplFileInfo`, string-Path.
- Adapter für VichUploaderBundle und OneupUploaderBundle als opt-in (per `composer require` + Tagging).
- Messenger-Integration: `ScanFileMessage` + `ScanFileHandler` als Templates für async Scanning.

Nach v0.3.0 ginge es auf v1.0.0 mit eingefrorenem API. Davor sind 0.x-Releases als »experimental contract« deklariert (semver-konform: 0.x darf brechen).

## Zeitplan-Empfehlung

- v0.1.0 in den nächsten 1–2 Wochen — solange v3.0.0 frisch ist und der API-Vertrag hell vor Augen.
- Nach v0.1.0 — drei Audit-Wochen warten, bis die Library-Reviews durch sind. Falls die Audits Findings für die Library bringen, kommen die in eine 3.0.x und das Bundle bleibt auf `^3.0` stehen ohne Bruch.
- v0.2.0 und v0.3.0 dann nach Bedarf, abhängig davon, was Frühnutzer an Symfony-DX vermissen.

## Tooling-Disziplin

Identisch zur Library — keine Sonderwege:

- PHPStan Level 9 + bleedingEdge, keine Baseline.
- PHP-CS-Fixer mit dem gleichen `.php-cs-fixer.dist.php` wie die Library (EUPL-Header inkl.).
- PHPUnit / Pest für Tests; `KernelTestCase` + Symfony's BrowserKit wo nötig.
- CI-Matrix PHP 8.4 + 8.5, Symfony 7.4 (eine Spalte; LTS-only).
- Keine Mutation-Tests im Bundle — die sind in der Library, das Bundle ist im Wesentlichen DI-Verdrahtung.
- Conventional Commits, Keep-a-Changelog, dieselbe Commit-/PR-Disziplin (Memory-Items gelten genauso).

## Was ich nicht machen würde

- Kein »icap-flow-bundle als Monorepo-Subdirectory«. Library und Bundle haben unterschiedliche Release-Zyklen — Bundle bricht öfter, Library möglichst nie. Separates Repo, separate SemVer.
- Keine vorzeitige Symfony-6.4-Unterstützung. Wenn ein Public-Sector-Anwender 6.4 braucht, kann er auf v0.x pinnen, das wird sowieso BC-stabil bleiben innerhalb der Linie. Die Wartungslast einer Multi-Major-Matrix ist es nicht wert, bevor Frühnutzer das aktiv anfragen.
- Kein eigener Audit-Prompt für das Bundle in v0.1. Der Library-Prompt deckt die DI-Readiness der Library ab; das Bundle ist DI-Glue ohne neue Security-Properties. Audit-Würdigkeit kommt erst zu v1.0.
