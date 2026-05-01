# Migration guide: v2 → v3

`v3.0.0` is a deliberately small breaking release. It carries no new features and no architectural rework — it is a cleanup release whose sole purpose is to remove three known-stale corners of the v2 API so the surface can be frozen for the upcoming Symfony bundle (`ndrstmr/icap-flow-bundle`).

If you are on `^2.2` and not subclassing `IcapClient`, not catching `IcapResponseException` directly, and not consuming `options()` as a `ScanResult`, **the upgrade is a no-op other than bumping the constraint to `^3.0`**.

The three BC-breaks below resolve the v3-V, v3-W and v3-F items from the consolidated v2.1 task list (`docs/review/review_v2-1/consolidated_v2.1_task-list.md`).

---

## TL;DR

```diff
 composer require ndrstmr/icap-flow:^3.0
```

```diff
-$result = $client->options('/avscan')->await();
-$preview = $result->getOriginalResponse()->headers['Preview'][0] ?? null;
+$result = $client->options('/avscan')->await();
+$preview = $result->headers['Preview'][0] ?? null;
```

```diff
-try { ... }
-catch (\Ndrstmr\Icap\Exception\IcapResponseException $e) { ... }
+try { ... }
+catch (\Ndrstmr\Icap\Exception\IcapProtocolException $e) { ... }
+// or, if you don't need to discriminate:
+// catch (\Ndrstmr\Icap\Exception\IcapExceptionInterface $e) { ... }
```

```diff
-$client->executeRaw($req)->await();
+$client->request($req)->await();
+// or scanFile() / scanFileWithPreview() / options()
+// — executeRaw() is now protected and only callable from a subclass
```

---

## 1. PHP version

- **v2.2**: `php ^8.4`
- **v3.0**: `php ^8.4`

No PHP-version change. CI matrix continues to run 8.4 + 8.5.

---

## 2. `IcapClient::executeRaw()` is now `protected` (v3-V)

`executeRaw()` was always intended as an internal seam for the preview flow, where `100 Continue` is a legitimate intermediate response. Keeping it `public` let external callers bypass the fail-secure status-code interpretation in `interpretResponse()` — silently turning an unexpected status into a clean verdict, which defeats the library's main safety guarantee. The method was never part of `IcapClientInterface`, so the public contract is unchanged.

```diff
-public function executeRaw(IcapRequest $request, ?Cancellation $cancellation = null): Future
+protected function executeRaw(IcapRequest $request, ?Cancellation $cancellation = null): Future
```

### Migration

If you were calling `executeRaw()` directly from outside the class:

```diff
-$response = $client->executeRaw($req)->await();
-if ($response->statusCode === 200) { ... }
+$result = $client->request($req)->await();
+// $result is a ScanResult; for the raw IcapResponse use ->getOriginalResponse()
```

For the high-level operations, use the dedicated methods:

```diff
-$client->executeRaw(new IcapRequest('OPTIONS', $uri))->await();
+$client->options('/avscan')->await();
```

If you genuinely need raw access (vendor-specific extensions, custom preview strategies), subclass `IcapClient` — `protected` lets you keep calling or overriding the method:

```php
final class MyVendorClient extends IcapClient
{
    public function customScan(IcapRequest $req): IcapResponse
    {
        return $this->executeRaw($req)->await();
    }
}
```

---

## 3. `IcapClient::options()` returns `Future<IcapResponse>` (v3-W)

OPTIONS is a capability-discovery method (RFC 3507 §4.10). Wrapping the response in `ScanResult` — which is modelled around `isInfected()` / `getVirusName()` — was always semantically wrong. Real callers reached for `$result->getOriginalResponse()->headers` to read the negotiated `Preview`, `Options-TTL`, `Methods`, `Allow`, `Service`, `ISTag`, `Max-Connections` values; the wrapper was friction, not safety.

| Symbol | Before (v2.x) | After (v3.0) |
|---|---|---|
| `IcapClient::options()` | `Future<ScanResult>` | `Future<IcapResponse>` |
| `SynchronousIcapClient::options()` | `ScanResult` | `IcapResponse` |
| `RetryingIcapClient::options()` | `Future<ScanResult>` | `Future<IcapResponse>` |
| `IcapClientInterface::options()` | `Future<ScanResult>` | `Future<IcapResponse>` |

### Fail-secure semantics are unchanged

`interpretResponse()` used to inline both the success-mapping (204/200/206 → `ScanResult`) and the failure-mapping (100/4xx/5xx → typed exception). The failure branches are now extracted into a dedicated `assertSuccessfulStatus()` helper that both `interpretResponse()` (for scans) and `options()` (for capability discovery) call:

- 100 Continue outside the preview flow → `IcapProtocolException`
- 4xx → `IcapClientException` (with the real status code)
- 5xx → `IcapServerException` (with the real status code)
- 200/204/206 OK → raw `IcapResponse` is returned to the caller

The OPTIONS-cache contract (`OptionsCacheInterface`) is unchanged — it always stored `IcapResponse`. The cache-hit path now skips the `interpretResponse()` round-trip and applies `assertSuccessfulStatus()` directly.

### Migration

```diff
-$result = $client->options('/avscan')->await();
-$headers = $result->getOriginalResponse()->headers;
-$preview = (int) ($headers['Preview'][0] ?? 1024);
+$result = $client->options('/avscan')->await();
+$preview = (int) ($result->headers['Preview'][0] ?? 1024);
```

