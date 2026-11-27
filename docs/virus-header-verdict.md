# Fail-closed verdict over virus and block headers

`VirusHeaderVerdict` is the fail-closed counterpart of the default header rules: it inspects configured virus headers (and optional policy-block headers), but accepts only a `204` after the whole file as clean. It implements `VerdictInterface` and is bound to one ICAP service, so the client scans with no other service while it is active, refuses custom preview strategies, and sends `Allow: 204` on whole-file scans (`scanFile()`, `scanFileDiscardingBody()`).

Unlike `CicapVerdict` ([`docs/cicap-clamav.md`](cicap-clamav.md)), **`VirusHeaderVerdict` is not verified against a reference ICAP service.** `icap-flow` tests the rules below; the operator must verify the behavior of the target ICAP service.

## Rules

While a verdict is configured, `Config::getVirusFoundHeaders()` does not apply; `VirusHeaderVerdict` inspects only the virus header list (default `['X-Virus-Name']`) and optional block header list (default `[]`) passed to its constructor. Header names are compared case-insensitively and every occurrence and value of each configured header is read.

| Response | Result |
|---|---|
| `204` after the whole file, with neither a configured virus header nor a configured block header | Clean (`isInfected() === false`, `getVirusName() === null`, `getBlockReason() === null`) |
| `204` before the whole file was sent (partial preview) | `IcapProtocolException` |
| `204` carrying any configured virus or block header (empty or non-empty) | `IcapProtocolException` (contradiction) |
| `200` or `206` with one non-empty virus name across all configured virus headers (identical repeats allowed, also alongside a block header) | Infected (`isInfected() === true`, `getVirusName()` set, `getBlockReason() === null`) |
| `200` or `206` with empty or conflicting virus header values | `IcapProtocolException` |
| `200` or `206` without virus headers and with one non-empty value across all configured block headers | Policy block (`isInfected() === true`, `getVirusName() === null`, `getBlockReason()` set) |
| `200` or `206` without virus headers and with empty, conflicting, or unconfigured block headers | `IcapProtocolException` |
| `200` or `206` with neither virus nor block headers (for example an unmodified message or an error page) | `IcapProtocolException` |
| `100 Continue` carrying any configured virus or block header | `IcapProtocolException` (the remainder of the file is not sent) |
| `4xx`, `5xx`, or any other status | `IcapClientException`, `IcapServerException`, or `IcapProtocolException` |

### Policy blocks as results

A policy block without a virus name only becomes a `ScanResult` when block headers are explicitly configured on `VirusHeaderVerdict`; without configured block headers, a `200`/`206` block page raises `IcapProtocolException`.

For a policy block, `ScanResult::isInfected()` returns `true` so callers that only check `isInfected()` stay fail-closed. `ScanResult::getVirusName()` is `null` and `ScanResult::getBlockReason()` returns the reason reported by the server. That text comes from the ICAP server and is untrusted input; `icap-flow` never logs it or puts it into exception messages.

## Combining with a request head and whole-file scans

For ICAP servers that reject `RESPMOD` without `req-hdr` and signal malware findings and policy blocks through headers, combine `scanFile()` (no preview), `encapsulatedRequest` (see [`docs/file-sources.md#encapsulated-request-header`](file-sources.md#encapsulated-request-header)), and `new VirusHeaderVerdict('/avscan', ['X-Virus-Name'], ['X-Block-Reason'])`. See [`examples/cookbook/08-virus-header-verdict.php`](../examples/cookbook/08-virus-header-verdict.php) for the complete runnable setup and result handling (`isInfected()`, `getVirusName()`, `getBlockReason()`).

## What `icap-flow` checks and what it does not

- **Checked by `icap-flow`:** ICAP framing and status codes, service binding (`VerdictInterface::service()`), that a `204` only arrives after the whole file and carries no verdict headers, that `100 Continue` carries no verdict headers, and that configured virus/block headers are present, non-empty, and consistent across all occurrences.
- **`204` without `Encapsulated`:** Some ICAP servers omit the `Encapsulated` header on `204` responses even though RFC 3507 §4.4.1 states it MUST be included. `icap-flow` accepts a `204` without `Encapsulated` (as well as `204` with `Encapsulated: null-body=0`).
- **Not checked by `icap-flow`:** Whether the ICAP service actually scanned the upload, whether its policies skip certain types or large files with `204`, or whether the configured header names match what the server actually emits.

## Operator verification checklist

Before using `VirusHeaderVerdict` in production, test the configured service end to end with at least:

1. **EICAR test file:** verify `isInfected() === true` and `getVirusName()` contains the expected threat name.
2. **Clean file:** verify `isInfected() === false` (`204` after the whole file).
3. **Largest permitted file size:** verify timeouts are sufficient and the server scans the file instead of returning `204` unchecked.
4. **Unscannable file (such as an encrypted archive):** verify how the server responds and confirm it does not silently return `204` unless your policy allows unscannable files.
5. **Policy-blocked file type:** verify that a blocked file produces `isInfected() === true`, `getVirusName() === null`, and `getBlockReason()` (when block headers are configured), or `IcapProtocolException` (when no block headers are configured).
