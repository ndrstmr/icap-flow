# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security
- Virus headers and the other response headers the client evaluates
  (`Encapsulated`, `Preview`, `ISTag`, `Options-TTL`, `Max-Connections`) are
  now matched case-insensitively, for live and cached responses alike.
  Before, a server sending `x-virus-name` instead of `X-Virus-Name` got an
  infected file reported as clean, also in the preview flow. Conflicting
  repeats of a single-valued field are rejected with
  `IcapMalformedResponseException`; `IcapResponse::$headers` keeps the
  server's spelling. *(B01)*
- Request metadata is checked before the first byte goes out. `RequestFormatter`,
  and with it `request()`, rejects ICAP and encapsulated HTTP methods, URIs,
  request and status lines and header fields that would break the wire format
  (CR/LF and other control characters, non-token names, non-string values)
  with `InvalidArgumentException`; `IcapRequest` refuses header values that
  are neither a string nor an array instead of casting them. The scan helpers
  apply the same header check; before, a header name ending in LF and values
  with other control characters got through. `Host` and `Encapsulated` always
  come from the formatter and `Preview`/`Allow` from the preview flow: caller
  values for them are ignored in any spelling instead of replacing them or
  going out twice, also in the full request after `100 Continue`. `scanFile()`
  sends no preview and drops a caller `Preview` the same way; `Allow` and
  other extra headers pass. *(B04)*
- Service paths must be an absolute path with an optional query, and the
  configured host must be a host name or an IP address, in the ICAP and in the
  TCP URI. A missing leading slash, user info, a fragment or a `/` or `?` in
  the host can no longer move the URI authority, path or query. IPv6 server
  addresses are bracketed in ICAP and TCP URIs, so a host like `::1` now
  connects. *(N04)*
- `AmpConnectionPool` no longer identifies a TLS context by its object hash.
  Once a context was collected, its hash could belong to a later context that
  trusts other authorities, and that context was served the idle socket the
  first one had opened. Each context object now gets a token the pool never
  issues twice. *(B07)*
- A socket goes back to the connection pool only after the exchange was
  validated. `AsyncAmpTransport::request()` hands out the raw response before
  status or verdict are checked, so it now always closes its socket, with or
  without a pool. The strict preview flow releases after status and verdict
  are checked and closes on an exception, a `100 Continue` after `ieof`, an
  unexpected status and a `close` token in `Connection` (any spelling, also in
  `keep-alive, close`), including interim and preview responses. Reuse is
  therefore limited to `scanFileWithPreview()` on a session-capable transport
  until the lease contract lands. *(M06, first stage)*
- A preview size can no longer decide an allocation. An explicit
  `scanFileWithPreview()` size must lie between 0 and 1 MiB and is checked
  before the file or a socket is opened. A `Preview` value from a cached
  OPTIONS response is read as a non-negative decimal before any integer cast:
  a larger valid value is clamped to 1 MiB and anything that is not a decimal
  is rejected with `IcapMalformedResponseException`. Before, a cached
  `Preview: 67108864` made a one-byte file ask PHP for 64 MiB and killed the
  process, and a value too large for an integer was cast to something else.
  `Preview: 0` is valid now and goes out as a body section holding only
  `0\r\n\r\n`, never as `null-body`; before, an explicit 0 was refused and a
  cached 0 was replaced by 1024. *(B06)*
- A source that fails no longer ends a body. `ChunkedBodyEncoder` writes the
  terminator, and `ieof`, only after the end of the stream was observed: a
  failing read, an empty read before the end and a rewind that is needed but
  fails raise a `\RuntimeException`, where they used to end the loop and send
  the shortened body as complete. *(B08)*
- `scanFile()` and `scanFileWithPreview()` take the file size from the open
  handle instead of a second path lookup, refuse a handle without one instead
  of announcing `Content-Length: 0`, and send `ieof` only after the end of the
  file was observed at the length the handle reported. A file that grew or
  shrank behind that size now fails the scan before the final chunk, in the
  preview, in the rest of a strict preview flow and in the whole body of
  `scanFile()` and of the full request after a preview, instead of ending as a
  complete body of another size with a clean result. The check lives in
  `RequestFormatter`: a stream body whose message announces a single plain
  `Content-Length` has to deliver exactly that many bytes, so it holds for a
  stream passed to `request()` as well, and for a preview that ends in `ieof`;
  a preview that carries only the start of the body is exempt, since its
  `Content-Length` still describes the whole body, and a body without such a
  field is sent as before. A change that keeps the size is not detected; binding the verdict
  to an immutable object remains open. *(M07, first stage)*
