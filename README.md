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
> **AI-assisted origin & production-use disclaimer.** Large parts of this library — code, tests, docs and CI plumbing — were authored with substantial AI assistance and reviewed in independent audits ([`docs/review/`](docs/review/)). This client sends uploads to an ICAP service and interprets its response; it sits in the security-critical path of any application that uses it. **Do not deploy this in production without a deep, independent review and an integration-test bake-out against the ICAP server you actually use.** A non-exhaustive checklist:
>
> - End-to-end test against the ICAP service you use. Only c-icap `virus_scan` with ClamAV is verified ([verified mode](docs/cicap-clamav.md)); any other service is read by the default rules, which have not been verified against a product.
> - Fail-secure verification: confirm an unreachable / 5xx / malformed-response server makes your application **block the upload**, not silently pass it through.
> - TLS configuration review (cipher policy, hostname verification, cert pinning where appropriate).
> - Resource limits (`Config::withLimits(...)`) tuned to your traffic profile.
> - Audit logging via PSR-3 wired into your central log pipeline.
>
> This software is provided AS IS under EUPL-1.2; the licence's "no warranty" clauses apply unconditionally.

## Versions

`v3.0.0`, the current release, is a small breaking release of the v2 API: `executeRaw()` is `protected` (nothing outside the `final` `IcapClient` reaches it), `options()` returns `Future<IcapResponse>`, `IcapResponseException` is gone ([migration](docs/migration-v2-to-v3.md)). `v2.0.0` made the wire format RFC 3507 compliant; v1 is deprecated ([migration](docs/migration-v1-to-v2.md)).

This README, its examples included, describes `main`, the next major release 4.0.0, which is not released yet. See the [v3-to-v4 migration guide](docs/migration-v3-to-v4.md) and the *Unreleased* section of the [`CHANGELOG.md`](CHANGELOG.md).

## Installation

