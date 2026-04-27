# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> Sprache: **Deutsch.** Antworte, dokumentiere und committe in dieser Repo auf Deutsch (Code, Identifier und Wire-Format-Konstanten bleiben englisch).

## Projekt

`ndrstmr/icap-flow` — async-fähiger ICAP-Client (RFC 3507) für PHP 8.4+, veröffentlicht auf Packagist. Library, keine Anwendung.

Die Library scannt Uploads gegen einen ICAP-Server (typischerweise c-icap + ClamAV oder eine Vendor-Appliance: Symantec, Trend Micro, Sophos, McAfee, Kaspersky) und liegt damit auf dem sicherheitskritischen Pfad jeder aufrufenden Anwendung. Jede Änderung in `src/` ist sicherheitsrelevant; siehe Disclaimer im `README.md` und die Reviews unter `docs/review/review_v2-1/`.

## Häufig genutzte Befehle

Alle Workflows laufen über Composer-Skripte (definiert in `composer.json`):

```bash
composer test               # Pest, nur Unit-Suite (Default lokal & im CI-Quality-Job)
composer test:integration   # Pest, Integration-Suite — braucht echten ICAP-Server
composer test:all           # beide Suites
composer stan               # PHPStan Level 9 + bleedingEdge, scannt src/ und tests/
composer cs-check           # PSR-12 via php-cs-fixer (dry-run)
composer cs-fix             # PSR-12-Fixes anwenden
composer audit              # composer audit + roave/security-advisories
composer mutation           # Pest-Mutation-Tests (Unit, parallel, --min=65)
```

Einzelner Test / einzelne Datei:

```bash
vendor/bin/pest tests/IcapClientTest.php
vendor/bin/pest --filter='returns infected when virus header present'
```

PHPStan auf einem einzelnen Pfad:

```bash
vendor/bin/phpstan analyse src/IcapClient.php
```

### Integrationstests

Die `Integration`-Suite ist aus `composer test` ausgenommen. Sie spricht einen echten ICAP-Server und überspringt sich selbst, wenn die Env-Variablen nicht gesetzt sind — Contributors ohne Docker bekommen also weiterhin einen grünen Lauf. Für die End-to-End-Wire-Format-Prüfung:

```bash
docker compose up -d                               # mnemoshare/clamav-icap auf :1344
ICAP_HOST=127.0.0.1 ICAP_PORT=1344 \
  ICAP_ECHO_SERVICE=/avscan \
  ICAP_CLAMAV_SERVICE=/avscan \
  composer test:integration
```

Beim ersten Start lädt das ClamAV-Image die Signaturdatenbank (~200 MB, mehrere Minuten). Der Healthcheck im Compose-File und der CI-Job in `.github/workflows/ci.yml` warten deshalb auf eine echte `ICAP/1.0`-Antwort, nicht nur auf einen offenen Port — dieses Muster bitte übernehmen, wenn du Integration-Skripte schreibst.

## Architektur

Schichtung (jede Schicht hängt nur von darunter liegenden ab):

```
SynchronousIcapClient ─┐
                       ├─► IcapClient (Facade) ──► PreviewStrategy   ──► PreviewDecision
RetryingIcapClient ────┘                       ──► RequestFormatter  ──► ChunkedBodyEncoder
                                                ──► ResponseParser
                                                ──► TransportInterface (+ SessionAwareTransport)
                                                       │
                                                       ├─ AsyncAmpTransport      (amphp/socket, Default)
                                                       │     └─ AmpTransportSession + AmpConnectionPool
                                                       └─ SynchronousStreamTransport (stream_socket_client)
                                                Cache: OptionsCacheInterface (InMemoryOptionsCache)
                                                DTOs:  IcapRequest, IcapResponse, HttpRequest, HttpResponse, ScanResult
```

Tragende Designregeln — Abweichungen brauchen einen guten Grund:

- **Fail-Secure-Statuscode-Auswertung lebt in `IcapClient::interpretResponse()`.** Jeder Status, der kein eindeutiges Clean-Signal ist (204, 200/206 ohne Virus-Header), wirft eine typisierte Exception. `100 Continue` außerhalb des Preview-Flows ist ein Protokollfehler, kein Clean-Scan — Finding G der v2-Konsolidierung. Hier dürfen keine stillen Fallbacks rein.
- **Der Preview-Flow nutzt `executeRaw()`** (ohne Fail-Secure-Interpretation), weil `100 Continue` mitten im Austausch legitim ist. Externe Aufrufer verwenden `request()` / `scanFile*()`, niemals direkt `executeRaw()`.
- **Strict RFC 3507 §4.5 Preview-Continue setzt `SessionAwareTransport` voraus.** `IcapClient::scanFileWithPreview()` verzweigt: session-fähige Transports laufen über `scanFileWithPreviewStrict` (Preview + Continuation auf demselben Socket, ein logischer ICAP-Request), andere fallen auf `scanFileWithPreviewLegacy` zurück (Zwei-Request-Approximation, kostet einen zusätzlichen TCP/TLS-Handshake). Diese beiden Pfade nicht zusammenlegen.
- **Sockets, die irgendetwas Untypisches gesehen haben, werden geschlossen, nicht gepoolt.** `AsyncAmpTransport` gibt einen Socket nur über `release()` zurück, wenn der Austausch sauber war und der Server kein `Connection: close` gesendet hat. Framing-Fehler oder geworfene Exceptions erzwingen `close()`, damit der nächste Pool-User keine ausgerichteten Bytes sieht.
- **Header-/URI-Injection wird abgewiesen, bevor irgendein Byte den Socket erreicht.** `IcapClient::validateServicePath()` und `validateIcapHeaders()` lehnen CR/LF/NUL/Steuerzeichen in vom Aufrufer übergebenen Service-Pfaden und `extraHeaders` ab. Library-verwaltete Header (`Encapsulated`, `Host`, `Connection` und im Preview-Flow `Preview` / `Allow`) gewinnen in `mergeHeaders()` immer — Aufrufer können sie nicht überschreiben.
- **DTOs und `Config` sind immutable** (`final readonly class` / `readonly`-Properties). Mutatoren liefern neue Instanzen (`Config::withTlsContext`, `withVirusFoundHeaders`, `withLimits`). Keine Setter ergänzen.
- **`RequestFormatter::format()` liefert ein iterierbares Chunk-Array.** Encapsulated-Bodies werden als HTTP/1.1-Chunks gestreamt; Preview-Requests, die bereits den vollständigen Payload tragen, terminieren mit `0; ieof\r\n\r\n`. Bodies nicht in einen einzigen String puffern.
- **`ResponseParser` setzt DoS-Limits durch** (`maxResponseSize`, `maxHeaderCount`, `maxHeaderLineLength`) und holt sie aus `Config`. Wer den Parser per Hand instanziiert, übergibt die Limits aus derselben `Config`, die der Transport sieht.
- **Vendor-Virus-Header sind eine geordnete Liste.** `Config::getVirusFoundHeaders()` liefert `list<string>`, `IcapClient::extractVirusName()` gibt den ersten vorhandenen zurück. Neue Vendor-Header in die Liste aufnehmen, nicht per Sonderfall behandeln.

Öffentliche Einstiegspunkte:

- `IcapClient::create()` / `IcapClient::forServer($host)` — Async/Sync-Convenience-Factories mit Default-Verdrahtung. Gut für Beispiele; produktiver Code konstruiert `IcapClient` explizit mit getunter `Config` und `LoggerInterface`.
- `SynchronousIcapClient::create()` — dünner `await()`-Wrapper um `IcapClient`; existiert, damit Framework-lose Aufrufer `Revolt\EventLoop` nicht selbst kennen müssen.
- `RetryingIcapClient` — Decorator (keine Subklasse), retried 5xx mit Exponential-Backoff. Einen `IcapClient` einwickeln, nicht ableiten.

## Konventionen

