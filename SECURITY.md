# Security policy

`icap-flow` sits in the security-critical path of any application that uses it for upload virus scanning. We take vulnerability reports seriously.

## Reporting a vulnerability

**Do not** open a public GitHub issue for a security finding.

1. Use GitHub's [private vulnerability report](https://github.com/ndrstmr/icap-flow/security/advisories/new) form, **or**
2. Open an issue marked "Security disclosure request" — we'll reply with a private channel — without including the details.

Please include:
- the affected version (`composer.lock` snippet is fine),
- a clear reproduction or proof of concept (curl / php script / PHPUnit fixture),
- the impact you observed and what an attacker could do with it,
- whether the finding is already public elsewhere (CVE, blog post, social media).

## What you can expect

| Step | Target |
|---|---|
| Initial acknowledgement | within 5 working days |
| First triage / severity rating (CVSS v3.1) | within 10 working days |
| Fix or workaround | depends on severity (see below) |
| Public advisory | after fix is released, with reporter credit unless anonymity is requested |

### Severity bands and target windows

| CVSS v3.1 | Target fix window |
|---|---|
| 9.0 – 10.0 (Critical) | 7 days |
| 7.0 – 8.9 (High) | 30 days |
| 4.0 – 6.9 (Medium) | 90 days |
| 0.1 – 3.9 (Low) | next regular release |

These are targets, not contractual SLAs.

## Coordinated disclosure