- `scanFile()` and `scanFileWithPreview()` close the file they opened when the
  operation ends, whatever the outcome and also while failed Futures and
  their trace arguments are still referenced, and a cancellation requested
  before the call opens no file. `scanFile()` now opens its file inside the
  returned Future, so an unreadable file fails the Future instead of throwing
  at the call, as `scanFileWithPreview()` already did. The handle is also
  closed when the size lookup on it throws. A stream the caller passes in a
  request body stays open. *(M08)*
- Writing a request is bounded now. `AmpTransportSession::write()` handed
  its chunks to `Socket::write()`, which takes no cancellation: against a
  server that stopped reading, the call stayed suspended for good, holding
  session, socket and upload source, and neither `streamTimeout` nor the
  caller's `Cancellation` ended it. Every `write()` call now has one window
  of `streamTimeout` seconds for all its chunks (the preview and the
  continuation of a strict preview flow each get their own), looks at the
  caller's cancellation before it pulls the first chunk and around every
  write, and ends a stalled write by closing the socket. Whenever the
  producer hands a chunk back (an empty one too) and before the call
  returns, the window is checked on a monotonic clock as well, so a slow
  producer, or one that blocks the event loop, can neither run on past the
  window nor finish the write after it. A call that was cancelled beforehand
  pulls nothing from the producer and sends nothing. A write that ends in a
  cancellation, in the timeout or in any other failure closes the session
  for good, so a later `release()` cannot hand a half-sent request to the
  pool. *(B05)*
- The transport's own timeouts surface as `IcapTimeoutException`: the window
  for acquiring the socket (pool, connect, TLS handshake), for writing the
  request and for reading the response. A cancellation by the caller, also a
  `TimeoutCancellation` of theirs, stays an `Amp\CancelledException`. A
  connection cancelled beforehand does not reach the connector, and a socket
  it hands back after the caller gave up or the window passed is closed, not
  used. Before, both came out as `CancelledException`, so a caller who gave
  up could not be told from a server that was too slow, and the async
  transport never threw `IcapTimeoutException`; code that caught
  `CancelledException` to detect the transport's timeout has to catch
  `IcapTimeoutException` as well. A read that ends early closes the session
  too, and timers and cancellation subscriptions are removed explicitly on
  every way out, also while the exception of a failed Future and its trace
  arguments are still referenced. A connect that exceeds `socketTimeout`
  still raises `IcapConnectionException`, and the deadline over retries and
  backoff remains open. *(M10, first stage)*
- The synchronous transport no longer loses part of a request, waits for ever
  on a peer that stopped reading, or takes a timeout for the end of the
  response. `SynchronousStreamTransport` ignored what `fwrite()` returned, so
  a short write or a failed one left a hole in the request and the next chunk
  went out behind it. Each chunk is now sent completely, carrying on at the
  offset a short write reached; a write that returns `false` ends the call
  with `IcapConnectionException`. A blocking `send()` is bounded by the stream
  timeout on Linux only (on macOS it never returns while the peer does not
  read), so the request now goes out through a non-blocking socket that is
  waited for with `stream_select()`: a peer that takes nothing for a whole
  `streamTimeout` ends the call with `IcapTimeoutException` on every platform,
  and one that reads slowly is waited for. `streamTimeout` keeps its fraction
  of a second (it was cut to whole seconds, so 0.5 became 0), and a value of
  zero, below zero or not finite gives up at once instead of waiting for ever.
  A read that timed out is reported as `IcapTimeoutException`; before, a
  silent server ended as an `IcapMalformedResponseException` about a
  connection closed too early, which a real end of the stream with an
  incomplete frame still is. A failed read is an `IcapConnectionException`.
  The caller's cancellation is also looked at after connecting and between the
  parts of a short write and the waits for a full buffer; a wait that is
  already running is not interrupted, so use `AsyncAmpTransport` where that
  matters. *(M09)*
- OPTIONS cache keys follow PSR-6 and PSR-16 now. The client named an entry
  `host:port/service`, and the adapters put the caller's prefix in front of
  it and kept the ISTag and the list of tracked keys under `__icap_istag` and
  `__icap_keys`. `:` and `/` are reserved in both PSRs and a longer prefix or
  service passes the 64 characters a backend must accept, so a conforming
  backend may refuse such a key, and with it the OPTIONS lookup and the
  preview size lookup fail. A prefix and key that add up to the same text as
  another pair, or a caller key that is a meta name, also met in one backend
  entry. The client now names an entry by a hash of host, port and service,
  the same key in `options()` and in the preview size lookup, and
  `Psr6OptionsCache` and `Psr16OptionsCache` turn prefix, role (entry, ISTag,
  tracked keys) and key into 64 hex digits, each part hashed with its length,
  whatever key a caller passes. The prefix keeps adapters apart; it is no
  longer put in front of the key. The new keys start a cold cache: entries
  stored under the old keys are neither read nor deleted, so clean a shared
  backend yourself if you want them gone. A custom `OptionsCacheInterface`
  receives that hash as its key. The key holds no TLS or tenant identity.
  *(M12)*