- **PHP 8.4 Minimum** (CI fährt 8.4 + 8.5). PHPStan Level 9 + bleedingEdge, keine Baseline — Issues fixen, nicht unterdrücken. Überall `final class`, `readonly` wo Daten unveränderlich sind.
- **`#[\Override]`** auf jeder Interface-Implementierung. PHPStan flagt fehlende Annotationen.
- **EUPL-1.2-Dateiheader** ist Pflicht in jeder PHP-Datei in `src/` und `tests/`. `composer cs-fix` setzt ihn aus `.php-cs-fixer.dist.php` neu — den Header-Block nicht von Hand editieren.
- **PSR-4-Namespace** `Ndrstmr\Icap\` (src) / `Ndrstmr\Icap\Tests\` (tests). PSR-12-Coding-Style wird von php-cs-fixer durchgesetzt.
- **Tests laufen unter Pest 3** (PHPUnit 11 darunter). Mockery steht für Transport-Doubles bereit. Testdateien liegen neben der Schicht, die sie prüfen: `tests/Wire/` für Formatter/Parser, `tests/Transport/` für Transports, `tests/Security/` für Fail-Secure- und DoS-Limit-Invarianten, `tests/Integration/` für Smoke-Tests gegen echte Server. Diese Aufteilung beim Hinzufügen neuer Tests beibehalten.
- **Logging ist PSR-3 und strukturiert.** `IcapClient` schreibt `info` beim Start und Abschluss sowie `warning` bei Fehlschlag, mit den Keys `method`, `uri`, `host`, `port`, `statusCode`, `infected`. Neue Log-Stellen sollen dieses Schema treffen.

## Commits & Pull Requests

Diese Repo folgt **strikt** [Conventional Commits 1.0](https://www.conventionalcommits.org/de/v1.0.0/). Bestehende Historie zeigt das Muster (`docs:`, `feat(v2.1):`, `docs(review):`) — neue Commits halten dieses Schema.

### Commit-Format

```
<type>(<scope>): <subject>

<body — Pflicht, keine reinen Subject-Commits>

<footer — optional: BREAKING CHANGE, Refs, Closes>
```

- **Types:** `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`. Eigene Types nicht erfinden.
- **Scope:** wenn sinnvoll, in Klammern. Übliche Scopes hier: `transport`, `wire`, `preview`, `pool`, `cache`, `security`, `review`, `v2.1`, `v2.2` … oder ein Klassenname (`IcapClient`).
- **Subject:** Imperativ, kleinbuchstaben, kein Punkt am Ende, max. ~70 Zeichen.
- **Body:** Pflicht. Erklärt **warum**, nicht **was** — der Diff zeigt das Was. Bei Items aus `docs/review/review_v2-1/consolidated_v2.1_task-list.md` die Tabellenzeile + Reviewer-Quelle nennen.
- **Footer:**
  - `BREAKING CHANGE: <Beschreibung>` für jeden BC-Break (gehören in den v3.0.0-Bucket, nicht in Patch/Minor).
  - `Refs #<issue>` / `Closes #<issue>` für Verlinkung zur GitHub-Issue.

### Was nicht in Commits / PRs gehört

- **Keine Co-Authored-By-Trailer für Claude / Anthropic / Generated-with-Claude-Code-Werbung.** Auch keine 🤖-Emoji-Footer. Commits sind authored vom Repo-Maintainer; Tooling-Provenance gehört nicht in die Git-Historie.
- **Keine Werbe- oder Generator-Hinweise** in PR-Beschreibungen, Issue-Bodies oder Commit-Messages.
- **Keine `--no-verify`** — wenn pre-commit-Hooks fehlschlagen, Root-Cause fixen und neuen Commit anlegen (nicht amend).

### Pull Requests

- **PR-Titel** ist eine Conventional-Commit-Subject-Zeile (≤ 70 Zeichen). Kein `[WIP]`-Präfix; Drafts sind GitHub-nativ.
- **PR-Body** mit zwei Sektionen:
  - `## Summary` — 1–3 Bullets, was sich ändert und warum.
  - `## Test plan` — Checkliste, wie der Reviewer das Verhalten prüft (`composer test`, einzelner Pest-Filter, Integration-Run gegen Docker, manueller Eicar-Probe-Lauf, …).
