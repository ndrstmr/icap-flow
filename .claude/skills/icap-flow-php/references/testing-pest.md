# Testing & Quality — Pest 3, Mockery, PHPStan, Mutation

icap-flow ist test-getrieben: jede Verhaltensänderung beginnt mit einem **roten** Pest-Test. Diese Datei zeigt den Stack, der in der Repo aktiv ist.

## Vier Gates vor Übergabe

```bash
composer cs-check                 # PSR-12 + EUPL-Header (php-cs-fixer dry-run)
composer stan                     # PHPStan Level 9 + bleedingEdge — null Fehler, keine Baseline
composer test                     # Pest Unit-Suite grün
composer test:integration         # Pest Integration-Suite — env-gated, self-skip ohne ICAP_HOST
```

`composer audit` läuft im CI auch — lokal sinnvoll, wenn `composer.lock` angefasst wurde.

## Pest 3 — Teststil in dieser Repo

Der Stil ist **Funktional-Pest** (`it()` / `test()` / `expect()`), nicht der Class-Stil. Class-Mixins via `uses()` für Setup/Tear-Down werden eingesetzt.

```php
// [EUPL-Header]
declare(strict_types=1);

use Mockery as m;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\TransportInterface;

uses(AsyncTestCase::class);

it('rejects status 100 outside the preview flow as protocol error', function () {
    [$config, , $transport, , , $client] = makeClient();

    /** @var \Mockery\Expectation $tx */
    $tx = $transport->shouldReceive('request');
    $tx->once()->andReturn(\Amp\Future::complete("ICAP/1.0 100 Continue\r\n\r\n"));

    expect(fn () => $client->request(new IcapRequest('RESPMOD', 'icap://example/avscan'))->await())
        ->toThrow(IcapProtocolException::class);
});
```

**Konventionen:**
- Datei-Suffix `*Test.php`. Pest sammelt sie über `phpunit.xml.dist`.
- `it('verb …')` für Behavior, `test('description')` für Setup-/Edge-Case-Notationen — beides erlaubt, durchgängig wie der Rest des Verzeichnisses bleiben.
- `expect($x)->toBe($y)` / `->toEqual()` / `->toThrow()` / `->toContain()`. Kein `assertSame()` mischen.
- `uses(AsyncTestCase::class)` immer, wenn der Test `await()` aufruft oder Fibers betritt.

## Test-Verzeichnis-Layout

```
tests/
├── AsyncTestCase.php
├── Pest.php                          # Bootstrap; Mockery::close() in afterEach
├── DTO/                              # Value-Object-Verhalten
├── Exception/                        # Exception-Hierarchie / -Codes
├── Wire/                             # RFC-3507-Bytes (Formatter / Parser)
├── Transport/                        # Socket-/Pool-/Session-Verhalten
├── Security/                         # Fail-Secure, DoS-Limits, Validators
├── Integration/                      # echte Server (Docker), env-gated
├── IcapClientTest.php
├── RetryingIcapClientTest.php
├── PreviewContinueStrictTest.php
├── … weitere Behavior-Tests im Top-Level
```

Faustregel: **dort hinzufügen, wo gleichartige Tests schon liegen**. Eine neue Datei macht man nur, wenn die behandelte Klasse keinen Test-Pendant hat.

## Mockery — Intersection-Type-Hints

Pest 3 spielt mit Mockery, sobald ein `m::mock()` einen Type kreuzt. Wir nutzen Intersection-Hints, damit PHPStan beide Typen (das Interface **und** das Mock-Interface) sieht:

```php
/** @var TransportInterface&\Mockery\MockInterface $transport */
$transport = m::mock(TransportInterface::class);

/** @var \Mockery\Expectation $tx */
$tx = $transport->shouldReceive('request');
$tx->once()->with(/* ... */)->andReturn(\Amp\Future::complete($body));
```

Der Cast in `\Mockery\Expectation` ist nötig, weil PHPStan bleedingEdge sonst auf der return-Type-Lücke hängen bleibt. Vorbild: `tests/IcapClientTest.php:42-58`.

`Mockery::close()` läuft im `afterEach`-Hook in `tests/Pest.php` — die Tests müssen es nicht aufrufen.

## Wire-Tests — keine Mocks

Wire-Suite testet den Formatter mit echten Bytes:

```php
function wire(iterable $chunks): string
{
    $s = '';
    foreach ($chunks as $c) {
        $s .= $c;
    }
    return $s;
}

it('emits 0; ieof terminator on preview-complete', function () {
    $request = new IcapRequest(
        method: 'RESPMOD',
        uri: 'icap://h/avscan',
        headers: ['Preview' => ['1024'], 'Allow' => ['204']],
        encapsulatedResponse: new HttpResponse(200, ['Content-Length' => ['12']], body: 'hello world!'),
        previewIsComplete: true,
    );

    $bytes = wire((new RequestFormatter())->format($request));

    expect($bytes)->toEndWith("0; ieof\r\n\r\n");
});
```

Niemals den `RequestFormatter` mocken, wenn die Wire-Schicht geprüft werden soll — der Sinn dieser Tests ist genau, dass echte Bytes rauskommen.

## Security-Tests — Fail-Secure-Invarianten

`tests/Security/FailSecureAndValidationTest.php` enthält:

- `100 Continue` außerhalb Preview → `IcapProtocolException`.
- 5xx → `IcapServerException` mit Code als HTTP-Status.
- 4xx → `IcapClientException` mit Code.
- Header mit CR/LF → `\InvalidArgumentException`.
- Service-Pfad mit Steuerzeichen → `\InvalidArgumentException`.

