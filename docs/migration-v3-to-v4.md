# Migrating from 3.0 to 4.0

This guide describes the changes in 4.0. See the
[`CHANGELOG.md`](../CHANGELOG.md) for the complete list.

## Verify the service and fail closed

- The default verdict rules are not verified against a particular product. For
  c-icap `virus_scan` with ClamAV, configure the service and opt in to
  [`CicapVerdict`](cicap-clamav.md). The client cannot tell whether a service
  silently skipped a file; test the actual service and its configuration.
- Treat exceptions and cancellations as no verdict, and block release. Custom
  preview strategies no longer receive error or unexpected statuses. They must
  continue on `100` and stop on final `200`, `204` or `206`; inconsistent
  decisions raise `IcapProtocolException`. Validate the interpretation of
  final responses against the service: a `204` alone does not prove a scan.
- Keep the source and its path stable during the scan and release only the
  same unchanged object. The application owns quarantine and release. See
  [file sources](file-sources.md).

## Adjust I/O and error handling

- File helpers now require worker processes and `proc_open`; they accept
  regular local files, not stream-wrapper URLs. A custom request formatter
  must pass the supplied `LocalFile` body to `ChunkedBodyEncoder` and consume
  it completely. A hanging OS call cannot be interrupted immediately.
- Each operation has a 60-second default budget, including retries. Configure
  `operationTimeout`, `responseStartTimeout` and the other limits for your
  file sizes and service. Code that blocks the event loop cannot be
  interrupted until it returns.
- Internal timeouts now raise `IcapTimeoutException`; caller cancellation
  remains `Amp\CancelledException`. Socket failures become
  `IcapConnectionException`, and file, stream and built-in cache errors may
  raise `IcapStorageException`. Catch failures from the returned future as
  well as invalid arguments at the call site.
- `RetryingIcapClient` retries only `options()` after an ICAP 5xx response;
  upload requests are attempted once. Decide in the application whether a
  failed upload is safe to retry.
- Responses are parsed more strictly. Check custom servers and fixtures for
  valid ICAP framing, headers and `Encapsulated` layouts, and for bytes sent
  behind a response, which are now an error. Request metadata, service paths
  and configuration are also validated more strictly.

## Review resource limits and integrations

- A preview is continued on the connection it began on (RFC 3507 §4.5).
  `SynchronousStreamTransport` supports that now. A custom transport has to
  implement `SessionAwareTransport` (and, for the discarding helpers,
  `DiscardingTransport` with sessions that implement
  `DiscardingTransportSession`) or `scanFileWithPreview*()` throws a
  `LogicException` before it opens the file; the client no longer sends the
  whole file again in a second request. `request()` and `scanFile()` need no
  sessions. With `SynchronousStreamTransport` the rest of a previewed file now
  has to go out within `streamTimeout`, as with the async transport: raise it
  for large files.
- Pooling remains opt-in. Its caps now include connecting and active sockets;
  a full pool fails unless a bounded queue is configured. Socket reuse is
  limited to the validated strict-preview flow.
- Existing response-returning methods still buffer bodies up to the configured
  limit. For large echoed responses, the new discarding file helpers require
  an explicit response budget and retain no response body. See
  [large responses](file-sources.md#large-responses).
- OPTIONS cache keys and stored format changed: old entries are not read or
  removed. Entries expire after the server TTL, capped at one hour; ISTag no
  longer flushes other entries. A custom cache must enforce key and expiry
  rules, and each security domain needs its own cache or prefix.
- PSR-3 context no longer includes host, URI, exception message or the
  exception itself. Update log processing that depended on those fields.
