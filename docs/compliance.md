# Compliance Mapping

This document maps `ndrstmr/icap-flow` capabilities to common regulatory and
security frameworks relevant for European public-sector deployments. It
describes `main`, the unreleased 4.0.0; the changes since 3.0.0 are listed
under *Unreleased* in the [`CHANGELOG.md`](../CHANGELOG.md).

> **Disclaimer.** This mapping is informational — it does not constitute a
> certification or formal compliance statement. Large parts of this library
> were authored with AI assistance (see the [AI-assisted development
> disclaimer](../README.md) and `docs/review/`). Deployers must conduct
> their own independent review and integration testing before production use.

## BSI IT-Grundschutz

### OPS.1.1.4 — Schutz vor Schadprogrammen

| Requirement | Coverage |
|---|---|
| Malware scanning of uploaded content | Core purpose — `scanFile()` / `scanFileWithPreview()` via ICAP (RFC 3507); the ICAP service scans |
| Scan engines | Verified for c-icap `virus_scan` with ClamAV only (verified mode, [`cicap-clamav.md`](cicap-clamav.md)); other services are read by the default rules (virus header list of `Config::withVirusFoundHeaders()`), which have not been verified against a product |
| Fail-secure on unknown status codes | `IcapClient::interpretResponse()` throws typed exceptions for anything that is not a clear clean signal (204, or 200/206 without a virus header; in the verified mode only a 204 to the whole file) |
| Logging of scan results | PSR-3 structured logging with `method`, `port`, `statusCode`, `infected` and `exception_class` context keys; no URI, host, exception message or server text |
| Retry on transient failures | `RetryingIcapClient` decorator: `options()` is repeated after a 5xx with exponential backoff and jitter; uploads and scans get one attempt (a 5xx does not prove the bytes were not processed) |

### APP.4.4 — Webanwendungen und Webservices (excerpt)

| Requirement | Coverage |
|---|---|
| Input validation on external data | Header-name validation (RFC 7230 §3.2.6 tchar whitelist), URI path validation (no CRLF/NUL injection), DoS limits on response size / header count / header line length |
| TLS for inter-service communication | `Config::withTlsContext()` with `AsyncAmpTransport` — TLS 1.2/1.3 via `amphp/socket`, mTLS supported |
| Connection management | Opt-in `AmpConnectionPool` with idle eviction, per-host and total caps, server-side `Max-Connections` awareness, TLS-context-isolated pool keys |

## DSGVO / GDPR — Art. 32 (Security of Processing)

| Measure | Coverage |
|---|---|
| Encryption in transit | TLS/mTLS support for ICAP connections (see `examples/cookbook/04-tls-mtls.php`) |
| Minimisation of data exposure | File contents are streamed, not buffered in memory; `RequestFormatter::format()` emits chunked-transfer encoding. Response headers are not logged (regression-tested in `tests/LoggerIntegrationTest.php`) |
| Resilience | Connection pooling with idle eviction, retry decorator, a limit per phase (connect, TLS handshake, write, start and transfer of the answer) and one `operationTimeout` (60 s by default) over all of them and over every attempt |
| Auditability | PSR-3 structured log events at request start and completion; EUPL-1.2 license ensures source availability |

## EUPL-1.2 License

The library is licensed under EUPL-1.2, which is:
- Recognised by the European Commission as an open-source licence for public-sector software.
- Listed on [OpenCoDE](https://opencode.de/) as a compatible licence.
- Compatible with GPL-2.0, GPL-3.0, LGPL, AGPL, MPL, EUPL, and other licences listed in the EUPL Appendix.

## AI-Assisted Development

This library was developed with significant AI assistance. Audits of earlier
versions are kept in `docs/review/` (v1, v2.1, v3.0). Their ratings and
maturity levels describe the version they reviewed, not the current code; this
document claims no maturity level.

See the [README disclaimer](../README.md) for the full statement.