- **Verweise auf Roadmap-Items** aus `docs/review/review_v2-1/consolidated_v2.1_task-list.md` mit Item-Nummer und Reviewer-Quellen-Tabelle, wenn der PR ein gelistetes Finding schließt.
- **CI muss grün sein** vor Merge: `cs-check`, `stan`, `test`, `audit`. Integration-Tests sind aktuell `continue-on-error: true` (Item #17 der v2.2-Liste hebt das); ein roter Integration-Step blockiert den Merge **noch nicht**, sollte aber im PR adressiert werden.

### CHANGELOG-Eintrag

Jeder funktionale PR (`feat`, `fix`, `perf`, `refactor` mit User-sichtbarer Wirkung) ergänzt einen Bullet im `[Unreleased]`-Block der `CHANGELOG.md` (Keep-a-Changelog-Format). `docs:`, `test:`, `ci:`, `chore:` brauchen das nicht.

## Roadmap & Reviews — die maßgebliche Quelle

Der **aktuelle Plan** für die Weiterentwicklung steht in **`docs/review/review_v2-1/consolidated_v2.1_task-list.md`**. Vor jeder substanziellen Änderung in `src/`, `tests/`, `examples/cookbook/` oder `.github/workflows/` zuerst diese Datei prüfen — sie sortiert die Findings aus den vier Deep-Research-Audits (Konsens TRL-7, Scores 74–77/100) nach Release-Milestones und benennt pro Item die kritischen Dateien.

Vier unabhängige Audits unter `docs/review/review_v2-1/`:

- `claude_deep-research-audit-icap-flow-v2.1.0.md`
- `codex_deep-research-v2.1.0-audit.md`
- `codex_v2.1.0-production-readiness-audit-2026-04-25.md`
- `jules_deep_research_report_2-1.md`

### Aktueller Release-Plan (Stand: aus der konsolidierten Task-Liste)

- **v2.1.1 — Critical Hotfix** (kein BC-Break, höchste Priorität):
  - `AmpConnectionPool::key()` um TLS-Context-Fingerprint erweitern (Cross-Tenant-Leakage, CVE-würdig im Multi-Tenant-Deployment) — `src/Transport/AmpConnectionPool.php:130-133`.
  - `DefaultPreviewStrategy` für 200/206 erweitern: Virus-Header → `ABORT_INFECTED`, sonst `ABORT_CLEAN`. Der Branch in `IcapClient.php:388` ist heute unerreichbar — Virus-Treffer im Preview kommen als uncatchable Exception zurück.
  - `SECURITY.md:73-75` von Stale-Claims befreien (Cache/Pool/Retry sind seit v2.0/2.1 implementiert).
  - Kleine Doku-Korrekturen in Cookbook + `ConnectionPoolInterface`-Phpdoc.
- **v2.1.2 — CI-Quality-Patch** (kein BC-Break): Strict-§4.5-Streaming-Fix (`stream_get_contents()` durch `ChunkedBodyEncoder::encodeRemainderFromStream()` ersetzen) plus `failOnRisky=true` im Phpunit-Setup und PHPStan-Memory-Limit-Stabilisierung.
- **v2.2.0 — Minor, additiv:** OPTIONS-getriebenes Preview-/Pool-Tuning, `NullConnectionPool`, Mutation-Tests als Required-CI, Coverage-Push (`AmpConnectionPool` 54 → ≥90 %, `SynchronousStreamTransport` 41 → ≥85 %), 4 zusätzliche Cookbook-Beispiele, OpenTelemetry-Decorator, PHPBench-Suite.
- **v2.3.0** — separates Repo `ndrstmr/icap-flow-bundle` (Symfony-Bundle), nicht in dieser Repo.
- **v3.0.0** — gesammelte BC-Breaks: `executeRaw()` `protected`/Interface, `options()` zu `Future<IcapResponse>`, `IcapResponseException` entfernen.

Konkrete Korrekturen aus der konsolidierten Liste, die beim Code-Lesen auffallen können:

- **Jules' Befund zur Header-Array-Validierung ist faktisch falsch** — `IcapClient::validateIcapHeaders()` Z. 600-613 iteriert per `foreach ((array) $value as $v)` über jeden Array-Eintrag. Diesen Punkt **nicht** als offen behandeln.
- Der Strict-§4.5-Streaming-Bug ist von 3 von 4 Reviewern erfasst (Jules nicht).

### Historische Reviews

`docs/review/` (Top-Level, ohne `review_v2-1/`-Unterordner) enthält die drei v1-Audits, die das v2-Redesign getrieben haben, sowie deren `consolidated_task-list.md`. Diese Dateien sind **historische Provenance** — abgeschlossen mit dem v2.0-Release. Für laufende Arbeit ist `docs/review/review_v2-1/consolidated_v2.1_task-list.md` der Anker, nicht die alten v1-Listen.

`docs/migration-v1-to-v2.md` dokumentiert jeden v1 → v2 Break. `CHANGELOG.md` folgt Keep-a-Changelog und SemVer ist committed. v1 ist deprecated; dort keine neuen Codepfade einbauen.