**Release 3.0.0**, documented by its [README](https://github.com/ndrstmr/icap-flow/blob/v3.0.0/README.md). It has no `CicapVerdict` and none of the later changes; the quickstarts below do not run on it.

```bash
composer require ndrstmr/icap-flow:^3.0
```

**Development: `main`, the unreleased 4.0.0**, which this README and its quickstarts describe. `dev-main` follows the branch and is not a release; `composer.lock` records the commit you got.

```bash
composer require ndrstmr/icap-flow:dev-main
```

## Quickstart — synchronous

Both quickstarts scan with the c-icap + ClamAV service of `docker-compose.yml` on `127.0.0.1:1344`, in the verified mode ([`docs/cicap-clamav.md`](docs/cicap-clamav.md)).

```php
use Ndrstmr\Icap\CicapVerdict;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\SynchronousIcapClient;

$icap = new SynchronousIcapClient(IcapClient::forServer('127.0.0.1', 1344, new CicapVerdict('/avscan')));

$result = $icap->scanFile('/avscan', '/path/to/upload.bin');

echo $result->isInfected()
    ? 'Virus found: ' . $result->getVirusName() . PHP_EOL
    : 'File is clean' . PHP_EOL;
```

## Quickstart — asynchronous (amphp v3 / Revolt)

```php
use Ndrstmr\Icap\CicapVerdict;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;

$icap = new IcapClient(
    new Config('127.0.0.1'),
    new AsyncAmpTransport(),
    new RequestFormatter(),
    new ResponseParser(),
    verdict: new CicapVerdict('/avscan'),
);

$future = $icap->scanFile('/avscan', '/path/to/upload.bin'); // returns at once
$result = $future->await();                                  // the event loop runs while it waits

echo $result->isInfected()
    ? 'Virus: ' . $result->getVirusName() . PHP_EOL
    : 'Clean' . PHP_EOL;
```

## Scanning files

`scanFile()` and `scanFileWithPreview()` read the file through amphp/file in worker processes (`proc_open` is needed), so the event loop keeps running. Keep the file and path stable during the scan and release only the same unchanged object. See [`docs/file-sources.md`](docs/file-sources.md), also for `scanFileDiscardingBody()`: a server that echoes a large file back does not make the client hold the response.

Without a verdict the client applies the default rules: a `204`, and a `200` or `206` without a header of `Config::getVirusFoundHeaders()` (default `X-Virus-Name`), are clean. They are verified against no product, and with c-icap they are wrong: they report an infected file as clean, and with c-icap's infection headers in the list they still take the `204` or the unchanged message it sends for a file it did not scan for a clean one. Use the verified mode there.

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
    ->withTlsContext(new ClientTlsContext('icap.example.com'))   // TLS needs the async transport
    // Default rules: the headers your server names an infection in, first match wins (default: X-Virus-Name).
    ->withVirusFoundHeaders(['X-Virus-Name', 'X-Virus-ID'])
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

### Timeouts

Every limit is finite; `0` is never "without limit". One operation (a public method call, with the attempts and pauses of `RetryingIcapClient`) has one end, and each phase has a limit of its own inside it:

| Setting | Limit on | Default |
|---|---|---|
| `operationTimeout` | the whole operation: cache, pool wait, connect, TLS, upload, preview, continuation, answers (and in `RetryingIcapClient` all attempts and pauses) | 60 s |
| `socketTimeout` | the TCP connection | 10 s |
| `tlsHandshakeTimeout` | the TLS handshake | `streamTimeout` |
| `streamTimeout` | an answer from its first byte on (a maximum, not an idle timer); a write: one window for the whole call, for the preview and for the rest of the file each, but a request of the synchronous transport outside a preview (`scanFile()`, `request()`, `options()`) has the time the peer may go without taking a byte, which every byte it takes starts again (`operationTimeout` bounds the total) | 10 s |
| `responseStartTimeout` | the server's time to begin its answer once the request is sent (the scan) | `streamTimeout` |

```php
// Large files, slow scanner: the rest of a previewed file has to go out within streamTimeout as well.
$config = (new Config(host: 'icap.example.com', port: 1344, streamTimeout: 120.0))
    ->withTimeouts(operationTimeout: 300.0, responseStartTimeout: 120.0);
```

The end of the operation is an `IcapTimeoutException`; your own cancellation stays an `Amp\CancelledException`. An expired call closes its socket and its file handle; a file call that hangs in the operating system keeps its worker, and the handle is closed when that call returns ([`docs/file-sources.md`](docs/file-sources.md#deadline-and-cancellation)). A request-body producer or a cache that holds the event loop and does not return cannot be interrupted, only noticed afterwards; the synchronous transport bounds its native waits only.

### Connection pool

For long-running workers (RoadRunner, Swoole, ReactPHP) the async transport can reuse sockets via a connection pool. A socket goes back to the pool only after a validated exchange, which today is the strict preview flow of `scanFileWithPreview()`; every other request closes its socket, with a pool as well.

The pool also limits connections: the ones being set up, in use and idle all count against `maxConnectionsPerHost` (per host and port, whatever the TLS context) and `maxConnections` (the whole pool, default the same number). A call that finds the pool full fails with `IcapConnectionException`, unless you configure a queue (`maxWaiters` and `waitTimeout`); only an idle socket gives way for a new connection, never one in use. Without a pool the application limits its own concurrency. Use one pool per security domain; a new TLS context (or a new pool, with the old one closed) is how a certificate rotation takes effect.

```php
use Ndrstmr\Icap\Cache\InMemoryOptionsCache;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;

$pool = new AmpConnectionPool(
    maxConnectionsPerHost: 8,    // connections to one host:port, in use and idle together
    maxIdleSeconds: 30.0,        // close sockets idle longer than 30 s
    // maxConnections: 16,       // the whole pool (default: maxConnectionsPerHost)
    // maxWaiters: 32, waitTimeout: 5.0, // let up to 32 calls wait, 5 s each, instead of failing
);

$transport = new AsyncAmpTransport($pool);

// Optional: an OPTIONS cache. scanFileWithPreview() without a size then takes
// the Preview of the answer options() stored (0 to 1 MiB; larger values are
// clamped, an entry that is no usable OPTIONS answer is ignored), else 1024.
$cache = new InMemoryOptionsCache();
// For a shared cache backend use Psr16OptionsCache or Psr6OptionsCache.

$client = new IcapClient($config, $transport, new RequestFormatter(), new ResponseParser(), optionsCache: $cache);
```

### OPTIONS cache

An entry is one OPTIONS answer (status 200, at most 64 KiB of fields and body in at most 100 field values) of one endpoint, service and security profile (plain, or the TLS settings of the client), valid for the `Options-TTL` of the server, never longer than an hour, counted from when the answer came in. An answer without a usable `Options-TTL` is not kept. Nothing invalidates an entry earlier, the ISTag included: a change of the service shows when the entry ends. What a cache returns is checked before it is used, also with a cache of your own. The client checks its response limits (`maxResponseSize`, `maxHeaderCount`, `maxHeaderLineLength`) against a normalized representation, which may differ from the server's original wire encoding; an entry that fails is a miss. A custom cache is responsible for the key and expiry. `InMemoryOptionsCache` holds 64 entries by default (`maxEntries`). The PSR adapters store one self-contained item per entry; the number of items in a shared backend is that backend's to bound. The key knows endpoint, service and TLS settings, not a tenant: give each security domain its own cache, or its own `prefix`.

## Custom request headers

```php
$result = $icap->scanFile('/avscan', '/path/to/upload.bin', [
    'X-Client-IP'          => '203.0.113.5',
    'X-Authenticated-User' => base64_encode('user@example.org'),
]);
```

Header names and values are validated against CR / LF / NUL / control characters — injection attempts raise `InvalidArgumentException` before any byte hits the socket. The client sets `Host` and `Encapsulated` itself, and in `scanFileWithPreview()` also `Preview` / `Allow`; caller values for these are dropped in any spelling. `scanFile()` sends no preview, so it drops a caller `Preview` too, while `Allow` (e.g. `Allow: 204`) and all other headers pass through. In the verified mode the scan helpers set `Allow: 204` themselves.

## Exception taxonomy

The failures of an exchange implement `Ndrstmr\Icap\Exception\IcapExceptionInterface`, so you can catch them in one block. Arguments that cannot work (a header, a service, a preview size, a `Config` value) are an `\InvalidArgumentException` before anything is sent, a discarding scan with a transport or parser that cannot discard, or a preview with a transport that has no sessions, is a `\LogicException`, and your own cancellation stays an `Amp\CancelledException`.

| Exception | Trigger |
|---|---|
| `IcapConnectionException` | TCP-level failure (refused, connect timeout, failed TLS handshake, a socket that breaks) |
| `IcapTimeoutException` | A limit of the client passed: the `operationTimeout` of the whole operation, or the limit of a phase (acquiring the connection, the TLS handshake, writing the request, the answer beginning or arriving; the synchronous transport reports a read that waited that long, or a write that made no progress for that long). A cancellation by the caller is not mapped; it stays `Amp\CancelledException` |
| `IcapProtocolException` | RFC-3507 violation or unexpected status (e.g. status 100 outside preview); in the verified mode also an answer that does not show a scan |
| `IcapMalformedResponseException` (extends `IcapProtocolException`) | Server response can't be parsed (no separator, header line without `:`, oversize lines) |
| `IcapClientException` | ICAP 4xx response — request rejected by server, code is the real status |
| `IcapServerException` | ICAP 5xx response — server failed, code is the real status |
| `IcapStorageException` (extends `RuntimeException`) | The file or stream to scan is unreadable or changed size, or the OPTIONS cache backend fails |

For a partial preview, the client rejects ICAP 4xx and 5xx responses as `IcapClientException` and `IcapServerException`, and unexpected statuses as `IcapProtocolException`, before calling a custom strategy. A strategy must continue on `100` and stop on final `200`, `204` or `206`; a conflicting decision is a protocol error. The strategy remains responsible for interpreting allowed final responses for the actual service: a `204` alone does not prove that a file was scanned.

## Examples

The `examples/` directory has runnable demos and a cookbook:

- `examples/01-sync-scan.php` — minimal synchronous scan (verified mode)
- `examples/02-async-scan.php` — async scan inside `Revolt\EventLoop` (verified mode)
- `examples/cookbook/01-custom-headers.php` — `X-Client-IP`, `X-Authenticated-User`
- `examples/cookbook/02-custom-preview-strategy.php` — a preview strategy of your own (default rules)
- `examples/cookbook/03-options-request.php` — capability discovery via OPTIONS
- `examples/cookbook/04-tls-mtls.php` — TLS and mutual TLS (mTLS) setup
- `examples/cookbook/05-retry-decorator.php` — exponential-backoff retry of OPTIONS on 5xx
- `examples/cookbook/06-pool-tuning.php` — connection-pool idle eviction and Max-Connections
- `examples/cookbook/07-cancellation-from-upload.php` — timeout and user-initiated cancellation

## Integration tests

`docker compose up -d` brings up the reference service ([`mnemoshare/clamav-icap`](https://hub.docker.com/r/mnemoshare/clamav-icap), pinned by digest) on port 1344. The tests in `tests/Integration/` skip when `ICAP_HOST` is unset, so contributors without Docker get a green `composer test:integration`; a skipped test verifies nothing. CI runs them on PHP 8.4 and 8.5 for pull requests and pushes to `main` and `release/**`, nightly and on demand; there a skipped test fails the run. Variables and the service: [`tests/Integration/README.md`](tests/Integration/README.md).

## Provenance & due diligence

`docs/review/` keeps the audits and task lists of earlier versions (v1, v2.1, v3.0). They describe the version they reviewed, not the current code; what has been fixed since is in the [`CHANGELOG.md`](CHANGELOG.md).

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

To report a vulnerability, see [`SECURITY.md`](SECURITY.md). Please **do not** open a public GitHub issue for security findings. The same file says what the client guarantees and what stays with the ICAP service and your application.

## Changelog

[`CHANGELOG.md`](CHANGELOG.md) — Keep a Changelog format, SemVer-committed.
