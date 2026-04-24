# Migration guide: v1 → v2

`v2.0.0` is a deliberately breaking release. v1 had RFC-3507-blocking bugs in the wire format and a fail-open security bug in the response interpreter; closing both required wire-format and API surface changes that no semver-friendly minor could have shipped.

This guide walks through every API break and shows the v1 → v2 mapping.

---

## TL;DR

```diff
-use Ndrstmr\Icap\Config;
-use Ndrstmr\Icap\IcapClient;
-use Ndrstmr\Icap\DTO\IcapRequest;
+use Ndrstmr\Icap\Config;
+use Ndrstmr\Icap\IcapClient;
+use Ndrstmr\Icap\DTO\HttpResponse;
+use Ndrstmr\Icap\DTO\IcapRequest;

-$req = new IcapRequest('RESPMOD', $uri, [], $fileBody);
+$req = new IcapRequest(
+    method: 'RESPMOD',
+    uri:    $uri,
+    encapsulatedResponse: new HttpResponse(
+        statusCode: 200,
+        headers:    ['Content-Type' => ['application/octet-stream'], 'Content-Length' => [(string) $size]],
+        body:       $fileBody,
+    ),
+);
```

For the common case — `scanFile()` and `scanFileWithPreview()` — you don't need to construct anything by hand. The high-level methods do it for you, and the only surface-level change is an optional new `array $extraHeaders = []` parameter.

---

## 1. PHP version

- **v1**: `php >= 8.3`
- **v2**: `php ^8.4`

The CI matrix runs 8.4 + 8.5. If you can't move off 8.3, stay on `^1.0`.

---

## 2. `IcapRequest`: `mixed $body` is gone

v1 stored a freeform body on `IcapRequest`. v2 splits the encapsulated payload into typed slots that match RFC 3507 semantics:

```diff
-public function __construct(
-    public string $method,
-    public string $uri = '/',
-    array  $headers = [],
-    public mixed  $body = '',
-) { ... }
+public function __construct(
+    public string         $method,
+    public string         $uri = '/',
+    array                 $headers = [],
+    public ?HttpRequest   $encapsulatedRequest  = null,  // REQMOD payload
+    public ?HttpResponse  $encapsulatedResponse = null,  // RESPMOD payload
+    public bool           $previewIsComplete    = false, // emit `0; ieof\r\n\r\n`
+) { ... }
```

Two new DTOs you'll use to construct payloads:

```php
namespace Ndrstmr\Icap\DTO;

final readonly class HttpRequest
{
    public function __construct(
        public string $method,
        public string $requestTarget = '/',
        /** @var array<string, string[]> */ public array $headers = [],
        public mixed  $body = null,                      // string | resource | null
        public string $httpVersion = 'HTTP/1.1',
    ) {}
}

final readonly class HttpResponse
{
    public function __construct(
        public int    $statusCode,
        public string $reasonPhrase = 'OK',
        /** @var array<string, string[]> */ public array $headers = [],
        public mixed  $body = null,                      // string | resource | null
        public string $httpVersion = 'HTTP/1.1',
    ) {}
}
```

If you only ever called `scanFile()` / `scanFileWithPreview()`, you don't construct these by hand — `IcapClient` synthesises a minimal `HttpResponse` envelope around the file for you.

---

## 3. `RequestFormatterInterface::format()` returns `iterable<string>`

v1 returned a single `string`. v2 returns chunks so the transport can stream them onto the socket without buffering multi-GB bodies.

```diff
-public function format(IcapRequest $request): string;
+public function format(IcapRequest $request): iterable;
```

If you implemented your own formatter, switch to `yield`:

```php
public function format(IcapRequest $request): iterable
{
    yield $this->renderHead($request);
    if ($body !== null) {
        yield from $this->chunkBody($body);
    }
}
```

---

## 4. `TransportInterface::request()` accepts `iterable<string>`

```diff
-public function request(Config $config, string $rawRequest): \Amp\Future;
+public function request(Config $config, iterable $rawRequest): \Amp\Future;
```

Transports iterate the chunks and write each one to the socket as it arrives.

---

## 5. `IcapClient::scanFile()` / `scanFileWithPreview()` got a trailing `$extraHeaders`