We follow [responsible disclosure](https://en.wikipedia.org/wiki/Coordinated_vulnerability_disclosure):

1. You report privately.
2. We confirm and develop a fix.
3. We coordinate a release date with you.
4. The advisory is published with your name (or anonymously) on the agreed date.
5. We file a CVE where appropriate.

## Scope

In scope:
- Anything in `src/` of this repository.
- The published Composer package on Packagist (`ndrstmr/icap-flow`).
- The default `docker-compose.yml` and CI workflow shipped in this repo.

Out of scope:
- Vulnerabilities in your ICAP server (c-icap, ClamAV, vendor products) — report those upstream.
- Vulnerabilities in transitive Composer dependencies — see their own security policies.
- Issues that require privileged local access to the host running the library.

## Responsibilities

- **The client** speaks ICAP, reads each answer by the rules of its mode (the default rules or the verified mode), bounds what it reads and holds, and reports errors. The default rules and custom preview strategies need validation against the service in use.
- **The ICAP service** scans: engine, signatures, which types and sizes it inspects. For the verified mode it has to be configured like the reference service ([`docs/cicap-clamav.md`](docs/cicap-clamav.md)).
- **Your application** treats every exception as "not scanned" and blocks the upload, keeps the object and its path stable through the scan and releases only the same unchanged object ([`docs/file-sources.md`](docs/file-sources.md)). It owns quarantine, release, access control and its own concurrency.

## Security-relevant defaults & guarantees

This file describes `main`, the unreleased 4.0.0; the changes since 3.0.0 are listed under *Unreleased* in the [`CHANGELOG.md`](CHANGELOG.md).

- **Fail-secure on protocol errors.** ICAP `100 Continue` outside a preview exchange raises `IcapProtocolException` — never a clean scan result.
- **CRLF / NUL / control-character validation** on `$service` and on every user-supplied request header (name + value), enforced before any byte hits the socket.
- **TLS available** via `Config::withTlsContext(ClientTlsContext)` with `AsyncAmpTransport` (`SynchronousStreamTransport` refuses a TLS context with `IcapConnectionException`). The default `ClientTlsContext` verifies the certificate and the peer name.
- **Bounded reads.** `Config::withLimits(maxResponseSize, maxHeaderCount, maxHeaderLineLength)` caps every response. Defaults: 10 MB total, 100 headers, 8 KB per line. The discarding scans (`scanFileDiscardingBody()`, `scanFileWithPreviewDiscardingBody()`) take the caller's budget for the bytes of a response instead of `maxResponseSize`, read it without keeping it (memory does not grow with its size) and keep the header limits.
- **Preview status and decisions.** For a partial preview, ICAP 4xx and 5xx responses raise `IcapClientException` and `IcapServerException`, and unexpected statuses raise `IcapProtocolException`, before a custom strategy is called. The client requires continuation on `100` and an abort decision on final `200`, `204` or `206`. A custom strategy still decides the meaning of an allowed final answer for the service; a `204` does not itself prove a scan. Your application must block the upload on exceptions.
- **Verified mode for c-icap + ClamAV** (`CicapVerdict`, [`docs/cicap-clamav.md`](docs/cicap-clamav.md)). The default rules do not know c-icap's infection headers (`X-Infection-Found`, `X-Violations-Found`; the default list is `X-Virus-Name`) and read a `204`, or a `200` without a header of that list, as clean, which is also what c-icap answers for a file it did not scan. The verified mode reads this service's answers by their values and counts and reports a result as clean only after a `204` to a file that was sent whole; any other answer that does not show a scan (also ClamAV's report of a limit or an encrypted file) is an `IcapProtocolException`. It is made for one service and the client scans with no other. What the answers cannot show, whether that service scans every type, is its configuration (see the document).

## What this library DOES provide

- **Automatic retry of OPTIONS on 5xx** via `RetryingIcapClient` (decorator; exponential backoff with full jitter that ends with the caller's cancellation and with the end of the operation; configurable attempts, the first one counted). Only the bodyless `options()` is repeated: a 5xx or a failed write does not prove that the server did not process the bytes of an upload, so `request()`, `scanFile()` and `scanFileWithPreview()` get one attempt. Wraps `IcapClient`; no retry by default on the bare client.
- **A validated OPTIONS cache** via the `$optionsCache` constructor parameter: `InMemoryOptionsCache` (at most 64 entries by default) or `Psr6OptionsCache` and `Psr16OptionsCache` for a shared backend. An entry is one checked OPTIONS answer of one endpoint, service and set of TLS settings, valid for its `Options-TTL` (RFC 3507 §4.10.2), at most an hour from the arrival of the answer; whatever a cache returns, also one of your own, is a miss unless its response passes the checks and limits of the client. A custom cache is responsible for the key and expiry ([contract](README.md#options-cache)).
- **Keep-alive connection pooling** via `AmpConnectionPool` (LIFO per host:port:tls-context identity). Opt-in: `new AsyncAmpTransport($pool)`; without a pool every request opens its own connection, and `NullConnectionPool` does the same explicitly. A socket goes back to the pool only after the validated exchange of the strict preview flow (`scanFileWithPreview()`); every other request closes its socket. The pool limits connections, not only idle sockets: connections being set up, in use and idle count against `maxConnectionsPerHost` (per host and port, whatever the TLS context) and `maxConnections` (whole pool). A full pool fails with `IcapConnectionException`; an optional bounded queue (`maxWaiters`, `waitTimeout`) lets calls wait first come, first served. Only an idle socket gives way for a new connection, never one in use.
- **TLS pool-key isolation**: each distinct `ClientTlsContext` object gets its own idle-socket stack, identified by a token the pool never issues twice, so that a socket opened under one context is never served under another.
- **Pool idle eviction**: `AmpConnectionPool` closes sockets idle longer than `maxIdleSeconds` (default 30 s, monotonic clock), in every bucket and below the top of the stack, on each acquire and by an unreferenced timer that runs only while something is idle. When it hands out an idle socket it reads it once: a socket whose peer has closed, or that has received bytes nobody asked for, is closed instead. Prevents stale-socket accumulation in long-running workers (RoadRunner, Swoole, ReactPHP).
- **OPTIONS-driven pool tuning**: `AmpConnectionPool::tuneFromOptions(IcapResponse, ?Config)` honours the server's `Max-Connections` header (RFC 3507 §4.10.2) as a positive decimal number of at most nine digits (anything else is an `IcapMalformedResponseException`). With the `Config` it lowers the cap of that endpoint (host and port); without it, the cap of every endpoint. It can only lower what the pool allows itself, and a later answer that allows more serves the calls that wait. The pool keeps the number of at most 256 endpoints and refuses a further one with an `IcapConnectionException` rather than forget it.
- **A limit per phase and one end per operation** (the settings are in the README under *Timeouts*). `operationTimeout` (default 60 s) is a monotonic end, fixed when a public method is called, that covers every phase and, in the retry decorator, every attempt and pause, and that no nested call or inner client can extend. The end of the operation is an `IcapTimeoutException`; the caller's own cancellation stays an `Amp\CancelledException` and wins. An expired call closes its socket and its file handle (one held by a hanging file call when that call returns, see below) and does not return the socket to the pool. A cache write is not started after an observed timeout or cancellation, but a write already in progress cannot be undone. The limits are library defaults, not a statement about your deployment: raise `operationTimeout` and `responseStartTimeout` where scanning large files takes longer than a minute.
- **Streaming-safe Strict §4.5 continuation**: the body remainder is streamed via `ChunkedBodyEncoder::encodeRemainderFromStream()`, never buffered in memory.

## What this library does NOT guarantee

- It does **not** authenticate the ICAP server beyond the TLS checks of the `ClientTlsContext` (certificate and peer name by default) — if you need mutual TLS or pinning, configure the `ClientTlsContext` accordingly. See [`examples/cookbook/04-tls-mtls.php`](examples/cookbook/04-tls-mtls.php).
- It does **not** limit concurrency without a pool (or with `NullConnectionPool`): that stays the application's job. And it does **not** guarantee that a pooled socket is alive: a peer that closes right after the pool looked fails that request like any other. Neither the pool nor the transports reconnect or retry a request that may have been sent; the only retry is `RetryingIcapClient`, which repeats `options()` after a 5xx answer.
- It does **not** interrupt code it cannot interrupt. The end of an operation is a timer and a look at the clock where control comes back: a request-body producer or a cache backend that holds the event loop and does not return is waited for, and a result that comes in after the end is discarded. Files are read through amphp/file, so the end of the operation also ends the wait for a file call; a call that hangs in the operating system is not interrupted, it keeps its worker, and the handle is closed when the call returns ([`docs/file-sources.md`](docs/file-sources.md)). The synchronous transport (`SynchronousStreamTransport`) can only bound its own native waits, and an inner client that ignores its cancellation is waited for.
- It does **not** know whether a service scanned a file. A `204` to a file inside the preview looks the same for a scanned file and for one the service skipped (a type it does not scan, a c-icap without a scan engine, a file over a limit of ClamAV). The verified mode rejects the answers that tell, and relies on the reference configuration for the rest (`tests/Integration/reference/`, [`docs/cicap-clamav.md`](docs/cicap-clamav.md)); it is verified for c-icap `virus_scan` with ClamAV only, and a skipped integration test is no verification.
- It does **not** invalidate the OPTIONS cache before the TTL ends. The ISTag does not flush it (it belongs to one service; the flush of earlier versions hit the others and raced between processes): a change of the service shows when its entry ends, at most an hour later, and processes that share a backend are not told. It does not know a tenant either: separate caches, or `prefix`es, per security domain. The size of a shared backend is its own to bound; the adapters bound the lifetime and size of an entry.
- It does **not** persist the OPTIONS cache across process restarts when using `InMemoryOptionsCache`. For a shared cache use `Psr6OptionsCache` or `Psr16OptionsCache` with a backend configured for the required sharing and persistence; APCu's scope depends on the platform and deployment.
- It does **not** ship OpenTelemetry traces or Prometheus metrics; its signals are the PSR-3 events.

## AI-assisted origin

Large parts of this library were authored with substantial AI assistance and reviewed in independent audits ([`docs/review/`](docs/review/); they describe the version they reviewed). **Do not deploy this in production without an independent review**. See the [`README.md`](README.md) disclaimer block.

## Hall of fame

Reporters who have helped harden `icap-flow` will be listed here once we have any.
