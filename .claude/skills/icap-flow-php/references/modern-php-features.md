# Modern PHP 8.4 / 8.5 — icap-flow-Konventionen

Quelle für Versions-Minimum: `composer.json` (`"php": "^8.4"`), CI-Matrix in `.github/workflows/ci.yml` (PHP 8.4 + 8.5). Was hier gezeigt wird, ist in der Codebase bereits aktiv oder darf in neuem Code verwendet werden.

## Strikte Typen & Datei-Header

Jede PHP-Datei in `src/` und `tests/` startet mit dem EUPL-1.2-Klassen-Docblock direkt nach `<?php` und einem `declare(strict_types=1)`. `composer cs-fix` setzt den Header aus `.php-cs-fixer.dist.php` neu — **nicht** von Hand bearbeiten.

```php
<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * This file is part of icap-flow.
 *
 * Licensed under the EUPL, Version 1.2 only (the "Licence");
 * you may not use this work except in compliance with the Licence.
 * ...
 */

declare(strict_types=1);

namespace Ndrstmr\Icap;
```

In Skill-Beispielen unten lasse ich den Header der Lesbarkeit halber weg und schreibe `// [EUPL-Header]`. Im echten Code ist er Pflicht.

## `final readonly class` für DTOs

Alle Werte, die durch das System reisen (Requests, Responses, Config, Result), sind unveränderlich.

```php
// [EUPL-Header]
declare(strict_types=1);

namespace Ndrstmr\Icap\DTO;

final readonly class ScanResult
{
    public function __construct(
        private bool $isInfected,
        private ?string $virusName,
        private IcapResponse $originalResponse,
    ) {
    }

    public function isInfected(): bool
    {
        return $this->isInfected;
    }

    public function getVirusName(): ?string
    {
        return $this->virusName;
    }

    public function getOriginalResponse(): IcapResponse
    {
        return $this->originalResponse;
    }
}
```

`Config` ist `final readonly`, mutiert via `with*()`-Methoden, die neue Instanzen liefern (`withTlsContext`, `withVirusFoundHeaders`, `withLimits`, `withTimeouts`). Keine Setter.

## Enums — Strategy-Entscheidungen

Verwende einen Backed-Enum statt Konstanten oder Strings, sobald eine endliche Menge von Zuständen existiert. Beispiel aus `src/PreviewDecision.php`:

```php
// [EUPL-Header]
declare(strict_types=1);

namespace Ndrstmr\Icap;

enum PreviewDecision: string
{
    case CONTINUE_SENDING = 'continue';
    case ABORT_CLEAN = 'abort_clean';
    case ABORT_INFECTED = 'abort_infected';
}
```

`DefaultPreviewStrategy::handlePreviewResponse()` bildet die Antwort auf ein Preview auf diese drei Fälle ab.

## Match statt Switch — Statuscode-Auswertung

```php
// In DefaultPreviewStrategy::handlePreviewResponse()
return match (true) {
    $code === 100 => PreviewDecision::CONTINUE_SENDING,
    $code === 204 => PreviewDecision::ABORT_CLEAN,
    $code === 200,
    $code === 206 => $this->classifyBodyResponse($previewResponse),
    default => throw IcapStatus::failure($code) ?? new IcapProtocolException(
        'Unexpected preview status code: ' . $code,
        $code,
    ),
};
```

## `#[\Override]` — überall Pflicht

Auf jeder Methode, die ein Interface oder eine Parent-Methode implementiert. PHPStan Level 9 + bleedingEdge meldet jedes Fehlen.

```php
final class DefaultPreviewStrategy implements PreviewStrategyInterface
{
    #[\Override]
    public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision
    {
        // ...
    }
}
```

## Named Arguments — bei Konstruktoren mit ≥3 Parametern

`Config`, `IcapRequest`, `HttpResponse`, `ScanResult` haben ≥3 Parameter. Neuer Code ruft sie mit Named Arguments auf — das macht Diffs lesbar und überlebt Parameter-Reorder.

```php
$envelope = new HttpResponse(
    statusCode: 200,
    headers: [
        'Content-Type'   => ['application/octet-stream'],
        'Content-Length' => [(string) $fileSize],
    ],
    body: $previewBytes,
);
```

## First-Class Callables

Für Callbacks, Decorator-Wrapping und Connector-Defaults, z. B. in `AmpConnectionPool`:

```php
$this->connector = $connector ?? SocketOpener::open(...);
```

## `never` für Throw-Helfer

```php
private function rejectInjection(string $service): never
{
    throw new \InvalidArgumentException(
        'Service path contains control characters: ' . var_export($service, true),
    );
}
```

## Property Hooks, Asymmetric Visibility (PHP 8.4)

In `src/` derzeit nicht verwendet. Header werden nicht über eine normalisierte Map gelesen, sondern über `HeaderFields::values()` in jeder Schreibweise: ein `array_change_key_case()` verlöre Werte, die der Server in zwei Schreibweisen sendet.

## DNF-Types & Intersection-Types

Wir nutzen Intersections vor allem in Mockery-Tests:

```php
/** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
$formatter = m::mock(RequestFormatterInterface::class);
```

DNF-Types (`(A&B)|null`) sind verfügbar, kommen aber bisher nicht in `src/` vor. Wenn nötig, in PHPDoc und nativem Type-Hint identisch halten.

## Attribute

In `src/` nur `#[\Override]`.

## Quick-Reference

| Feature | PHP | Wo in icap-flow |
|---|---|---|
| `final readonly class` | 8.2+ | Alle DTOs, `Config`, `CicapVerdict` |
| Backed Enums | 8.1+ | `PreviewDecision` |
| `#[\Override]` | 8.3+ | Pflicht auf jeder Interface-Impl |
| Named Arguments | 8.0+ | Konstruktoren mit ≥3 Params |
| `match` (Statement + Expression) | 8.0+ | `DefaultPreviewStrategy` |
| First-Class Callables | 8.1+ | Connector-Defaults (`SocketOpener::open(...)`) |
| `never` | 8.1+ | Verfügbar für Throw-Helfer, in `src/` derzeit ungenutzt |
| Property Hooks | 8.4 | nicht verwendet |
| Asymmetric Visibility | 8.4 | nicht verwendet |
| Intersection-Types | 8.1+ | Mockery-Test-Hints |
| DNF-Types | 8.2+ | Verfügbar, in `src/` derzeit ungenutzt |
| `\Throwable` als param | überall | `try/catch/finally` mit `\Throwable` (siehe `IcapClient::requestWith()`) |