If you were doing `$result->isInfected()` on an OPTIONS response: that never made sense — OPTIONS is not a scan. Remove the call. If you genuinely need a "is this a clean scan" check, send a `RESPMOD` via `request()` / `scanFile()` instead.

The `RetryingIcapClient::withRetry()` helper was generalised with `@template T` so the same retry harness now carries both `ScanResult` (for `request`/`scanFile*`) and `IcapResponse` (for `options`) without losing its return-type generic.

---

## 4. `IcapResponseException` is removed (v3-F)

`Ndrstmr\Icap\Exception\IcapResponseException` has been deprecated since v2.0 (with the PHP 8.4 `#[\Deprecated]` attribute since v2.2). It only ever served as a catch-all bucket for status codes outside the recognised taxonomy — that is exactly what `IcapProtocolException` represents.

Both throw sites moved to `IcapProtocolException`:

- **`IcapClient::interpretResponse()` fail-secure backstop.** The previous comment claimed `assertSuccessfulStatus()` always throws and the line was unreachable; that was wrong. `assertSuccessfulStatus()` only throws for 100/4xx/5xx, leaving 1xx-other-than-100, 3xx and 6xx+ as a real fall-through. The backstop is genuine fail-secure code.
- **`DefaultPreviewStrategy::handlePreviewResponse()` `default` branch.** Same semantics as before; the new exception type carries the offending status code as `->getCode()`.

### Migration

If you catch the exception explicitly:

```diff
-try {
-    $client->scanFile('/avscan', $path);
-} catch (\Ndrstmr\Icap\Exception\IcapResponseException $e) {
-    // ...
-}
+try {
+    $client->scanFile('/avscan', $path);
+} catch (\Ndrstmr\Icap\Exception\IcapProtocolException $e) {
+    // ...
+}
```

If you catch via the marker interface (`IcapExceptionInterface`), no change is needed — `IcapProtocolException` already implements it, and so does every other concrete exception in the taxonomy.

The full v3.0 exception taxonomy:

| Status / failure | Exception |
|---|---|
| 100 outside preview flow | `IcapProtocolException` |
| 4xx | `IcapClientException` |
| 5xx | `IcapServerException` |
| 1xx-other-than-100, 3xx, 6xx+ | `IcapProtocolException` (was `IcapResponseException`) |
| malformed response bytes | `IcapMalformedResponseException extends IcapProtocolException` |
| TCP / TLS / connect failure | `IcapConnectionException` |
| stream cancellation timed out | `IcapTimeoutException` |

All seven (six concrete + the marker) implement `IcapExceptionInterface`, so a single catch on the marker still captures everything.

---

## 5. What did *not* change

To set expectations: the following all stay byte-compatible between v2.2 and v3.0.

- ICAP wire format — same `RequestFormatter`, same `ResponseParser`, same chunked-body encoding, same `0; ieof` preview terminator.
- Connection pool API (`ConnectionPoolInterface`, `AmpConnectionPool`, `NullConnectionPool`).
- TLS/mTLS setup via `Config::withTlsContext()`.
- Per-IO timeout model (introduced in v2.2).
- OPTIONS cache contract (`OptionsCacheInterface`, `InMemoryOptionsCache`, `Psr6OptionsCache`, `Psr16OptionsCache`).
- ISTag-based cache invalidation (introduced in v2.2).
- Multi-vendor virus-header detection (`Config::withVirusFoundHeaders()`).
- DoS limits (`Config::withLimits()`).
- PSR-3 logger integration and the structured-logging schema.
- DTO shapes (`IcapRequest`, `IcapResponse`, `HttpRequest`, `HttpResponse`, `ScanResult`).
- `RetryingIcapClient` retry policy (5xx only, exponential backoff).
- amphp v3 / Revolt event-loop integration.

The `RetryingIcapClient::withRetry()` internal helper picked up an `@template T` so `options()` can return `IcapResponse` through it; the public `RetryingIcapClient::options()` signature inherits from `IcapClientInterface` and is correctly typed without explicit annotations.

---

## 6. Test impact

If you vendor tests against the library:

- Tests that asserted `$client->options(...)->isInfected()` — replace with raw-response inspection (`$response->statusCode`, `$response->headers[...]`) or move the scan-semantics assertion to `request()` / `scanFile()`.
- Tests that asserted `IcapResponseException` — switch to `IcapProtocolException` (or the marker interface).
- The deprecation-warning lines from v2.x (two per test run) are gone in v3 — your `phpunit.xml.dist` `failOnRisky` / `failOnDeprecation` settings may surface this as a behavioural change.

---

## 7. Provenance

`docs/review/review_v2-1/consolidated_v2.1_task-list.md` lists the three v3 items (v3-V, v3-W, v3-F) with reviewer attribution (Claude, Codex, four independent audits). Each PR that closed an item is recorded against the corresponding line:

- v3-V → PR #85 (`feat(IcapClient)!: make executeRaw() protected`)
- v3-W → PR #86 (`feat(IcapClient)!: return IcapResponse from options()`)
- v3-F → PR #87 (`refactor(exception)!: remove deprecated IcapResponseException`)

The next deep-research audit cycle (Claude, Codex, Jules) runs against `v3.0.0` using the prompt at `docs/review/deep-research-prompt-icapflow_v3.0.md`.

---

## Need help?

- The v1→v2 migration guide is at [`docs/migration-v1-to-v2.md`](migration-v1-to-v2.md).
- Open an issue if you hit something not covered here — please include the v2.x code, the v3 migration target, and the exception class (if any) you're trying to catch.