```diff
-public function scanFile(string $service, string $filePath): Future;
-public function scanFileWithPreview(string $service, string $filePath, int $previewSize = 1024): Future;
+public function scanFile(
+    string $service,
+    string $filePath,
+    array  $extraHeaders = [],
+): Future;
+public function scanFileWithPreview(
+    string $service,
+    string $filePath,
+    int    $previewSize = 1024,
+    array  $extraHeaders = [],
+): Future;
```

Extra headers are caller-controlled metadata like `X-Client-IP`, `X-Authenticated-User`, `X-Server-IP`. Header names and values are validated for CR/LF/NUL/control characters. Library-managed headers (`Encapsulated`, `Host`, `Connection`, `Preview`, `Allow`) always win.

Existing call sites with two positional args still work — the parameter is optional.

---

## 6. `Config` constructor + new accessors

The constructor signature grew. The legacy `string $virusFoundHeader` parameter is still accepted for back-compat, but the canonical accessor is now `getVirusFoundHeaders(): list<string>`.

```php
$config = (new Config(
    host: 'icap.example.com',
    port: 11344,
    socketTimeout: 5.0,
    streamTimeout: 30.0,
))
    ->withTlsContext(new ClientTlsContext('icap.example.com'))
    ->withVirusFoundHeaders(['X-Virus-Name', 'X-Infection-Found', 'X-Violations-Found'])
    ->withLimits(maxResponseSize: 10 * 1024 * 1024, maxHeaderCount: 100, maxHeaderLineLength: 8192);
```

If you only used `new Config('host')` / `withVirusFoundHeader()`, your code keeps working as-is.

---

## 7. `IcapClient` constructor: optional logger param

```diff
 public function __construct(
     Config                       $config,
     TransportInterface           $transport,
     RequestFormatterInterface    $formatter,
     ResponseParserInterface      $parser,
     ?PreviewStrategyInterface    $previewStrategy = null,
+    ?Psr\Log\LoggerInterface     $logger          = null,
 );
```

Defaults to `Psr\Log\NullLogger`. Three structured events per `request()` call: `ICAP request started`, `ICAP request completed`, `ICAP request failed`.

---

## 8. Status-code mapping changed (security fix)

v1's `interpretResponse` mapped status `100 Continue` to `ScanResult(isInfected: false)` — a **fail-open** bug. If a 100 ever leaked outside the preview flow (race, partial read, server bug), malware would have passed as clean.

v2 throws the right exception for every status it doesn't expect:

| Status | v1 behaviour | v2 behaviour |
|---|---|---|
| 100 outside preview | `ScanResult(clean)` | `IcapProtocolException` |
| 4xx | `IcapResponseException` | `IcapClientException(code)` |
| 5xx | `IcapResponseException` | `IcapServerException(code)` |
| 206 | `IcapResponseException` | inspect virus header (200-style) |

If you `catch (IcapResponseException $e)` you now want `catch (IcapExceptionInterface $e)` to capture the new richer types in one block.

---

## 9. CRLF guard on `$service`

v1 happily concatenated whatever you passed as `$service` into the request URI. v2 rejects CR/LF/NUL/control/whitespace with `InvalidArgumentException` before any byte is written. If you're passing `$service` through from untrusted input you may need to handle the new exception.

---

## 10. Test surface

If you're vendoring tests:
- `tests/RequestFormatterTest.php` was deleted (it asserted the broken wire format and blocked the fix).
- Wire-format expectations now live in `tests/Wire/RequestFormatterWireTest.php` with hand-computed RFC-3507 byte fixtures.
- Integration tests are gated behind `ICAP_HOST` env vars and skip by default.

---

## 11. New runtime deps

```diff
 "require": {
     "php": "^8.4",
     "amphp/socket": "^2.3",
+    "psr/log": "^3.0",
+    "revolt/event-loop": "^1.0"
 }
```

`revolt/event-loop` was a transitive in v1 but the source code already used it — v2 declares it explicitly so you can rely on it.

---

## Need help?

- The full per-finding closure list is in [`docs/review/consolidated_task-list.md`](review/consolidated_task-list.md).
- Open an issue if you hit something not covered here — please include the v1 code and the migration target.
