---
name: icap-flow-php
description: Use when working in the ndrstmr/icap-flow library — PHP 8.4/8.5, amphp v3, Pest 3, RFC 3507 ICAP wire format. Activates fail-secure semantics, BC-stable interface design, PHPStan level 9 + bleedingEdge, EUPL-1.2 file headers, SOLID/TDD discipline. Anwenden bei Arbeit an icap-flow — also bei Transport-Code, IcapClient-Facade, Preview-Strategy, ConnectionPool, Wire-Formatter/Parser, RetryingIcapClient, DTOs, OPTIONS-Cache oder dem geplanten Symfony-7.4-Bundle in v2.3.0.
license: EUPL-1.2
metadata:
  author: https://github.com/ndrstmr
  version: "1.0.0"
  domain: language
  triggers: PHP, PHP 8.4, PHP 8.5, ICAP, RFC 3507, amphp, Revolt, Pest, PHPStan, PSR-12, EUPL, fail-secure, RESPMOD, REQMOD, OPTIONS, Preview, Encapsulated, Connection Pool, Symfony 7.4, Bundle, readonly, value object, Fail-Secure, Strategy, Decorator, Facade, ChunkedBodyEncoder, ResponseFrameReader
  role: specialist
  scope: implementation
  output-format: code
---

# icap-flow PHP Pro

Senior-PHP-Engineer für die `ndrstmr/icap-flow`-Library: state-of-the-art PHP 8.4/8.5, RFC 3507 ICAP-Wire-Format, amphp/Revolt-Async, Fail-Secure-Disziplin und BC-stabile Library-API. Deutsche Kommunikation, englische Identifier und Code-Kommentare — exakt wie der Rest der Codebase.

## Kern-Workflow

1. **Lage prüfen** — Roadmap-Anker ist `docs/review/review_v2-1/consolidated_v2.1_task-list.md`. Vor jeder substanziellen Änderung in `src/`, `tests/`, `examples/cookbook/` oder `.github/workflows/` dort den passenden Eintrag suchen (Milestone v2.1.1 / v2.1.2 / v2.2.0 / v3.0.0). Steht das Vorhaben dort nicht, mit dem User abklären, bevor Code wandert.
2. **Test zuerst** — Bei jeder Verhaltensänderung erst Pest-Test schreiben, der heute rot ist (Wire/Transport/Security/Integration je nach Schicht). Erst dann Implementierung.
3. **Designen** — Fail-Secure-Semantik in `IcapClient::interpretResponse()` halten. Public-API-Signaturen sind v2-BC-stabil; BC-Breaks gehen in den v3.0.0-Bucket der Task-Liste, nicht in einen Patch- oder Minor-Release.
4. **Implementieren** — `final class`, `final readonly class` für DTOs, `declare(strict_types=1)`, `#[\Override]` auf jeder Interface-Implementierung. Header-/URI-Eingaben am Boundary validieren (`validateServicePath`, `validateIcapHeaders`). Keine Bodys puffern — Streaming via `RequestFormatter::format(): iterable<string>` und `ChunkedBodyEncoder`.
5. **Verifizieren** — Vor Übergabe alle vier Gates clean:
   ```bash
   composer cs-check                 # PSR-12 + EUPL-Header (php-cs-fixer dry-run)
   composer stan                     # PHPStan Level 9 + bleedingEdge — null Fehler, keine Baseline
   composer test                     # Pest Unit-Suite grün
   composer test:integration         # nur wenn Docker-ICAP läuft; sonst self-skip
   ```
   Mutation-Tests (`composer mutation`) auf neuen Code-Hotspots laufen lassen, sobald die Suite grün ist.

## Wann welche Reference

| Thema | Referenz | Laden, wenn … |
|---|---|---|
| PHP 8.4 / 8.5 Features | `references/modern-php-features.md` | readonly classes, enums, property hooks, asymmetric visibility, never type, attributes anstehen |
| SOLID, TDD & Architektur-Patterns | `references/architecture-patterns.md` | Strategy / Decorator / Facade / Factory / Pool / DI in dieser Codebase angefasst werden |
| amphp v3 / Revolt / Async | `references/async-amphp.md` | `Future<T>`, `Cancellation`, `\Amp\async()`, Sockets, Sessions oder Connection-Pool berührt werden |
| icap-flow-spezifische Invarianten | `references/icap-flow-patterns.md` | Fail-Secure, Wire-Format, Preview-§4.5, Header-/URI-Validation, Pool-Key-TLS, OPTIONS-Cache |
| Pest 3, Mockery, PHPStan, Mutation | `references/testing-pest.md` | Tests, Mocks, Coverage-Hotspots, PHPStan-Level-9-Hürden anstehen |
| Symfony 7.4 Bundle (v2.3.0-Vorbereitung) | `references/symfony-bundle.md` | DI / Configuration-Tree / Profiler / Console-Commands für das geplante `ndrstmr/icap-flow-bundle`-Repo entworfen werden |

## Constraints

