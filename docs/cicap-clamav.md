# Verified mode for c-icap + ClamAV

The default rules read a `204`, and a `200` or `206` without a header of `Config::getVirusFoundHeaders()` (default
`X-Virus-Name`), as a clean scan. The `virus_scan` service of c-icap reports an infection in `X-Infection-Found` and
`X-Violations-Found`, and it answers `204` or sends the message back for a file it did not scan. So with the default
rules a client reports EICAR as clean (recorded against the reference service below). The verified mode reads the
answers of this service as the service means them and takes nothing else for a verdict. It is for this service and
ClamAV only; other servers keep the default rules.

```php
use Ndrstmr\Icap\CicapVerdict;

$client = new IcapClient($config, new AsyncAmpTransport(), new RequestFormatter(), new ResponseParser(), verdict: new CicapVerdict('/avscan'));
// or, with the synchronous transport: IcapClient::forServer('icap.example', 1344, new CicapVerdict('/avscan'))

$result = $client->scanFileWithPreview('/avscan', $path)->await(); // isInfected(), getVirusName()
```

The verdict is made for one service, named as a scan names it (path and query, compared as they are; the endpoint is
the one of the `Config`). While the mode is on the client scans with no other: another service is an
`InvalidArgumentException` before a file or a connection is touched. Use one client per service.

Every way to ask for a verdict goes through it: `request()`, `scanFile()`, `scanFileWithPreview()`, their
`…DiscardingBody()` variants and the synchronous and retrying wrappers. It does not use the header list of the `Config`,
and it refuses a preview strategy of your own (`InvalidArgumentException`), which could decide before it. The helpers
send `Allow: 204`. `request()` sends what you put in the request (add it) and takes the request for the whole message
unless it is the start of a longer one: a `Preview` field and no `ieof` (`IcapRequest::isPartialPreview()`).

| Answer of the service | Result |
|---|---|
| `204` to a file that was sent whole (the preview held it, or `100 Continue` and the rest) | clean |
| `200` or `206` with infection headers, also to a preview | infected; the threat is the name in the headers (`Eicar-Signature`), not the text of the header. A signature or a heuristic other than the next row is a finding, also next to those names |
| `200` or `206` whose only names are `Heuristics.Limits.Exceeded…` or `Heuristics.Encrypted…` (ClamAV saying that it did not look into the file: `AlertExceedsMax`, `AlertEncrypted`) | `IcapProtocolException` |
| `204` to the preview of a file that is not all sent | `IcapProtocolException`; the rest of the file is not sent |
| `200` or `206` without infection headers (the message sent back, a block or error page) | `IcapProtocolException`; for a page, its HTTP status (`IcapResponse::$encapsulatedStatus`) is in the message |
| infection headers that contradict each other or a `204`, or that are malformed; also on a `100 Continue` | `IcapProtocolException`; the rest of the file is not sent |
| `100` outside a preview, `4xx`, `5xx` (e.g. `500` while clamd is down) | as in every mode |

## What the service has to do

The answers cannot show whether the service scanned a file: it answers a small file of a type it skips with the same
`204` as for a clean scan, and the client does not know which service it talks to. It scans with the one you name and
rejects the answers that are distinguishable; that the service is configured like the reference is for you. The
reference configuration (`tests/Integration/reference/`) sets what the verified mode relies on:

| Setting | Without it |
|---|---|
| `ServiceAlias avscan virus_scan?…&force=on` | Only the types in `ScanFileTypes` are scanned (observed): a PDF with EICAR in it ends as `204`; a larger one gets a `204` at once to its preview, and without a preview the message sent back or a `204`, depending on how the request arrives. `sizelimit=off` and `mode=simple` are in the alias, too: objects over `MaxObjectSize` and early data are not wanted. |
| `AlertExceedsMax yes` (`clamd.conf`) | A file over `MaxFileSize` (100 MiB by default), `MaxScanSize` or `MaxRecursion` is not scanned and ends as `204` (observed): a 101 MiB archive with EICAR passes. With it the service reports `Heuristics.Limits.Exceeded.MaxFileSize` and the like, which the mode takes for an error. Other limits of ClamAV are not covered by it (ClamAV documentation). |
| `AlertEncrypted yes` | An encrypted archive with EICAR ends as `204` (observed). With it: `Heuristics.Encrypted.Zip`, an error as well. |
| `PassOnError off` (the default) | The module lets the content pass when the engine fails (module documentation), where it answers `500` now (observed with clamd down). |
| no ICAP service without a working engine (`start.sh`, `healthcheck.sh`) | c-icap started without clamd answers `204`, or sends the message back, to everything, EICAR included (observed). Its `ISTag` is then `"CI0001-1B2M2Y8AsgTpgAmY7PhCfgAA"` (observed; the ISTag of a service with an engine changes with the ClamAV version and signature version), which a monitor can alert on. |

The limits in the reference `clamd.conf` are small so that the tests can exceed them with small files; choose your own.
A clean answer means that ClamAV found no signature in what it could inspect within its limits. The mode does not look
at the payload, does not know the health of the engine and has been verified for the service below only.

## Reference service

`docker compose up -d` starts it; the image is pinned by digest (`docker-compose.yml`). Versions, signature state and
how to reproduce them are in [`tests/Integration/README.md`](../tests/Integration/README.md); the answers recorded from it
are in `tests/Fixtures/cicap-virus-scan/`.
