# Compliance Mapping

This document maps `ndrstmr/icap-flow` capabilities to common regulatory and
security frameworks relevant for European public-sector deployments.

> **Disclaimer.** This mapping is informational — it does not constitute a
> certification or formal compliance statement. Large parts of this library
> were authored with AI assistance (see the [AI-assisted development
> disclaimer](../README.md) and `docs/review/`). Deployers must conduct
> their own independent review and integration testing before production use.

## BSI IT-Grundschutz

### OPS.1.1.4 — Schutz vor Schadprogrammen

| Requirement | Coverage |
|---|---|
| Malware scanning of uploaded content | Core purpose — `scanFile()` / `scanFileWithPreview()` via ICAP (RFC 3507) |
| Support for multiple AV engines | `Config::withVirusFoundHeaders()` supports ClamAV, Symantec, Trend Micro, Sophos, McAfee, Kaspersky header conventions |
| Fail-secure on unknown status codes | `IcapClient::interpretResponse()` throws typed exceptions for anything that is not a clear clean signal (204 / 200 without virus header) |
| Logging of scan results | PSR-3 structured logging with `method`, `uri`, `host`, `port`, `statusCode`, `infected` context keys |
| Retry on transient failures | `RetryingIcapClient` decorator with exponential backoff on 5xx |

### APP.4.4 — Webanwendungen und Webservices (excerpt)

| Requirement | Coverage |
|---|---|
| Input validation on external data | Header-name validation (RFC 7230 §3.2.6 tchar whitelist), URI path validation (no CRLF/NUL injection), DoS limits on response size / header count / header line length |
| TLS for inter-service communication | `Config::withTlsContext()` — TLS 1.2/1.3 via `amphp/socket`, mTLS supported |
| Connection management | `AmpConnectionPool` with idle eviction, per-host caps, server-side `Max-Connections` awareness, TLS-context-isolated pool keys |

## DSGVO / GDPR — Art. 32 (Security of Processing)

| Measure | Coverage |
|---|---|
| Encryption in transit | TLS/mTLS support for ICAP connections (see `examples/cookbook/04-tls-mtls.php`) |
| Minimisation of data exposure | File contents are streamed, not buffered in memory; `RequestFormatter::format()` emits chunked-transfer encoding. Response headers are not logged (regression-tested in `tests/LoggerIntegrationTest.php`) |
| Resilience | Connection pooling with idle eviction, retry decorator, per-IO timeouts (no session-lifetime accumulation) |
| Auditability | PSR-3 structured log events at request start and completion; EUPL-1.2 license ensures source availability |

## EUPL-1.2 License

The library is licensed under EUPL-1.2, which is:
- Recognised by the European Commission as an open-source licence for public-sector software.
- Listed on [OpenCoDE](https://opencode.de/) as a compatible licence.
- Compatible with GPL-2.0, GPL-3.0, LGPL, AGPL, MPL, EUPL, and other licences listed in the EUPL Appendix.

## AI-Assisted Development

This library was developed with significant AI assistance. Four independent
deep-research audits (Claude, Codex ×2, Jules) are documented in
`docs/review/review_v2-1/` with a consolidated task list that tracks every
finding to resolution. The audit consensus is TRL-7 (system prototype
demonstration in operational environment) with scores of 74–77/100.

See the [README disclaimer](../README.md) for the full statement.
