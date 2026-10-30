# Integration tests

These tests talk to a real ICAP server over TCP. They are **not** run by
`composer test` (which invokes the `Unit` testsuite only).

## Running locally

```bash
docker compose up --wait --wait-timeout 600   # healthy when /avscan finds EICAR; first start downloads signatures
ICAP_HOST=127.0.0.1 ICAP_PORT=1344 \
ICAP_ECHO_SERVICE=/avscan ICAP_CLAMAV_SERVICE=/avscan ICAP_TYPED_SERVICE=/avscan_typed \
    composer test:integration
```

`ICAP_CLAMAV_SERVICE` has to be a service that scans every type and answers a clean file with 204: the reference
`/avscan`, which the verdict of the tests is made for. `ICAP_TYPED_SERVICE` is `/avscan_typed`, which scans only the
types the unmodified image scans; the tests read its answers at the protocol level, and show that the verified mode
refuses it. The image is `linux/amd64` only; on an ARM host Docker emulates it (the start takes about a minute longer).

## Reference service

The verified mode ([`docs/cicap-clamav.md`](../../docs/cicap-clamav.md)) is specified and tested for this service:

| | |
|---|---|
| Image | `mnemoshare/clamav-icap:0.14.5@sha256:aa9a6b47929f545c7890a284af10d61abadbcb73a2c4af6e05dffd6c59a56d42` (the index; its `linux/amd64` manifest is `sha256:2d44e5475fc652ff530b4cc554328601bf73b30e12e8fbb7c964de6ffc18ffb5`), built 2026-04-24 on Alpine 3.22.4 |
| c-icap, c-icap-modules | 0.6.3, 0.5.7 (build arguments `cicapBaseVersion`, `cicapModuleVersion` of the image; the service banner says `C-ICAP/0.6.3`) |
| ClamAV | 1.4.3 (`clamav-1.4.3-r0`; freshclam reports it as outdated, 1.4.6 recommended) |
| Signatures | downloaded when the container starts. Recorded on 2026-10-27: daily 28166, main 63, bytecode 339 (3,642,898 signatures with the 2 of the image) |
| Configuration | `c-icap.conf` and `clamd_mod.conf` of the image; `virus_scan.conf`, `clamd.conf`, `start.sh` and `healthcheck.sh` from [`reference/`](reference) |

To read the same facts from a running container:

```bash
docker inspect --format '{{.Image}} {{json .Config.Env}}' icap-flow-clamav   # image id, cicapBaseVersion, cicapModuleVersion
docker exec icap-flow-clamav /opt/c-icap/bin/c-icap -VV                      # c-icap 0.6.3
docker exec icap-flow-clamav clamdscan --version                             # ClamAV 1.4.3/<signature version>/<date>
docker exec icap-flow-clamav clamconf -n                                     # effective clamd settings and databases
```

## Recorded answers

`tests/Fixtures/cicap-virus-scan/` holds answers of the reference service as they came over the wire (the unit tests of
the verified mode replay them; `.gitattributes` keeps their CRLF). All requests were `RESPMOD` with an encapsulated
`HTTP/1.1 200 OK` and `Allow: 204`:

| File | Request |
|---|---|
| `clean-204.icap` | `/avscan`, a harmless text file of 204 bytes, `Preview: 1024` (the file is inside the preview) |
| `continue-100.icap` | `/avscan`, a harmless file of 5000 bytes, `Preview: 1024`: the answer to the preview |
| `infected-200.icap` | `/avscan`, the EICAR file (68 bytes), no preview |
| `limit-exceeded-200.icap` | `/avscan`, a ZIP with EICAR and 3 MiB of zeros (over `MaxFileSize 2M`), preview, then the rest after `100 Continue` |
| `encrypted-200.icap` | `/avscan`, `encrypted-eicar.zip` (below), `Preview: 1024` (the file is inside the preview) |
| `not-scanned-200.icap` | `/avscan_typed`, a PDF-like file of 495 bytes without EICAR, no preview |
| `error-500.icap` | `/avscan` with clamd stopped, a harmless file, `Preview: 1024` |
| `encrypted-eicar.zip` | `zip -P secret` of the EICAR file; its content is encrypted, so the archive holds no EICAR string |

## Design

Each test declares which server capability it needs via an env var check at the top of the closure and uses
`test()->markTestSkipped()` when that capability is not configured. This way the suite stays green on contributor
machines that don't have Docker running while still giving the CI container real end-to-end coverage. A skipped test
is no result: the verified mode counts as tested only where `CicapVirusScanTest` ran. CI runs the suite with
`--fail-on-skipped` and `--fail-on-empty-test-suite` and checks the verdict of PHPUnit (`.github/phpunit-verdict.sh`),
so there a test without its service fails the run.
