# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **BREAKING (v3.0.0):** `IcapClient::executeRaw()` is now `protected`. It was
  never part of `IcapClientInterface` and exists solely to support the internal
  preview flow, where `100 Continue` is a legitimate intermediate response.
  Exposing it as part of the public surface let callers bypass the fail-secure
  status-code interpretation in `interpretResponse()`. External callers must use
  `request()`, `scanFile()`, `scanFileWithPreview()` or `options()` instead;
  subclasses that need raw access can still invoke or override the method.
  *(v3-V — Quelle: Claude, Codex)*
- **BREAKING (v3.0.0):** `IcapClient::options()` and
  `SynchronousIcapClient::options()` now return `Future<IcapResponse>` /
  `IcapResponse` instead of `Future<ScanResult>` / `ScanResult`. OPTIONS is a
  capability-discovery method (RFC 3507 §4.10), so a virus-verdict wrapper was
  always semantically wrong — callers want the raw headers (`Preview`,
  `Options-TTL`, `Methods`, `Allow`, `Service`, `ISTag`, `Max-Connections`).
  Fail-secure semantics are preserved: 4xx still throws `IcapClientException`,
  5xx throws `IcapServerException`, `100 Continue` throws
  `IcapProtocolException`. The pre-existing `assertSuccessfulStatus()` helper
  (extracted from `interpretResponse()`) is the single source of truth for the
  failure branches. Migration: replace `$result->isInfected()` /
  `$result->getOriginalResponse()->headers` with `$result->headers` directly.
  *(v3-W — Quelle: Claude, Codex)*

## [2.2.0] - 2026-04-30

### Added
- **PSR-6 and PSR-16 OPTIONS-cache adapters** (v2.2-X): `Psr6OptionsCache` and
  `Psr16OptionsCache` delegate to any PSR-6 `CacheItemPoolInterface` or PSR-16
  `CacheInterface` implementation (Redis, APCu, Memcached, …). Both support key
  prefixing, ISTag-based flush-on-change, and TTL pass-through. The PSR interfaces
  are listed as `suggest` in composer.json — no hard dependency added.
- **OPTIONS-cache ISTag invalidation** (v2.2-T): `OptionsCacheInterface::set()`
  accepts an optional `?string $istag` parameter. `InMemoryOptionsCache` tracks
  the last known ISTag and flushes all cached entries when it changes (RFC 3507
  §4.10.2 — ISTag reflects server config/signature updates). `IcapClient::options()`
  now extracts the `ISTag` header from the response and passes it to the cache.
- **Injectable clock for InMemoryOptionsCache** (v2.2-U): the constructor accepts
  an optional `(Closure(): int)|null $clock` parameter, replacing the internal
  `advanceClockForTesting()` test seam with a clean dependency-injection approach.
  Tests use a controlled clock closure; production code defaults to `time()`.
- **Pool idle-eviction** (v2.2-Q): `AmpConnectionPool` now records when each
  socket became idle and evicts entries older than `maxIdleSeconds` (default
  30 s) on the next `acquire()`. Prevents stale socket accumulation in
  long-running PHP workers (Swoole, RoadRunner, ReactPHP). The constructor
  accepts optional `maxIdleSeconds` and `clock` parameters for tuning and
  deterministic testing.