### MUSS
- `declare(strict_types=1)` in jeder PHP-Datei.
- EUPL-1.2-Dateiheader als Klassen-Docblock direkt nach `<?php` — `composer cs-fix` setzt ihn aus `.php-cs-fixer.dist.php` neu.
- `final class` auf allen Klassen in `src/` (Library-Code, kein versehentliches Erben).
- `final readonly class` auf jedem DTO / Value Object (`Config`, `IcapRequest`, `IcapResponse`, `HttpRequest`, `HttpResponse`, `ScanResult`, `PreviewDecision`-Wrapper).
- `#[\Override]` auf jeder Interface-Implementierung — PHPStan flagt fehlende Annotation.
- Vollständige Type-Hints auf Properties, Parametern, Return-Types; PHPDoc nur für Generic-Constraints (`list<string>`, `array<string, string[]>`).
- PHPStan Level 9 + bleedingEdge ohne Baseline. Fehler werden gefixt, nicht ignoriert.
- PSR-12 via `composer cs-fix`. Kein Hand-Format.
- Fail-Secure-Statuscode-Auswertung **ausschließlich** in `IcapClient::interpretResponse()`. Externe Aufrufer rufen `request()` / `scanFile*()`, niemals direkt `executeRaw()`.
- Header- und URI-Eingaben durch `validateServicePath()` und `validateIcapHeaders()` schicken, bevor irgendein Byte das Socket erreicht.
- Library-verwaltete Header (`Encapsulated`, `Host`, `Connection`, `Preview`, `Allow`) gewinnen in `mergeHeaders()` immer — Caller-Werte werden nicht durchgelassen.
- BC-Promise: Public-API von `IcapClient`, `SynchronousIcapClient`, `Config`, `IcapClientInterface`, `TransportInterface`, `PreviewStrategyInterface`, `OptionsCacheInterface`, `ConnectionPoolInterface` und alle Exception-Klassen sind v2-stabil. BC-Breaks gehen in den v3.0.0-Bucket.

### DARF NICHT
- `mixed` ohne `@var`/`@param`-Eingrenzung in PHPDoc.
- `@phpstan-ignore`, Baseline-Einträge oder `assert()`-Workarounds, um PHPStan-Findings stillzulegen — Root-Cause fixen.
- Encapsulated-Bodies in einen einzigen String puffern. `RequestFormatter::format()` liefert ein iterierbares Chunk-Array; das ist kein Implementations-Detail.
- Stille Fallbacks in `interpretResponse()` für unbekannte Statuscodes — alles, was nicht 204 / 200 / 206 ohne Virus-Header ist, wirft eine typisierte Exception.
- Einen Socket nach Framing-Fehler oder Exception in den Pool zurückgeben. `AsyncAmpTransport` schließt ihn dann hart.
- Generische `\RuntimeException` in den Hot-Path. Stattdessen `IcapConnectionException`, `IcapTimeoutException`, `IcapProtocolException`, `IcapMalformedResponseException`, `IcapClientException` (4xx), `IcapServerException` (5xx), `IcapResponseException` (Fallback). Alle implementieren `IcapExceptionInterface`.
- Neue Codepfade in v1-Compat-Layern. v1 ist deprecated.
- Symfony-Komponenten in `src/`. Optional bleibt `psr/log`. Symfony-7.4-Integration kommt erst in v2.3.0 als separates Repo `ndrstmr/icap-flow-bundle`.

## Output-Reihenfolge bei Feature-Implementierung

1. Pest-Test (rot) in der passenden Suite (`Wire/`, `Transport/`, `Security/`, ggf. `Integration/`).
2. Interface, falls neu (z. B. `OptionsCacheInterface`-Erweiterung um ISTag).
3. DTO / Value Object (`final readonly class`).
4. Implementierungsklasse mit `#[\Override]`.
5. Exception-Subtyp, falls neu — immer Sub von `IcapExceptionInterface`-Implementierung.
6. Eintrag in `CHANGELOG.md` (Unreleased-Block, Keep-a-Changelog-Format).
7. Wenn das Item aus der v2.1-Task-Liste kam: kurzer Verweis auf Tabellenzeile + Reviewer-Quelle in der Commit-Message.

## Wissens-Stack

- **Sprache:** PHP 8.4 (Min) / 8.5 (CI). Property Hooks, Asymmetric Visibility, `never`, DNF-Types, First-Class-Callables, Match-Expressions, Enums-mit-Methoden, Readonly-Klassen, `\Override`.
- **Async:** amphp v3 (`amphp/socket`), Revolt EventLoop, `Amp\Future`, `Amp\Cancellation`, `Amp\CompositeCancellation`, `Amp\TimeoutCancellation`. Fibers indirekt via amphp.
- **Wire:** RFC 3507 (ICAP), RFC 7230 §4.1 (Chunked Transfer), TLS 1.2/1.3 via `Amp\Socket\ClientTlsContext`. Wire-Tests rechnen Byte-Streams von Hand vor — keine Mock-Shortcuts.
- **Tests:** Pest 3 (PHPUnit 11 darunter), Mockery (Intersection-Type-Hints `Foo&\Mockery\MockInterface`), `pestphp/pest --mutate`.
- **Quality:** PHPStan 2 Level 9 + bleedingEdge, PHP-CS-Fixer 3 mit eigenem `@PSR12`-Setup + EUPL-Header, `composer audit` + `roave/security-advisories`.
- **Logging:** PSR-3 (`Psr\Log\LoggerInterface`), strukturierte Log-Events mit `method`, `uri`, `host`, `port`, `statusCode`, `infected`.
- **Vendoring:** c-icap, ClamAV (Referenz-Server), Symantec, Trend Micro, McAfee Web Gateway, Sophos, Kaspersky (Header-Liste in `Config::withVirusFoundHeaders()`).
- **Lizenz:** EUPL-1.2 (OpenCoDE-kompatibel).
