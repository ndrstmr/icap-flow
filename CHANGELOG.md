# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- This release line will track post-v2.0.0 follow-up work, in particular: `Cancellation` in the public client API, OPTIONS-response cache, 503 retry with exponential backoff, single-connection preview-continue (RFC 3507 §4.5), keep-alive connection pooling.

## [2.0.0] - 2026-04-24

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

### Added — exception taxonomy
- New marker interface `Ndrstmr\Icap\Exception\IcapExceptionInterface`. Every concrete exception implements it.
- `IcapProtocolException`, `IcapTimeoutException`, `IcapClientException`, `IcapServerException`, `IcapMalformedResponseException` (extends `IcapProtocolException`).
- `IcapClient` is now `final` and implements the new `IcapClientInterface` contract. `RequestFormatter`, `ResponseParser`, `DefaultPreviewStrategy`, `SynchronousStreamTransport` are also `final`.
- `#[\Override]` attributes on every interface implementation.

### Added — test infrastructure
- `tests/Wire/RequestFormatterWireTest.php` and `tests/Wire/ResponseParserWireTest.php` with hand-computed RFC-3507 byte fixtures.
- `tests/Security/FailSecureAndValidationTest.php` and `tests/Security/ParserDosLimitsTest.php` covering every branch of the new status matrix and DoS limits.
- `tests/Integration/IcapServerSmokeTest.php` against a real ICAP server (skips gracefully when `ICAP_HOST` is unset).
- `docker-compose.yml` for local integration runs against `mnemoshare/clamav-icap:0.14.5`.
- CI matrix expanded to PHP 8.4 + 8.5; new integration job; `roave/security-advisories` in dev-deps; PHPStan upgraded to 2.x; `beStrictAboutCoverageMetadata` and a separate Integration testsuite in `phpunit.xml.dist`.

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
