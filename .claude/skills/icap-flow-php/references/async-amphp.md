# Async — amphp v3 / Revolt — icap-flow

icap-flow nutzt **amphp v3** (`amphp/socket: ^2.3`) und **Revolt EventLoop**. Symfony HttpClient kommt **nicht** zum Einsatz — der Vergleichscode aus dem Lotse-Workspace gilt hier ausdrücklich nicht. Diese Datei erklärt die Patterns, die in `src/` aktiv sind.

## Mental-Model

- `Amp\Future<T>` ist das Promise-Äquivalent — `await()` blockiert die aktuelle Fiber, bis der Wert da ist.
- `\Amp\async(fn (): T => ...)` startet eine neue Fiber und gibt ein `Future<T>` zurück. So entstehen alle public-async-Methoden im `IcapClient`.
- `Amp\Cancellation` ist der zentrale Abbruch-Mechanismus. Jede public Methode des `IcapClient` nimmt ein optionales `?Cancellation` als letzten Parameter — Patches müssen dieses Muster halten.
- `CompositeCancellation` kombiniert User-Cancellation und Library-internes `TimeoutCancellation`; was zuerst feuert, gewinnt.

## Standardsignatur einer öffentlichen Async-Methode

```php
// [EUPL-Header]
declare(strict_types=1);

namespace Ndrstmr\Icap;

use Amp\Cancellation;
use Amp\Future;

#[\Override]
public function options(string $service, ?Cancellation $cancellation = null): Future
{
    /** @var Future<ScanResult> $future */
    $future = \Amp\async(function () use ($service, $cancellation): ScanResult {
        // ... Orchestrierung, await(), interpretResponse()
        return $result;
    });

    return $future;
}
```

Drei Regeln, die bei jeder neuen Async-Methode greifen:
1. **Return-Type-Annotation `Future<X>` per PHPDoc**, da PHP keine generischen Native-Types kennt — PHPStan Level 9 mahnt sonst.
2. **Logging-Sandwich**: `info` beim Start, `warning` im `catch`, `info` beim Erfolg. Keys `method`, `uri`, `host`, `port`, `statusCode`, `infected`. Vorbild: `IcapClient::request()` Z. 100-134.
3. **Try/Finally für Resource-Lifecycle** — Streams schließen, Sessions zurückgeben (`release()`) oder hart schließen (`close()`).

## Cancellation richtig durchreichen

`Cancellation` reicht **immer** bis zum Socket runter. Niemals abschneiden, weil die Methode "nur kurz" ist.

```php
public function executeRaw(IcapRequest $request, ?Cancellation $cancellation = null): Future
{
    /** @var Future<IcapResponse> $future */
    $future = \Amp\async(function () use ($request, $cancellation): IcapResponse {
        $chunks         = $this->formatter->format($request);
        $responseString = $this->transport->request($this->config, $chunks, $cancellation)->await();

        return $this->parser->parse($responseString);
    });

    return $future;
}
```

In `AsyncAmpTransport::openSession()` werden User-Cancellation und `TimeoutCancellation` zu einem `CompositeCancellation` verschmolzen — das ist die Stelle, an der Library-Timeout und User-Abbruch zusammenfließen:

```php
$timeoutCancellation = new TimeoutCancellation($config->getStreamTimeout());
$effective = $cancellation === null
    ? $timeoutCancellation
    : new CompositeCancellation($cancellation, $timeoutCancellation);

$socket = $this->acquireSocket($config, $effective);
```