- **OPTIONS-driven preview size auto-detection** (v2.2-K): `scanFileWithPreview()`
  now accepts `?int $previewSize = null`. When null, the client queries the
  OPTIONS cache for the server's advertised `Preview` header (RFC 3507 §4.10.2)
  and uses that value. Falls back to 1024 when no cache entry exists or the
  response carries no `Preview` header. Explicit values still take precedence.
  (Issue #58)
- **OPTIONS Max-Connections pool tuning** (v2.2-L): `AmpConnectionPool` accepts
  an optional `serverMaxConnections` constructor parameter. When set, the
  effective idle cap becomes `min(localCap, serverMaxConnections)` per
  RFC 3507 §4.10.2. (Issue #59)
- `NullConnectionPool` (v2.2-E2): implements `ConnectionPoolInterface` with
  no keep-alive — every `acquire()` opens a fresh connection, every `release()`
  closes it. Useful for testing and explicit no-pool configurations.
  (Issue #57)

### Deprecated
- **`IcapResponseException`** (v2.2-F): constructor now carries a PHP 8.4
  `#[\Deprecated]` attribute with `since: '2.0'`. Instantiation triggers
  `E_USER_DEPRECATED`. Use `IcapProtocolException`, `IcapClientException`
  (4xx), or `IcapServerException` (5xx) instead. Will be removed in v3.0.0.

### Fixed
- **Per-IO timeout for session-aware transports** (v2.2-P):
  `AmpTransportSession` now creates a fresh `TimeoutCancellation` for each
  `readResponse()` call instead of sharing a single session-lifetime timer.
  For multi-round-trip flows (strict RFC 3507 §4.5 preview-continue), the
  old timer accumulated across all IO phases — a server that legitimately
  takes close to `streamTimeout` for a scan after `100 Continue` would
  trigger a spurious `CancelledException`. Each IO phase now gets the full
  `streamTimeout` window independently.

### Changed
- **Strict header-name validation** (v2.2-S): `IcapClient::validateIcapHeaders()`
  now rejects any character outside the RFC 7230 §3.2.6 `tchar` set.
  The previous blacklist regex only blocked control characters and the
  colon; separator tokens like parentheses, brackets, slash, at-sign,
  etc. slipped through. The new whitelist regex accepts only
  `ALPHA / DIGIT / "!" / "#" / "$" / "%" / "&" / "'" / "*" / "+" /
  "-" / "." / "^" / "_" / "`" / "|" / "~"`. (Issue #62)
- **obs-fold support in ResponseFrameReader** (v2.2-R):
  `findEncapsulatedHeader()` now unfolds RFC 7230 §3.2.4 obsolete line
  folding (continuation lines starting with SP or HTAB) before parsing
  the `Encapsulated` header value. Servers that fold long header values
  across multiple lines (e.g. c-icap) previously caused the framer to
  miss the body offset, truncating the response at the head separator.
  (Issue #63)
- **Integration CI hardened** (v2.2-N): removed `continue-on-error: true` from
  the integration job. Integration tests now run on push-to-main and a nightly
  schedule (03:15 UTC) instead of on every PR, avoiding flaky ClamAV image
  bootstrapping on shared runners. Failures are now hard failures. (Issue #61)

## [2.1.2] - 2026-04-28

### Fixed
- **Streaming preview continuation (OOM fix)**: `IcapClient::scanFileWithPreviewStrict()`
  now uses `ChunkedBodyEncoder::encodeRemainderFromStream()` instead of
  `stream_get_contents()` to send the post-preview body. The remainder is
  streamed in 8 KiB chunks from the current file position, eliminating the
  risk of out-of-memory errors on large files. Finding F, Claude + Codex ×2.
  (Issue #56)
- **3 risky unit tests**: added explicit assertions to Mockery-only tests
  (`LoggerIntegrationTest`, `OptionsCacheTest`, `RetryingIcapClientTest`) so
  they pass under `failOnRisky=true`.
- **SynchronousStreamTransportTest warning**: suppressed the expected
  `E_WARNING` from `stream_socket_client()` so the test passes under
  `failOnWarning=true`.

### Added
- `ChunkedBodyEncoder::encodeRemainderFromStream(resource $stream): iterable<string>`
  — reads from the current stream position in `CHUNK_SIZE` blocks without
  rewinding, emitting proper HTTP/1.1 chunked-transfer frames.
- `tests/Wire/ChunkedBodyEncoderTest.php` — 4 unit tests covering position
  preservation, chunked framing, empty remainder, and multi-chunk encoding.
- `tests/PreviewContinueStrictTest.php` — new end-to-end test verifying
  128 KiB streaming continuation with chunk-level payload verification.

### Changed
- **`phpunit.xml.dist`**: `failOnRisky` and `failOnWarning` set to `true`.
- **`composer.json`**: PHPStan script now passes `--memory-limit=1G` for
  CI stability on constrained runners.

## [2.1.1] - 2026-04-28

### Security
- **TLS pool-key isolation** (`AmpConnectionPool::key()`): the pool key now
  includes `spl_object_hash()` of the `ClientTlsContext` so that two configs
  pointing at the same `host:port` but carrying different TLS identities
  (certs, peer name, CA bundle) never share idle sockets. Pre-2.1.1 builds
  are vulnerable to cross-tenant socket reuse in multi-tenant deployments.
  Finding A, 4/4 reviewers. (Issue #53)

### Fixed
- **`DefaultPreviewStrategy` 200/206 verdict**: RFC 3507 §4.3.3 / §6 allows
  servers to respond `200 OK` or `206 Partial Content` with a virus-name
  header during a preview exchange if malware is detected in the first chunk.
  The strategy now maps these responses to `ABORT_INFECTED` (when a
  configured virus header is present) or `ABORT_CLEAN` (otherwise), making
  the `ABORT_INFECTED` code path in `IcapClient` reachable for the first time.
  The constructor accepts an optional `list<string> $virusFoundHeaders`
  parameter (default `['X-Virus-Name']`); `IcapClient` forwards
  `Config::getVirusFoundHeaders()` automatically. Finding B, Claude + Codex ×2.
  (Issue #54)

### Changed
- **`SECURITY.md`** "What this library does NOT guarantee" section updated to
  reflect the features that have been present since v2.0/v2.1 (`RetryingIcapClient`,
  `InMemoryOptionsCache`, `AmpConnectionPool`). Finding C, Claude + Jules.
  (Issue #55)
- **`ConnectionPoolInterface`** phpdoc: removed broken `{@see NullConnectionPool}`
  reference; replaced with a forward note for the v2.2 implementation.
  Finding E, Codex. (Issue #57)
- **Cookbook `02-custom-preview-strategy.php`**: corrected the McAfee example
  strategy to inspect `X-Virus-ID` on 200/206 responses instead of mapping
  200 unconditionally to `ABORT_CLEAN` (security anti-pattern). Added caveat
  comment. Finding H, Claude.
- **Cookbook `03-options-request.php`**: removed stale "next milestone after
  v2.0.0" claim; `InMemoryOptionsCache` has been available since v2.0.
  Finding G, Claude. (Issue #57)

## [2.1.0] - 2026-04-25

### Added — keep-alive transport
- **Connection pooling** in `AsyncAmpTransport`. New
  `Ndrstmr\Icap\Transport\ConnectionPoolInterface` with default
  `AmpConnectionPool` (LIFO stack per `host:port[:tls]` key,
  configurable cap, drops closed sockets on acquire and on release).
  Transport accepts an optional pool argument and reuses sockets
  across requests; without a pool the v2.0 single-shot behaviour is
  preserved unchanged. Closed (rather than released) on framing
  errors or `Connection: close` from the server.
- **`SessionAwareTransport`** capability surface plus
  `TransportSession` and the `AmpTransportSession` implementation —
  open one socket, run several write/read round-trips before
  release/close. Used by the new strict preview-continue path.
- **Strict RFC 3507 §4.5 preview-continue** in
  `IcapClient::scanFileWithPreview()`. When the transport supports
  sessions (the default async transport does), preview and
  continuation travel on the **same** socket as one logical ICAP
  request: the client sends only the chunked body remainder after
  the `100 Continue`, no second `RESPMOD` head. The synchronous
  transport keeps the v2.0 two-request approximation.
- `ChunkedBodyEncoder` extracted from `RequestFormatter::chunkBody`;
  reused by both the formatter and the §4.5 continuation path.

### Tests
- `tests/Transport/AmpConnectionPoolTest.php` — six pool-internals
  cases driven by `Amp\Socket\createSocketPair`.
- `tests/PreviewContinueStrictTest.php` — fake-server pair asserts
  that the connector is invoked **exactly once** for preview +
  continuation, that phase 1 is a real `RESPMOD` and phase 2 is
  body-only.
- Final unit-suite count at v2.1.0: **91 passed, 187 assertions**.

## [2.0.0] - 2026-04-25

### Why a major release
v1.0.0 had several RFC-3507-blocking bugs that no real ICAP server (c-icap, Symantec, Sophos, Kaspersky, Trend Micro) would have accepted, plus a fail-open security bug in the response interpreter. Closing those required wire-format changes that are by definition breaking.

### Breaking changes
- **Minimum PHP version is 8.4.** The CI matrix tests 8.4 and 8.5.
- `IcapRequest`: removed `mixed $body`; encapsulated payload is now modelled by two typed slots (`?HttpRequest $encapsulatedRequest`, `?HttpResponse $encapsulatedResponse`) plus a `bool $previewIsComplete` flag.
- `RequestFormatterInterface::format()` now returns `iterable<string>` instead of one big `string`. Transports stream chunks onto the socket, so multi-GB encapsulated bodies are never buffered.
- `TransportInterface::request()` now accepts `iterable<string>` for the raw request.
- `IcapClient::scanFile()` and `IcapClient::scanFileWithPreview()` gained an optional `array $extraHeaders = []` parameter.
- `Config` constructor signature changed: TLS context, DoS limits and the multi-vendor virus-header list moved into named-argument territory; the legacy single-string `$virusFoundHeader` param is still accepted but the canonical accessor is `getVirusFoundHeaders(): list<string>`.
- `interpretResponse()` is no longer fail-open on status 100 — a stray 100 outside the preview flow now raises `IcapProtocolException`. 4xx responses raise `IcapClientException`, 5xx raise `IcapServerException`.
- New runtime dependency: `psr/log ^3.0`.
- New explicit dependency: `revolt/event-loop ^1.0` (was a hidden transitive in v1).
- `tests/RequestFormatterTest.php` deleted (it asserted the broken wire format and blocked any real fix). Replaced by `tests/Wire/RequestFormatterWireTest.php` with hand-computed RFC-3507 byte fixtures.

### Added — RFC 3507 correctness
- Real `Encapsulated` offsets, computed from the rendered HTTP header block length. No more hardcoded `null-body=0` when a body is present.
- HTTP-in-ICAP nesting: the encapsulated block is a real HTTP/1.1 request or response, not naked file bytes.
- String- **and** stream-resource bodies are always chunk-encoded.
- `0; ieof\r\n\r\n` terminator when the preview window is the complete payload (RFC 3507 §4.5).
- `Allow: 204` is sent on every preview request.
- `ResponseParser` honours the server's `Encapsulated` header to locate the HTTP body inside the encapsulated block; null-body responses now return `body=''` as RFC 3507 §4.4.1 mandates.
- `ResponseParser` accepts both `ICAP/1.0` and `ICAP/1.1` status lines.
- `ResponseParser` honours RFC 7230 §3.2.4 obsolete header line folding (whitespace-prefixed continuation lines), required to parse c-icap's multi-line `X-Violations-Found` header (§6.4).

### Added — security hardening
- **Fail-secure on 100 Continue** outside a preview exchange (`IcapProtocolException`).
- **CRLF / NUL / control-character validation** on `$service` and on every user-supplied ICAP header name and value, before any byte is written to the socket.
- **TLS / `icaps://`** via `Config::withTlsContext(ClientTlsContext)` — `AsyncAmpTransport` switches to `Socket\connectTls()`. `SynchronousStreamTransport` explicitly rejects TLS at runtime.
- **DoS limits**: `maxResponseSize` (default 10 MB), `maxHeaderCount` (100), `maxHeaderLineLength` (8 KB) — enforced in both transports' read loops and in the parser.
- **`SynchronousStreamTransport` hardening**: connect timeout from `Config::getSocketTimeout()` (was hardcoded 5 s), `stream_set_timeout()` per connection, try/finally `fclose()`, bounded `fread()` loop replacing the unbounded `stream_get_contents()`.
- **Full status-code matrix**: 4xx → `IcapClientException(code)`, 5xx → `IcapServerException(code)`, 206 inspected for virus header, 200 unchanged, 204 clean.

### Added — ecosystem fit
- **PSR-3 logger** optional injection on `IcapClient`. Three structured events per request (`info` started / `info` completed / `warning` failed) with method, URI, host, port, status code, infected flag.
- **Multi-vendor virus headers**: `Config::withVirusFoundHeaders(list<string>)` and `getVirusFoundHeaders(): list<string>`. The client returns the first header that the server actually sent.
- **Custom request headers** on `scanFile()` / `scanFileWithPreview()` — `X-Client-IP`, `X-Authenticated-User`, etc. — with CRLF guard and library-managed-headers-win semantics.
- **External cancellation**: every public method (`request`, `options`, `scanFile`, `scanFileWithPreview`) takes an optional `?Amp\Cancellation` parameter. `AsyncAmpTransport` combines it with the internal `TimeoutCancellation` via `CompositeCancellation`. `SynchronousStreamTransport` honours it opportunistically between read/write iterations.
- **OPTIONS-response cache** (RFC 3507 §4.10.2 `Options-TTL`): new `Ndrstmr\Icap\Cache\OptionsCacheInterface` with default `InMemoryOptionsCache`. Optional last constructor argument on `IcapClient`. On a cache hit, no transport call, no parser call — the parsed `IcapResponse` is returned synchronously and still runs through `interpretResponse()` for the fail-secure status pass.
- **`RetryingIcapClient` decorator**: configurable exponential backoff (default 3 attempts, 0.1 s base, 2× factor, 5 s cap) that retries **only** on `IcapServerException` (5xx). 4xx, parse errors, connection errors, cancellation propagate after the first attempt.

### Added — transport / wire framing
- **Encapsulated-aware response framing** via the new `Ndrstmr\Icap\Transport\ResponseFrameReader`. The transport now knows when an ICAP response ends from the bytes themselves (head separator + Encapsulated-derived body offset + chunked terminator) instead of relying on the server closing the socket. Removes the v2-pre-release `Connection: close` request-side hack and unblocks keep-alive pooling for v2.1.
- DoS limits enforced inside the framing reader as well as in the parser, so a hostile server can't push us off a cliff before the head is even complete.

### Added — exception taxonomy
- New marker interface `Ndrstmr\Icap\Exception\IcapExceptionInterface`. Every concrete exception implements it.
- `IcapProtocolException`, `IcapTimeoutException`, `IcapClientException`, `IcapServerException`, `IcapMalformedResponseException` (extends `IcapProtocolException`).
- `IcapClient` is now `final` and implements the new `IcapClientInterface` contract. `RequestFormatter`, `ResponseParser`, `DefaultPreviewStrategy`, `SynchronousStreamTransport` are also `final`.
- `#[\Override]` attributes on every interface implementation.

### Added — test infrastructure
- `tests/Wire/RequestFormatterWireTest.php` and `tests/Wire/ResponseParserWireTest.php` with hand-computed RFC-3507 byte fixtures.
- `tests/Security/FailSecureAndValidationTest.php` and `tests/Security/ParserDosLimitsTest.php` covering every branch of the new status matrix and DoS limits.
- `tests/CancellationTest.php`, `tests/OptionsCacheTest.php`, `tests/RetryingIcapClientTest.php`, `tests/Transport/ResponseFrameReaderTest.php` covering the M3 follow-up surface.
- `tests/Integration/IcapServerSmokeTest.php` against a real ICAP server (skips gracefully when `ICAP_HOST` is unset). Verified end-to-end against `mnemoshare/clamav-icap` — OPTIONS, EICAR detection (via `X-Violations-Found`), clean-file verdict.
- `docker-compose.yml` for local integration runs against `mnemoshare/clamav-icap:0.14.5`.
- CI matrix expanded to PHP 8.4 + 8.5; new integration job; `roave/security-advisories` in dev-deps; PHPStan upgraded to 2.x; `beStrictAboutCoverageMetadata` and a separate Integration testsuite in `phpunit.xml.dist`.
- Final unit-suite count: **84 passed, 167 assertions** at v2.0.0 release.

### Removed
- v1 `mixed $body` on `IcapRequest`.
- v1 wire format with hardcoded `Encapsulated: null-body=0`.
- v1 `tests/RequestFormatterTest.php` (bug-cementing test).
- The fail-open mapping of ICAP 100 to `ScanResult(isInfected=false)`.

## [1.0.0] - 2025-06-18 (deprecated)

> **Deprecated.** v1.0.0 ships with several RFC-3507-blocking bugs and a fail-open response mapper. Use v2.0.0+. The full list of v1 findings is in [`docs/review/consolidated_task-list.md`](docs/review/consolidated_task-list.md).

### Added
- Initial release of the IcapFlow library.
- User-friendly `ScanResult` DTO for easy interpretation of scan results.
- Dual API with a fully asynchronous core (`IcapClient`) and a simple `SynchronousIcapClient` wrapper.
- Extensible Preview-Handling via the `PreviewStrategyInterface`.
- Factory methods (`::create()`) for easy, dependency-free instantiation.
- Support for ICAP `REQMOD`, `RESPMOD`, and `OPTIONS` methods.
- Streaming for large file processing via stream resources (note: in v1 the preview path still buffered the whole file — fixed in v2).
- Comprehensive test suite (>80 % coverage), static analysis (PHPStan Level 9), and a full CI/CD pipeline.
