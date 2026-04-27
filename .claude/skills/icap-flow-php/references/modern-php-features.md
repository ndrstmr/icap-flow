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
        public bool $isInfected,
        public ?string $virusName,
        public IcapResponse $originalResponse,
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
}
```

`Config` ist `final readonly`, mutiert via `with*()`-Methoden, die neue Instanzen liefern (`withTlsContext`, `withVirusFoundHeaders`, `withLimits`). Keine Setter.

## Enums mit Methoden — Strategy-Entscheidungen

Verwende einen Backed-Enum statt Konstanten oder Strings, sobald eine endliche Menge von Zuständen mit eigener Logik existiert. Beispiel aus `src/PreviewDecision.php`:

```php
// [EUPL-Header]
declare(strict_types=1);

namespace Ndrstmr\Icap;

enum PreviewDecision: string
{
    case ABORT_CLEAN     = 'abort_clean';
    case ABORT_INFECTED  = 'abort_infected';
    case CONTINUE_SENDING = 'continue_sending';

    public function isTerminal(): bool
    {
        return $this !== self::CONTINUE_SENDING;
    }
}
```

Im v2.1.1-Hotfix wird `DefaultPreviewStrategy::handlePreviewResponse()` um den 200/206-Branch erweitert, der `ABORT_INFECTED` / `ABORT_CLEAN` zurückgibt — das ist genau die Stelle, an der der Enum seine Existenzberechtigung beweist.

## Match statt Switch — Statuscode-Auswertung

```php
// In IcapClient::interpretResponse()
return match (true) {
    $code === 204                    => new ScanResult(false, null, $response),
    $code === 200 || $code === 206   => $this->scanResultFromMaybeInfected($response, $config),
    $code === 100                    => throw new IcapProtocolException(
        'ICAP 100 Continue is only valid during a preview exchange.',
        $code,
    ),
    $code >= 400 && $code < 500      => throw new IcapClientException(
        sprintf('ICAP client error (%d).', $code),
        $code,
    ),
    $code >= 500 && $code < 600      => throw new IcapServerException(
        sprintf('ICAP server error (%d).', $code),
        $code,
    ),
    default                          => throw new IcapResponseException(
        'Unexpected ICAP status: ' . $code,
        $code,
    ),
};
```

Die heutige Implementierung in `IcapClient.php:540ff` nutzt noch `if`-Ketten. Refactor auf `match (true)` ist v2.2-würdig, kein BC-Break — aber bitte mit identischen Tests in `tests/IcapClientTest.php` und `tests/Security/FailSecureAndValidationTest.php` flankieren.

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

## Named Arguments — Pflicht bei Konstruktoren mit ≥3 Parametern

`Config`, `IcapRequest`, `HttpResponse`, `ScanResult` haben alle ≥3 Parameter. Tests und Cookbook-Beispiele rufen sie ausschließlich mit Named Arguments auf — das macht Diffs lesbar und überlebt Parameter-Reorder.

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

Für `array_map`, `array_filter`, Decorator-Wrapping und Pool-Factories.

```php
$models = array_map(
    ModelInfo::fromHeader(...),
    $response->headers['Methods'] ?? [],
);
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

## Property Hooks (PHP 8.4)

Können sinnvoll sein, wenn `IcapResponse`-Header in normalisierter Form bereitgestellt werden müssen. Heute löst `ResponseParser` das eager beim Bauen. Wenn ein neuer DTO mit lazy-normalisiertem View-State entsteht, nutze Property Hooks statt einer Getter-Methode.

```php
final class HeaderBag
{
    /** @var array<string, list<string>> */
    private array $raw = [];

    /** @var array<string, list<string>> */
    public array $normalized {
        get => array_change_key_case($this->raw, CASE_LOWER);
    }
}
```

## Asymmetric Visibility (PHP 8.4)

Ideal für interne Counter / Idle-Timestamps in `AmpConnectionPool`, die von außen lesbar, aber nur von innen schreibbar sein sollen — ist im v2.2-Idle-Eviction-Item (`maxIdleAge`) ein guter Kandidat:

```php
final class PooledSocket
{
    public private(set) int $checkoutCount = 0;
    public private(set) float $lastReleasedAt;

    public function markCheckedOut(): void
    {
        ++$this->checkoutCount;
    }

    public function markReleased(float $now): void
    {
        $this->lastReleasedAt = $now;
    }
}
```

## DNF-Types & Intersection-Types

Wir nutzen Intersections vor allem in Mockery-Tests:

```php
/** @var RequestFormatterInterface&\Mockery\MockInterface $formatter */
$formatter = m::mock(RequestFormatterInterface::class);
```

DNF-Types (`(A&B)|null`) sind verfügbar, kommen aber bisher nicht in `src/` vor. Wenn nötig, in PHPDoc und nativem Type-Hint identisch halten.

## Attribute (Custom)

Für Cookbook-Beispiele rund um v2.2-OPTIONS-Auto-Tuning und v2.3-Bundle nützlich, in `src/` selten. Beispiel aus dem geplanten Bundle-Repo:

```php
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class IcapClean
{
    public function __construct(
        public string $service = '/avscan',
        public int $previewSize = 1024,
    ) {
    }
}
```

## Quick-Reference

| Feature | PHP | Wo in icap-flow |
|---|---|---|
| `final readonly class` | 8.2+ | Alle DTOs, `Config` |
| Backed Enums + Methoden | 8.1+ | `PreviewDecision` |
| `#[\Override]` | 8.3+ | Pflicht auf jeder Interface-Impl |
| Named Arguments | 8.0+ | Konstruktoren mit ≥3 Params |
| `match` (Statement + Expression) | 8.0+ | `DefaultPreviewStrategy`, geplanter `interpretResponse`-Refactor |
| First-Class Callables | 8.1+ | Header-Map-Builder, Pool-Factories |
| `never` | 8.1+ | Throw-Helfer in Validatoren |
| Property Hooks | 8.4 | Kandidat für Lazy-Normalisierungs-Bags |
| Asymmetric Visibility | 8.4 | Pool-Stats, v2.2-Idle-Eviction |
| Intersection-Types | 8.1+ | Mockery-Test-Hints |
| DNF-Types | 8.2+ | Verfügbar, in `src/` derzeit ungenutzt |
| `\Throwable` als param | überall | `try/catch/finally` mit `\Throwable` (siehe `IcapClient::request()`) |