Jedes neue Fail-Secure-Verhalten kommt **hier** als Test rein. v2.1.1 fügt z. B. einen Test für `200 + X-Virus-Name im Preview` hinzu (`DefaultPreviewStrategyTest::testInfectedDuringPreview`).

## Integration-Tests — env-gated Self-Skip

```php
it('detects EICAR via the ClamAV ICAP service', function () {
    $host = getenv('ICAP_HOST');
    $service = getenv('ICAP_CLAMAV_SERVICE');

    if ($host === false || $host === '' || $service === false) {
        test()->markTestSkipped('Set ICAP_HOST and ICAP_CLAMAV_SERVICE to enable.');
    }

    $client = SynchronousIcapClient::create()->withConfig(new Config($host));
    $result = $client->scanFile($service, fixture('eicar.com'));

    expect($result->isInfected())->toBeTrue();
});
```

`composer test` lässt diese Suite weg (`<exclude>./tests/Integration</exclude>` in `phpunit.xml.dist`). Wer sie laufen lässt: Docker-Compose hochziehen, env-Variablen setzen, `composer test:integration`.

## PHPStan Level 9 + bleedingEdge

```neon
includes:
    - vendor/phpstan/phpstan/conf/bleedingEdge.neon

parameters:
    level: 9
    paths:
        - src
        - tests
    ignoreErrors:
        # Pest 3 Mixin-Klasse ist @internal — das ist gewollt
        - identifier: method.internalClass
          path: tests/*
          message: '#Pest\\Mixins\\Expectation#'
```

**Keine neue Baseline.** Findings werden gefixt — wenn das nicht geht, kurz erläutern und im Issue-Tracker dokumentieren, **bevor** ein `ignoreErrors`-Eintrag kommt. Heutige Repo hat exakt zwei zugelassene Pest-/PHPStan-Reibungspunkte (siehe `phpstan.neon`); neue dürfen nicht hinzukommen.

### Häufige Level-9-Hürden in dieser Codebase

```php
// ❌ Cannot access offset 0 on array<string, array<string>>|null
$virus = $response->headers['X-Virus-Name'][0];

// ✅ Mit Null-Coalesce + isset-Check
$virus = $response->headers['X-Virus-Name'][0] ?? null;
```

```php
// ❌ Parameter $previewSize expects int<1, max>, int given
$strategy->handle($previewSize);

// ✅ Schmale int-Range über PHPDoc
/** @var int<1, max> $previewSize */
```

```php
// ❌ Method has no return type
public function buildKey($config) { ... }

// ✅ Volle Signatur — Level 9 will sie sehen
public function buildKey(Config $config): string { ... }
```

## PHP-CS-Fixer — eigenes Setup

`.php-cs-fixer.dist.php` setzt `@PSR12` und einen `header_comment` mit dem EUPL-1.2-Block. Anders als der Lotse-Setup nutzen wir **nicht** `@Symfony` — die Library bleibt framework-neutral.

```bash
composer cs-fix         # apply
composer cs-check       # dry-run, im CI
```

Wer einen Test schreibt: **niemals** den Header von Hand kopieren. `composer cs-fix` setzt ihn nach dem ersten Save automatisch.

## Mutation-Testing

```bash
composer mutation
# = pest --mutate --testsuite=Unit --parallel --covered-only --min=65
```

`--covered-only` hält die Laufzeit handhabbar: nur Lines, die ohnehin Coverage haben, werden mutiert. Schwelle 65 % ist heute der untere Mindestrahmen — v2.2 plant einen Required-Status auf PR-Branches (Item #16 der v2.1-Task-Liste).

Wer einen Mutation-Drop bemerkt, fragt sich:
- Ist der existierende Test zu schwach (z. B. asserts nur `toBeTruthy()`, statt `toBe(true)` mit Wert)?
- Ist der Codepfad einfach unerreichbar — dann den toten Code löschen, nicht den Test stärken.

## Coverage-Hotspots aus Jules' v2.1-Audit

Für v2.2 sind folgende Module konkret zu pushen (Item #18):

| Modul | heute | Ziel v2.2 |
|---|---|---|
| `AmpConnectionPool` | 54 % | ≥ 90 % |
| `SynchronousStreamTransport` | 41 % | ≥ 85 % |
| Async-Socket-Error-Pfade | 63 % | ≥ 85 % |

Konkrete fehlende Cases: Cross-TLS-Pool-Isolation (kommt schon in v2.1.1), Concurrent-Acquire-Race (Fiber), `0; ieof`-Recv-Pfad, Multi-Section-Encapsulated, Cancellation mid-write/mid-read/Composite, `Options-TTL=0`, `SynchronousIcapClient::scanFileWithPreview`, Logger-Sensitive-Header-Regression.

## Composer-Skript-Quickref

| Befehl | Zweck |
|---|---|
| `composer test` | Pest Unit (default; CI-Job „Quality Checks") |
| `composer test:integration` | Pest Integration — braucht `ICAP_HOST` etc. |
| `composer test:all` | beide Suites |
| `composer stan` | PHPStan Level 9 + bleedingEdge |
| `composer cs-check` | php-cs-fixer dry-run, im CI verwendet |
| `composer cs-fix` | php-cs-fixer apply |
| `composer mutation` | Pest mutation, Schwelle 65 % |
| `composer audit` | composer audit + roave/security-advisories |
| `vendor/bin/pest tests/IcapClientTest.php` | einzelne Datei |
| `vendor/bin/pest --filter='returns infected'` | einzelner Test über Description-Substring |
| `vendor/bin/phpstan analyse src/IcapClient.php` | PHPStan auf einer Datei |
