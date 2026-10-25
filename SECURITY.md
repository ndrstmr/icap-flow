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

## Security-relevant defaults & guarantees

The v2 line was reviewed against three independent due-diligence reports (`docs/review/`) and ships with the following guarantees you can audit:

- **Fail-secure on protocol errors.** ICAP `100 Continue` outside a preview exchange raises `IcapProtocolException` — never a clean scan result.
- **CRLF / NUL / control-character validation** on `$service` and on every user-supplied request header (name + value), enforced before any byte hits the socket.
- **TLS available** via `Config::withTlsContext(ClientTlsContext)`. The default `ClientTlsContext` performs hostname verification against the certificate.
- **Bounded reads.** `Config::withLimits(maxResponseSize, maxHeaderCount, maxHeaderLineLength)` caps every response. Defaults: 10 MB total, 100 headers, 8 KB per line.
- **No fail-open on 5xx.** Server errors raise `IcapServerException`. Your application is responsible for blocking the upload — the library will never silently report it as clean.

## What this library DOES provide (since v2.0 / v2.1 / v2.2 / v3.0)

- **Automatic retry of OPTIONS on 5xx** via `RetryingIcapClient` (decorator; exponential backoff with full jitter that ends with the caller's cancellation and with the end of the operation; configurable attempts, the first one counted). Only the bodyless `options()` is repeated: a 5xx or a failed write does not prove that the server did not process the bytes of an upload, so `request()`, `scanFile()` and `scanFileWithPreview()` get one attempt. Wraps `IcapClient`; no retry by default on the bare client.
- **OPTIONS-response caching** via `InMemoryOptionsCache` (honours `Options-TTL`, RFC 3507 §4.10.2). Plug in via the `$optionsCache` constructor parameter.
- **Cross-process OPTIONS caching** via `Psr6OptionsCache` (PSR-6) and `Psr16OptionsCache` (PSR-16) — since v2.2. Suitable for Redis, APCu, Memcached, Symfony Cache. Supports cross-process ISTag-based invalidation via meta-keys.
- **ISTag-based cache invalidation** (since v2.2): a server config / signature reload (new `ISTag` header per RFC 3507 §4.10.2) flushes all cached OPTIONS entries.
- **Keep-alive connection pooling** via `AmpConnectionPool` (LIFO per host:port:tls-context identity). Opt-in: `new AsyncAmpTransport($pool)`; without a pool every request opens its own connection. `NullConnectionPool` is available for stateless workers (since v2.2). The pool limits connections, not only idle sockets (unreleased): connections being set up, in use and idle count against `maxConnectionsPerHost` (per host and port, whatever the TLS context) and `maxConnections` (whole pool). A full pool fails with `IcapConnectionException`; an optional bounded queue (`maxWaiters`, `waitTimeout`) lets calls wait first come, first served. Only an idle socket gives way for a new connection, never one in use.
- **TLS pool-key isolation** (since v2.1.1): each distinct `ClientTlsContext` object gets its own idle-socket stack, preventing cross-tenant socket reuse in multi-tenant deployments.
- **Pool idle eviction** (since v2.2, complete since unreleased): `AmpConnectionPool` closes sockets idle longer than `maxIdleSeconds` (default 30 s, monotonic clock), in every bucket and below the top of the stack, on each acquire and by an unreferenced timer that runs only while something is idle. When it hands out an idle socket it reads it once: a socket whose peer has closed, or that has received bytes nobody asked for, is closed instead. Prevents stale-socket accumulation in long-running workers (RoadRunner, Swoole, ReactPHP).
- **OPTIONS-driven pool tuning** (since v2.2): `AmpConnectionPool::tuneFromOptions(IcapResponse, ?Config)` honours the server's `Max-Connections` header (RFC 3507 §4.10.2) as a positive decimal number of at most nine digits (anything else is an `IcapMalformedResponseException`). With the `Config` it lowers the cap of that endpoint (host and port); without it, the cap of every endpoint. It can only lower what the pool allows itself, and a later answer that allows more serves the calls that wait. The pool keeps the number of at most 256 endpoints and refuses a further one with an `IcapConnectionException` rather than forget it.
- **A limit per phase and one end per operation** (since unreleased; the settings are in the README under *Timeouts*). `operationTimeout` (default 60 s) is a monotonic end, fixed when a public method is called, that covers every phase and, in the retry decorator, every attempt and pause, and that no nested call or inner client can extend. The end of the operation is an `IcapTimeoutException`; the caller's own cancellation stays an `Amp\CancelledException` and wins; an expired call closes its socket and file handle and gives nothing to the pool or the OPTIONS cache. The limits are library defaults, not a statement about your deployment: raise `operationTimeout` and `responseStartTimeout` where scanning large files takes longer than a minute.
- **Streaming-safe Strict §4.5 continuation** (since v2.1.2): the body remainder is streamed via `ChunkedBodyEncoder::encodeRemainderFromStream()`, never buffered in memory.

## What this library does NOT guarantee

- It does **not** authenticate the ICAP server beyond TLS hostname verification — if you need mutual TLS or pinning, configure the `ClientTlsContext` accordingly. See [`examples/cookbook/04-tls-mtls.php`](examples/cookbook/04-tls-mtls.php).
- It does **not** limit concurrency without a pool (or with `NullConnectionPool`): that stays the application's job. And it does **not** guarantee that a pooled socket is alive: a peer that closes right after the pool looked fails that request like any other. Neither the pool nor the transports reconnect or retry a request that may have been sent; the only retry is `RetryingIcapClient`, which repeats `options()` after a 5xx answer.
- It does **not** interrupt code it cannot interrupt. The end of an operation is a timer and a look at the clock where control comes back: a request-body producer, a cache backend or a file read that holds the event loop and does not return is waited for, and a result that comes in after the end is discarded (non-blocking file I/O is planned); the synchronous transport (`SynchronousStreamTransport`) can only bound its own native waits, and an inner client that ignores its cancellation is waited for.
- It does **not** persist the OPTIONS cache across process restarts when using `InMemoryOptionsCache`. For cross-process caching use `Psr6OptionsCache` or `Psr16OptionsCache` with a Redis / APCu / Memcached / Symfony Cache backend.
- It does **not** ship OpenTelemetry traces or Prometheus metrics out of the box. An observability decorator is on the wishlist (`docs/review/review_v2-1/consolidated_v2.1_task-list.md`, item W-Y) and will land when there is concrete deployment demand.

## AI-assisted origin

Large parts of this library were authored with substantial AI assistance and reviewed by three independent due-diligence audits. **Do not deploy this in production without an independent review**. See the [`README.md`](README.md) disclaimer block.

## Hall of fame

Reporters who have helped harden `icap-flow` will be listed here once we have any.