**v2.2-Caveat (Item #19):** Im Strict-§4.5-Pfad ist dieses Timeout heute Session-Lifetime, nicht Per-IO. Beim Touch dieser Stelle entweder Per-IO-Reset einbauen **oder** den Caveat in der PHPDoc dokumentieren.

## Sessions — Strict-§4.5-Preview-Continue

`SessionAwareTransport` ist die Voraussetzung dafür, dass Preview und Continuation auf demselben Socket leben. Lebenszyklus einer Session:

```php
$session = $this->transport->openSession($this->config, $cancellation);
try {
    $session->write($this->formatter->format($previewRequest));
    $previewIcapResponse = $this->parser->parse($session->readResponse());

    if ($previewIsComplete) {
        $session->release();   // sauber zurück in den Pool
        return $this->interpretResponse($previewIcapResponse, $this->config);
    }

    $decision = $this->previewStrategy->handlePreviewResponse($previewIcapResponse);

    if ($decision !== PreviewDecision::CONTINUE_SENDING) {
        $session->release();   // 100/204-Pfad: Socket protokoll-sauber
        return new ScanResult(
            isInfected: $decision === PreviewDecision::ABORT_INFECTED,
            virusName: /* ... */,
            originalResponse: $previewIcapResponse,
        );
    }

    // §4.5 continuation: NUR die Chunked-Body-Reste, kein neuer ICAP-Head.
    $session->write((new ChunkedBodyEncoder())->encode($remainder));

    $finalIcapResponse = $this->parser->parse($session->readResponse());
    $session->release();
    return $this->interpretResponse($finalIcapResponse, $this->config);
} catch (\Throwable $e) {
    $session->close();   // off-script — Socket darf NIE in den Pool
    throw $e;
}
```

Faustregeln:
- `release()` nur, wenn der Austausch protokoll-sauber war **und** der Server kein `Connection: close` gesendet hat.
- Jeder `\Throwable`-Pfad endet in `close()`. Ein halb gesprochener Socket darf den nächsten Pool-User nicht sehen.

## Streaming, kein Buffering

`RequestFormatter::format(): iterable<string>` liefert drei Phasen:
1. ICAP-Head + Encapsulated-Offsets
2. encapsulated HTTP-Header-Block
3. Chunked-Body (HTTP/1.1) inkl. Terminator (`0\r\n\r\n` regulär, `0; ieof\r\n\r\n` bei Preview-Complete).

Niemals den Body in einen einzigen String puffern. Das `scanFile()`-Beispiel reicht den `fopen($path, 'rb')`-Stream als `body` in den `HttpResponse` und lässt den Encoder daraus Chunks ziehen.

**v2.1.2-Bug (Item #7):** Die heutige Strict-§4.5-Continuation in `IcapClient.php:399` macht `stream_get_contents($stream)` und puffert dadurch die Datei nach Preview komplett in RAM. Fix: `ChunkedBodyEncoder::encodeRemainderFromStream($stream)` ergänzen und den `stream_get_contents`-Call ersetzen. Memory-Watermark-Test (2-GB-Stream unter `php -d memory_limit=128M`) gehört in den gleichen PR.

## TLS — `icaps://`

`Config::withTlsContext(new ClientTlsContext($host))` schaltet auf TLS um. `AsyncAmpTransport` ruft dann `Socket\connectTls()` statt `Socket\connect()`:

```php
$context = (new ConnectContext())->withConnectTimeout($config->getSocketTimeout());
if ($tls !== null) {
    $context = $context->withTlsContext($tls);
    return Socket\connectTls($url, $context, $cancellation);
}
return Socket\connect($url, $context, $cancellation);
```

**v2.1.1-Bug (Item #2):** Der Pool-Key in `AmpConnectionPool::key()` enthält **nicht** den TLS-Context. Multi-Tenant mit unterschiedlichen Cert-Chains teilen sich heute den Stack — das ist eine Cross-Tenant-Leakage. Übergangs-Fix: `spl_object_hash($tls)` als Suffix; deterministischer Hash kommt v2.2.

## Connection-Pool

```php
final class AmpConnectionPool implements ConnectionPoolInterface
{
    /** @var array<string, list<SocketInterface>> */
    private array $idle = [];

    public function acquire(Config $config, Cancellation $cancellation): SocketInterface { /* ... */ }

    public function release(Config $config, SocketInterface $socket): void { /* ... */ }
}
```

Verhalten heute:
- LIFO pro Key (warmer Socket zuerst).
- `acquire()` verwirft offline-Sockets und macht ggf. einen frischen.
- `release()` schließt den Socket, wenn die Per-Host-Cap erreicht ist.

v2.2-Erweiterungen, die auf der Roadmap stehen:
- TLS-Fingerprint im Key (Hotfix in v2.1.1)
- `maxIdleAge` (Default 30 s) + Eviction-Sweep
- `Config::autoTunePoolFromOptions` — `effectiveCap = min(localCap, serverMaxConnections)` aus dem OPTIONS-Header

## Concurrency-Primitiven aus amphp

- `\Amp\async(fn (): T): Future<T>` — neuer Fiber-Job, sofort gestartet.
- `\Amp\delay(float $seconds, ?Cancellation): void` — Sleep, der Cancellation respektiert. Vorbild: `RetryingIcapClient`-Backoff.
- `Future::complete($value)` — bereits-gelöstes Future. Sinnvoll für Cache-Hits in `options()`.
- `Future::await()` — blockiert die aktuelle Fiber bis Resolve.
- `Amp\async()`-Closures dürfen **nicht** auf `$this->state` schreiben, ohne sich der Fiber-Re-Entrancy bewusst zu sein. In icap-flow sind alle Async-Methoden re-entrant-safe, weil `IcapClient` selbst stateless ist (`$transport`, `$formatter`, `$parser` werden nur gelesen).

## Synchroner Pfad

`SynchronousStreamTransport` nutzt `stream_socket_client` und blockt direkt. `SynchronousIcapClient` ist nur ein `await()`-Wrapper:

```php
public function scanFile(
    string $service,
    string $filePath,
    array $extraHeaders = [],
    ?Cancellation $cancellation = null,
): ScanResult {
    return $this->asyncClient->scanFile($service, $filePath, $extraHeaders, $cancellation)->await();
}
```

Der synchrone Pfad ist **nicht** `SessionAwareTransport`, also fällt `scanFileWithPreview()` dort auf `scanFileWithPreviewLegacy` zurück (zwei TCP-Handshakes, weniger effizient — aber RFC-konform für permissive Server).

## Quick-Reference

| Tool | Use case | Vorkommen in icap-flow |
|---|---|---|
| `\Amp\async()` | Public-Async-Methode | `IcapClient::request()`, `options()`, `scanFileWithPreview()` |
| `Future<T>::await()` | In Fiber blockierend warten | Fast alle async-Methoden |
| `Cancellation` Param | Externes Abbrechen | Pflicht-Last-Param public-API |
| `CompositeCancellation` | User + Lib-Timeout kombinieren | `AsyncAmpTransport::openSession()` |
| `TimeoutCancellation` | Lib-internes Streaming-Timeout | `AsyncAmpTransport`, `Config::getStreamTimeout()` |
| `SessionAwareTransport` | Multi-IO auf einem Socket | Strict-§4.5-Pfad |
| `Socket\connect()` / `connectTls()` | Plain / TLS | `AsyncAmpTransport::acquireSocket()` |
| `\Amp\delay()` | Cancellation-aware Sleep | `RetryingIcapClient`-Backoff |
| Symfony HttpClient | — | **nicht verwendet** in icap-flow |
| ReactPHP / Swoole | — | **nicht verwendet** |