- The client's log no longer carries what callers and servers put into a
  request or a response. `request()` and `options()` logged the full `uri`
  (query included), the `host` and the message of the exception of a failed
  request, and the parsers' messages repeated the status line or header line
  they refused, so tokens, user ids and server text reached central logs. The
  three events and their messages stay (`info` started and completed,
  `warning` failed); their context is now `method` (`OPTIONS`, `REQMOD`,
  `RESPMOD`, `other` for any other method), `port`, `statusCode`, `infected`
  and, for a failure, `exception_class` (was `exception`), never the message,
  a trace or the exception itself, and only the class name of an anonymous
  class. `ResponseParser` and the header check the transports run say that a
  status line or header line was invalid instead of repeating it, with the same
  `IcapMalformedResponseException`. Other exception messages (a connect error,
  a file path) still reach the caller; the client just does not log them.
  *(M16)*
- `Config` checks its values where it is built, and everything that builds one
  goes through that check. Before, only `withLimits()` and an empty
  `withVirusFoundHeaders([])` checked anything: the constructor took a host
  with a path or a space, a port of 0 or 70000, a negative, `NAN` or infinite
  timeout, a limit of 0 and a virus header that is not a header name, and the
  error came with the first request. `withVirusFoundHeaders()` read index 0 of
  its argument before looking at it, so names with string keys gave an
  "Undefined array key" warning and a `TypeError`. The constructor now raises
  an `\InvalidArgumentException` that names the setting, also when it is
  reached through a wither or `IcapClient::forServer()`: the host must be a
  host name or an IP address by the rules of the ICAP and socket URIs (nothing
  is resolved), the port 1 to 65535, `socketTimeout` finite and above 0,
  `streamTimeout` finite and 0 or more, each limit 1 or more, and
  `virusFoundHeaders` a non-empty list of header names, kept in the order
  given. A `streamTimeout` of 0 stays valid; it gives up at once and never
  waits without limit. *(N01)*
- `options()` accepts a 200 and nothing else, for the response of the server
  and for one from the cache. A 204, 206, a 1xx other than 100, a 2xx other
  than 200, a 3xx or a 6xx came back as the capability description, and its
  `Options-TTL` and `ISTag` were cached. They now raise
  `IcapProtocolException` (`100` as before); 4xx stays `IcapClientException`
  and 5xx `IcapServerException`. A rejected response is not cached, and a
  cached entry that would be rejected is raised instead of returned. Scans
  keep their own status rules. *(N02)*

- A response now ends where its own layout says, in the transports and in
  `ResponseParser` alike. The framing looked for `\r\n0\r\n\r\n` and took the
  first one for the end of the message: a chunk payload that contains it (any
  binary data may), or a whole forged ICAP response inside a payload, cut the
  response short, and the rest of the real one, forged part included, stayed
  on the socket and was read as the answer to the next request. `null-body=N`
  (N bytes of encapsulated headers follow) and `opt-body` were taken for "no
  body", and bytes read past the end of a message were thrown away. One
  decoder (`ResponseDecoder`) now frames and parses: the `Encapsulated` layout
  gives the length of the header section, the body is read as chunks (hex size,
  exactly that many bytes, CRLF, last chunk, trailer), and terminators or
  responses inside a chunk are data. Bytes read behind the end of a response
  stay with the session: `AmpTransportSession` refuses to write on top of them
  and does not give the socket back to the pool, and `ResponseParser::parse()`
  refuses a string with bytes behind its response. A one-shot `request()`
  closes its socket after the response, as before. *(B02)*
