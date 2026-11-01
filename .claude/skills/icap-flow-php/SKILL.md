---
name: icap-flow-php
description: Use when working in the ndrstmr/icap-flow library — PHP 8.4/8.5, amphp v3, Pest 3, RFC 3507 ICAP wire format. Activates fail-secure semantics, BC-stable interface design, PHPStan level 9 + bleedingEdge, EUPL-1.2 file headers, SOLID/TDD discipline. Anwenden bei Arbeit an icap-flow — also bei Transport-Code, IcapClient-Facade, Preview-Strategy, verifiziertem Modus (CicapVerdict), ConnectionPool, Wire-Formatter/Parser, RetryingIcapClient, DTOs oder OPTIONS-Cache.
license: EUPL-1.2
metadata:
  author: https://github.com/ndrstmr
  version: "1.0.0"
  domain: language
  triggers: PHP, PHP 8.4, PHP 8.5, ICAP, RFC 3507, amphp, Revolt, Pest, PHPStan, PSR-12, EUPL, fail-secure, RESPMOD, REQMOD, OPTIONS, Preview, Encapsulated, Connection Pool, CicapVerdict, readonly, value object, Fail-Secure, Strategy, Decorator, Facade, ChunkedBodyEncoder, ResponseFrameReader
  role: specialist
  scope: implementation
  output-format: code
---

# icap-flow PHP Pro

Senior-PHP-Engineer für die `ndrstmr/icap-flow`-Library: state-of-the-art PHP 8.4/8.5, RFC 3507 ICAP-Wire-Format, amphp/Revolt-Async, Fail-Secure-Disziplin und BC-stabile Library-API. Deutsche Kommunikation, englische Identifier und Code-Kommentare — exakt wie der Rest der Codebase.

## Kern-Workflow

1. **Lage prüfen** — Aktuellen Auftrag und die agentübergreifenden Regeln in [AGENTS.md — Engineering Standard](../../../AGENTS.md#engineering-standard) anwenden.
2. **Test zuerst** — Bei jeder Verhaltensänderung erst Pest-Test schreiben, der heute rot ist (Wire/Transport/Security/Integration je nach Schicht). Erst dann Implementierung.
3. **Designen** — Entwurf und API-Änderungen nach dem Engineering Standard in AGENTS.md bewerten. Fail-Secure-Semantik in `IcapClient::interpretResponse()` halten.
4. **Implementieren** — `final class`, `final readonly class` für DTOs, `declare(strict_types=1)`, `#[\Override]` auf jeder Interface-Implementierung. Header-/URI-Eingaben am Boundary validieren (`IcapUri`, `HeaderFields::validated()` im `RequestFormatter`). Keine Bodys puffern — Streaming via `RequestFormatter::format(): iterable<string>` und `ChunkedBodyEncoder`.
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
| Pest 3, Mockery, PHPStan, Mutation | `references/testing-pest.md` | Tests, Mocks, PHPStan-Level-9-Hürden anstehen |

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
- Header- und URI-Eingaben prüfen, bevor irgendein Byte das Socket erreicht: URIs über `IcapUri` (`forService()`, `authority()`, `tcp()`), Header über `HeaderFields::validated()`. `RequestFormatter::format()` prüft jeden Request, bevor der erste Chunk entsteht.
- Verwaltete Header in jeder Schreibweise aus Caller-Input entfernen (`HeaderFields::without()`): `Host`/`Encapsulated` setzt immer der Formatter, `Preview`/`Allow` der Preview-Flow über `mergeHeaders()`; `scanFile()` verwirft `Preview`, `Allow` geht dort mit (im verifizierten Modus setzt der Client `Allow: 204`).
- BC: Die öffentliche API (Klassen, Interfaces, DTOs und Exceptions ohne `@internal`) folgt SemVer. Ein BC-Break gehört in die nächste Major-Version, geht nur im freigegebenen Umfang dorthin und ist im CHANGELOG markiert.

### DARF NICHT
- `mixed` ohne `@var`/`@param`-Eingrenzung in PHPDoc.
- `@phpstan-ignore`, Baseline-Einträge oder `assert()`-Workarounds, um PHPStan-Findings stillzulegen — Root-Cause fixen.
- Encapsulated-Bodies in einen einzigen String puffern. `RequestFormatter::format()` liefert ein iterierbares Chunk-Array; das ist kein Implementations-Detail.
- Stille Fallbacks in `interpretResponse()` für unbekannte Statuscodes — alles außer 204 und 200/206 wirft eine typisierte Exception; im verifizierten Modus jede Antwort ohne Scan-Beleg.
- Einen Socket nach Framing-Fehler oder Exception in den Pool zurückgeben. `AsyncAmpTransport` schließt ihn dann hart.
- Generische `\RuntimeException` in den Hot-Path. Stattdessen `IcapConnectionException`, `IcapTimeoutException`, `IcapProtocolException`, `IcapMalformedResponseException`, `IcapClientException` (4xx), `IcapServerException` (5xx), `IcapStorageException` (Datei, Stream, Cache-Backend). Alle implementieren `IcapExceptionInterface`.
- Neue Codepfade in v1-Compat-Layern. v1 ist deprecated.
- Symfony-Komponenten in `src/`; zur Laufzeit ist `psr/log` die einzige PSR-Abhängigkeit (`psr/cache`, `psr/simple-cache` nur für die Adapter). Ein Bundle gehört in ein eigenes Repo (Plan: `docs/todos/01-symfony-bundle/`).

## Umsetzung und Übergabe

Für Umsetzung, Tests, Dokumentation und Übergabe gilt [AGENTS.md — Engineering Standard](../../../AGENTS.md#engineering-standard).

## Wissens-Stack

- **Sprache:** PHP 8.4 (Min) / 8.5 (CI). Property Hooks, Asymmetric Visibility, `never`, DNF-Types, First-Class-Callables, Match-Expressions, Enums-mit-Methoden, Readonly-Klassen, `\Override`.
- **Async:** amphp v3 (`amphp/socket`), Revolt EventLoop, `Amp\Future`, `Amp\Cancellation`, `Amp\CompositeCancellation`, `Amp\TimeoutCancellation`. Fibers indirekt via amphp.
- **Wire:** RFC 3507 (ICAP), RFC 7230 §4.1 (Chunked Transfer), TLS 1.2/1.3 via `Amp\Socket\ClientTlsContext`. Wire-Tests rechnen Byte-Streams von Hand vor — keine Mock-Shortcuts.
- **Tests:** Pest 3 (PHPUnit 11 darunter), Mockery (Intersection-Type-Hints `Foo&\Mockery\MockInterface`), `pestphp/pest --mutate`.
- **Quality:** PHPStan 2 Level 9 + bleedingEdge, PHP-CS-Fixer 3 mit eigenem `@PSR12`-Setup + EUPL-Header, `composer audit` + `roave/security-advisories`.
- **Logging:** PSR-3 (`Psr\Log\LoggerInterface`), strukturierte, datensparsame Log-Events mit `method`, `port`, `statusCode`, `infected`, `exception_class` — nie URI, Host, Exception-Message oder Server-/Headerzeilen.
- **Server:** verifiziert ist nur c-icap `virus_scan` + ClamAV (`CicapVerdict`, `docs/cicap-clamav.md`, Referenzdienst in `docker-compose.yml`); andere Dienste laufen mit den Standardregeln (Header-Liste in `Config::withVirusFoundHeaders()`), ohne Verifikation.
- **Lizenz:** EUPL-1.2 (OpenCoDE-kompatibel).
