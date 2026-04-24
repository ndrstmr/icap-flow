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

## What this library does NOT guarantee

- It does **not** authenticate the ICAP server beyond TLS hostname verification — if you need mutual TLS or pinning, configure the `ClientTlsContext` accordingly.
- It does **not** retry on transient failures. A `IcapServerException` (5xx) propagates to the caller; the caller must decide whether to retry or fail.
- It does **not** cache OPTIONS responses (RFC 3507 §4.10.2 `Options-TTL`); use a PSR-16 cache decorator if you need this.
- It does **not** pool connections — each scan opens a fresh TCP/TLS connection. (Pooling is on the post-v2 roadmap.)

## AI-assisted origin

Large parts of this library were authored with substantial AI assistance and reviewed by three independent due-diligence audits. **Do not deploy this in production without an independent review**. See the [`README.md`](README.md) disclaimer block.

## Hall of fame

Reporters who have helped harden `icap-flow` will be listed here once we have any.
