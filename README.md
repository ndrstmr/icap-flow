<p align="center">
  <img src="docs/assets/IcapFlow-logo.svg" width="200" alt="IcapFlow Logo">
</p>

[![Latest Stable Version](https://img.shields.io/packagist/v/ndrstmr/icap-flow)](https://packagist.org/packages/ndrstmr/icap-flow)
[![Total Downloads](https://img.shields.io/packagist/dt/ndrstmr/icap-flow)](https://packagist.org/packages/ndrstmr/icap-flow)
[![GitHub Actions Workflow Status](https://img.shields.io/github/actions/workflow/status/ndrstmr/icap-flow/ci.yml?branch=main)](https://github.com/ndrstmr/icap-flow/actions)
[![Code Coverage](https://img.shields.io/badge/Code%20Coverage-View%20Report-blue.svg)](https://ndrstmr.github.io/icap-flow/)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-level%209-brightgreen)](https://phpstan.org/)
[![License](https://img.shields.io/github/license/ndrstmr/icap-flow)](https://github.com/ndrstmr/icap-flow/blob/main/LICENSE)

# icap-flow

An async-ready ICAP (Internet Content Adaptation Protocol) client for PHP 8.4+, focused on RFC 3507 correctness, fail-secure semantics and a small surface area that is comfortable to drop into a Symfony / Laravel / framework-less code base.

> [!WARNING]
> **AI-assisted origin & production-use disclaimer.** Large parts of this library — code, tests, docs and CI plumbing — were authored with substantial AI assistance, captured under three independent due-diligence reviews in [`docs/review/`](docs/review/). The v2 line closes the protocol- and security-blocking findings of those reviews, but a piece of code that scans uploads for malware sits in the security-critical path of any application that uses it. **Do not deploy this in production without a deep, independent review and an integration-test bake-out against the ICAP server you actually use.** A non-exhaustive checklist:
>
> - End-to-end test against your production ICAP vendor (c-icap, Symantec, Trend Micro, McAfee Web Gateway, Sophos, Kaspersky, …) — wire formats vary in subtle ways.
> - Fail-secure verification: confirm an unreachable / 5xx / malformed-response server makes your application **block the upload**, not silently pass it through.
> - TLS configuration review (cipher policy, hostname verification, cert pinning where appropriate).
> - Resource limits (`Config::withLimits(...)`) tuned to your traffic profile.
> - Audit logging via PSR-3 wired into your central log pipeline.
>
> This software is provided AS IS under EUPL-1.2; the licence's "no warranty" clauses apply unconditionally.

## What changed in v2

`v2.0.0` is a **breaking** release that fixes RFC-3507-blocking bugs in v1. The v1 line is **deprecated**. Highlights:

- **RFC-3507 wire format** is correct: real `Encapsulated` offsets, HTTP-in-ICAP nesting, chunked bodies for both string and stream payloads, `0; ieof` terminator on preview-complete.
- **Streaming-safe preview** — `scanFileWithPreview()` no longer buffers the file; only the preview window is read.
- **Fail-secure on status 100** — a stray 100 outside the preview flow now throws `IcapProtocolException` instead of silently mapping to a clean scan.
- **CRLF / header-injection guard** on `$service` and on user-supplied ICAP headers.
- **TLS / icaps://** support via amphp's `ClientTlsContext`.
- **DoS limits** — `maxResponseSize`, `maxHeaderCount`, `maxHeaderLineLength`.
- **Full status-code matrix** — 4xx → `IcapClientException`, 5xx → `IcapServerException`, 206 inspected, …
- **Multi-vendor virus headers** — Config takes an ordered list (`X-Virus-Name`, `X-Infection-Found`, `X-Violations-Found`, `X-Virus-ID`).
- **PSR-3 logger** optional, structured events on every request.
- **Custom request headers** (`X-Client-IP`, `X-Authenticated-User`) on `scanFile()` / `scanFileWithPreview()`.
- **External cancellation** — every public method takes an optional `Amp\Cancellation`.
- **OPTIONS-response cache** with `Options-TTL` honour.
- **`RetryingIcapClient`** decorator with exponential backoff for 5xx.
- **Encapsulated-aware response framing** — no dependency on `Connection: close`; servers may keep the socket open.
- **PHP 8.4** minimum; **PHP 8.5** in CI; integration tested end-to-end against `mnemoshare/clamav-icap` (c-icap 0.6.3 + ClamAV).

The migration guide is [`docs/migration-v1-to-v2.md`](docs/migration-v1-to-v2.md). The full per-finding closure list is in [`docs/review/consolidated_task-list.md`](docs/review/consolidated_task-list.md).

> **v2.1.0** added keep-alive connection pooling and strict RFC 3507 §4.5 preview-continue (preview + continuation on the same socket). See [`CHANGELOG.md`](CHANGELOG.md).

## Installation

```bash
composer require ndrstmr/icap-flow:^2.0
```

## Quickstart — synchronous

```php
use Ndrstmr\Icap\SynchronousIcapClient;

$icap = SynchronousIcapClient::create();

$result = $icap->scanFile('/avscan', '/path/to/upload.bin');

echo $result->isInfected()
    ? 'Virus found: ' . $result->getVirusName() . PHP_EOL
    : 'File is clean' . PHP_EOL;
```

## Quickstart — asynchronous (amphp v3 / Revolt)

```php
use Ndrstmr\Icap\IcapClient;
use Revolt\EventLoop;

$icap = IcapClient::create();

EventLoop::run(function () use ($icap) {
    $future = $icap->scanFile('/avscan', '/path/to/upload.bin');
    $result = $future->await();

    echo $result->isInfected()
        ? 'Virus: ' . $result->getVirusName() . PHP_EOL
        : 'Clean' . PHP_EOL;
});
```

## Configuration

```php
use Amp\Socket\ClientTlsContext;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;

$config = (new Config(
    host: 'icap.example.com',
    port: 11344,                 // 1344 is plain ICAP, 11344 is the de-facto TLS port
    socketTimeout: 5.0,
    streamTimeout: 30.0,
))
    ->withTlsContext(new ClientTlsContext('icap.example.com'))
    ->withVirusFoundHeaders([
        'X-Virus-Name',          // ClamAV / c-icap
        'X-Infection-Found',     // ISS Proventia
        'X-Violations-Found',    // Trend Micro
        'X-Virus-ID',            // Symantec
    ])
    ->withLimits(
        maxResponseSize: 10 * 1024 * 1024,
        maxHeaderCount: 100,
        maxHeaderLineLength: 8192,
    );

$client = new IcapClient(
    $config,
    new AsyncAmpTransport(),
    new RequestFormatter(),
    new ResponseParser(
        maxHeaderCount: $config->getMaxHeaderCount(),
        maxHeaderLineLength: $config->getMaxHeaderLineLength(),
    ),
    null,                        // PreviewStrategyInterface (DefaultPreviewStrategy if null)
    $logger,                     // Psr\Log\LoggerInterface (NullLogger if null)
);
```

## Custom request headers

```php
$result = $icap->scanFile('/avscan', '/path/to/upload.bin', [
    'X-Client-IP'          => '203.0.113.5',
    'X-Authenticated-User' => base64_encode('user@example.org'),
]);
```

Header names and values are validated against CR / LF / NUL / control characters — injection attempts raise `InvalidArgumentException` before any byte hits the socket. Library-managed headers (`Encapsulated`, `Host`, `Connection`, and inside the preview flow `Preview` / `Allow`) always take precedence over caller-supplied values.

## Exception taxonomy

Every exception this library throws implements `Ndrstmr\Icap\Exception\IcapExceptionInterface` so you can catch the whole family in one block.

| Exception | Trigger |
|---|---|
| `IcapConnectionException` | TCP-level failure (refused, timeout, TLS handshake, ...) |
| `IcapTimeoutException` | Stream cancellation timed out |
| `IcapProtocolException` | RFC-3507 violation (e.g. status 100 outside preview, malformed `Encapsulated`) |
| `IcapMalformedResponseException` (extends `IcapProtocolException`) | Server response can't be parsed (no separator, header line without `:`, oversize lines) |
| `IcapClientException` | ICAP 4xx response — request rejected by server, code is the real status |
| `IcapServerException` | ICAP 5xx response — server failed, code is the real status |
| `IcapResponseException` | Status code that doesn't fit any other bucket |

## Examples

The `examples/` directory has runnable demos, including a full Symfony-ready async cookbook:

- `examples/01-sync-scan.php` — minimal synchronous scan
- `examples/02-async-scan.php` — async scan inside `Revolt\EventLoop`
- `examples/cookbook/01-custom-headers.php` — `X-Client-IP`, `X-Authenticated-User`
- `examples/cookbook/02-custom-preview-strategy.php` — vendor-specific preview interpretation
- `examples/cookbook/03-options-request.php` — capability discovery via OPTIONS

## Integration tests

A docker-compose stack (`docker-compose.yml`) brings up [`mnemoshare/clamav-icap`](https://hub.docker.com/r/mnemoshare/clamav-icap) on port 1344. The tests in `tests/Integration/` skip when `ICAP_HOST` is unset, so contributors without Docker get a green `composer test:integration` while CI exercises a real wire-level round trip on every PR.

```bash
docker compose up -d
ICAP_HOST=127.0.0.1 ICAP_PORT=1344 \
  ICAP_ECHO_SERVICE=/avscan \
  ICAP_CLAMAV_SERVICE=/avscan \
    composer test:integration
```

## Provenance & due diligence

`docs/review/` carries the three independent due-diligence reports (Claude, Codex, Jules) that drove the v2 redesign, and a verified consolidated task list. They are part of the repo, not after-the-fact marketing — every closed finding maps back to a specific file/line in those docs.

## Developers

```bash
composer test           # unit suite (Pest)
composer test:integration   # against a configured ICAP server
composer stan           # PHPStan level 9 + bleedingEdge
composer cs-check       # PSR-12 (php-cs-fixer)
composer cs-fix         # apply fixes
composer audit          # composer + roave/security-advisories
```

CI matrix: PHP 8.4 + 8.5. See [`CONTRIBUTING.md`](CONTRIBUTING.md) for the PR workflow.

## Licence

EUPL-1.2 — see [`LICENSE`](LICENSE). The licence is OpenCoDE-compatible and explicitly designed for European public-sector software.

## Security

To report a vulnerability, see [`SECURITY.md`](SECURITY.md). Please **do not** open a public GitHub issue for security findings.

## Changelog

[`CHANGELOG.md`](CHANGELOG.md) — Keep a Changelog format, SemVer-committed.