- Responses that are not legal are refused, not read as clean. The old parser
  took `-1`, `ZZ`, `10000000000000000` and `ffffffffffffffff` as chunk sizes for
  an empty body, raised a `ValueError` for `8000000000000000`, skipped the two
  bytes behind a chunk without looking at them and accepted a missing last
  chunk. Now an `IcapMalformedResponseException`, without a partial result, a
  PHP warning or a `ValueError`, follows as soon as the bytes arrive for: a
  chunk size that is not hex digits or does not fit the size limit (checked
  digit by digit before any cast or multiplication, offsets of the
  `Encapsulated` header likewise), bad chunk extensions, a missing CRLF behind
  the data, an invalid trailer, an `Encapsulated` header with a negative,
  repeated, unknown or misordered entry or a layout that is not a response form
  of RFC 3507 §4.4.1 (`req-hdr` together with `res-hdr` is the form of a RESPMOD
  request), a header section that does not end where the layout says, a
  start line of the wrong kind, a bare CR or LF, and the end of the stream
  inside the response. A final response without `Encapsulated` (a 200 or 206,
  an OPTIONS answer too) is refused instead of read as a clean scan with a
  plain body; a 100, a 204 and a 4xx/5xx may still leave the header out. A 100
  and a 204 carry no message, so their layout, if given, is `null-body=0`: a
  204 with a `res-body` came back as a clean scan with the bytes of that body,
  and a 100 with one made the strict preview send the rest of the file. Header
  fields are checked as they arrive, before whitespace is trimmed or a fold is
  joined, in the head and in encapsulated header sections: the name must be a
  token and the value free of control characters (`: v`, `X Bad: v`,
  `X-Virus-\x01Name: EICAR` and `X-A: v\x01` were read as a clean scan). The
  limits for the number and length of header lines now also apply to
  encapsulated header sections, chunk lines and trailers. *(B03, F-4-3)*
- The framing no longer searches the whole buffer again for every fragment of
  a response. It kept no state, so a response that came in many small
  fragments cost about the square of its size (64-byte fragments: 3.6 ms for
  100 kB, 12 ms for 200 kB, 44 ms for 400 kB, 177 ms for 800 kB). The decoder
  keeps its place and looks at each byte a constant number of times, whatever
  the fragmentation: 3.4 µs per KiB at every size. *(M03)*

### Changed
- `composer.json` declares the runtime packages the library uses itself.
  `amphp/amp` (`async`, `Future`, `Cancellation`) and `amphp/byte-stream`
  (`ClosedException`) were only there through `amphp/socket`. The minimum
  versions are the first that run without deprecation notices on PHP 8.4 and
  8.5, measured with the lowest installable runtime packages: `amphp/amp`
  3.1.1, `revolt/event-loop` 1.0.8 and `amphp/socket` 2.3.1 (3.0.0, 1.0.0 and
  2.3.0 gave notices), and `amphp/dns` 2.1.2, a floor for what `amphp/socket`
  pulls in, whose own constraint still allows 2.0.x. `amphp/byte-stream` 2.0.0
  is clean. `composer.lock` keeps every package and version; only its hash
  changes. *(N07)*
- The documentation no longer promises subclass access to
  `IcapClient::executeRaw()`: the class has been `final` since 2.0.0, so
  `protected` leaves the method without access from outside. The 3.0.0 notes,
  the README, the migration guide and the docblock say so, and the example
  in `AmpConnectionPool::tuneFromOptions()` uses the `IcapResponse` that
  `options()` returns. *(N06)*

## [3.0.0] - 2026-05-01

`v3.0.0` is a deliberately small breaking release. It carries no new features
and no architectural rework — it is a cleanup pass that closes three known-stale
corners of the v2 API so the surface can be frozen for the upcoming Symfony
bundle (`ndrstmr/icap-flow-bundle`). Migration: see
[`docs/migration-v2-to-v3.md`](docs/migration-v2-to-v3.md). For most call sites
the upgrade is a no-op other than bumping the constraint to `^3.0`.

### Removed
- **BREAKING (v3.0.0):** `Ndrstmr\Icap\Exception\IcapResponseException` is gone.
  The class was `@deprecated since 2.0` (with `#[\Deprecated]` since v2.2) and
  served only as a catch-all bucket for status codes outside the recognised
  taxonomy. Both throw sites (`IcapClient::interpretResponse()`'s fail-secure
  backstop, `DefaultPreviewStrategy::handlePreviewResponse()`'s `default`
  branch) now throw `IcapProtocolException` instead — semantically more honest:
  a status that is neither a clean-scan signal (204/200/206) nor a recognised
  failure (100/4xx/5xx) is a protocol violation. Callers using
  `catch (IcapExceptionInterface $e)` are unaffected. Callers catching
  `IcapResponseException` directly should switch to `IcapProtocolException` or
  `IcapExceptionInterface`. *(v3-F — Quelle: Claude, Codex)*

### Changed
- **BREAKING (v3.0.0):** `IcapClient::executeRaw()` is now `protected`. It was
  never part of `IcapClientInterface` and exists solely to support the internal
  preview flow, where `100 Continue` is a legitimate intermediate response.
  Exposing it as part of the public surface let callers bypass the fail-secure
  status-code interpretation in `interpretResponse()`. External callers must use
  `request()`, `scanFile()`, `scanFileWithPreview()` or `options()` instead.
  `IcapClient` is `final`, so no subclass has access either.
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
