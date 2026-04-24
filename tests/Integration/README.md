# Integration tests

These tests talk to a real ICAP server over TCP. They are **not** run by
`composer test` (which invokes the `Unit` testsuite only).

## Running locally

```bash
docker compose up -d              # starts c-icap (echo + clamav services)
ICAP_HOST=127.0.0.1 ICAP_PORT=1344 composer test:integration
```

Set `ICAP_CLAMAV_SERVICE=/virus_scan` (or whatever the service path is
in your compose file) to enable the virus-scan tests that require
ClamAV signatures.

## Design

Each test declares which server capability it needs via an env var
check at the top of the closure and uses `test()->markTestSkipped()`
when that capability is not configured. This way the suite stays green
on contributor machines that don't have Docker running while still
giving the CI container real end-to-end coverage.
