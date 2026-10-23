# Async — amphp v3 / Revolt — icap-flow

icap-flow nutzt **amphp v3** (`amphp/socket: ^2.3`) und **Revolt EventLoop**. Symfony HttpClient kommt **nicht** zum Einsatz — der Vergleichscode aus dem Lotse-Workspace gilt hier ausdrücklich nicht. Diese Datei erklärt die Patterns, die in `src/` aktiv sind.

## Mental-Model

- `Amp\Future<T>` ist das Promise-Äquivalent — `await()` blockiert die aktuelle Fiber, bis der Wert da ist.
- `\Amp\async(fn (): T => ...)` startet eine neue Fiber und gibt ein `Future<T>` zurück. So entstehen alle public-async-Methoden im `IcapClient`.
- `Amp\Cancellation` ist der zentrale Abbruch-Mechanismus. Jede public Methode des `IcapClient` nimmt ein optionales `?Cancellation` als letzten Parameter — Patches müssen dieses Muster halten.
- Cancellation des Aufrufers und Library-Timeout bilden je IO-Phase (Erwerb in `openSession()`, `write()`, `readResponse()`) ein Fenster: ein `DeferredCancellation`, ausgelöst von `EventLoop::delay()` (`streamTimeout`) und einer Subscription auf der User-Cancellation. Der Cancel des Aufrufers bleibt `Amp\CancelledException`, das eigene Fenster ist `IcapTimeoutException`.

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
2. **Logging-Sandwich**: `info` beim Start, `warning` im `catch`, `info` beim Erfolg. Keys `method`, `port`, `statusCode`, `infected`, im Fehlerfall `exception_class`; nie URI, Host, Exception-Message oder Throwable. Vorbild: `IcapClient::request()` mit `logContext()` und `logFailure()`.
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

In `AsyncAmpTransport::openSession()` (Erwerb: Pool, Connect, TLS) und in `AmpTransportSession::inWindow()` (für `write()` und `readResponse()`) fließen Library-Timeout und User-Abbruch zu einem Fenster zusammen. Timer und Subscription werden in `finally` ausdrücklich entfernt: `TimeoutCancellation` und `CompositeCancellation` räumen erst im Destruktor auf, und eine gehaltene Exception hält die Argumente ihrer Frames, damit beide, am Leben.

```php
$cancellation?->throwIfRequested();       // vorab gecancelt: Pool und Connector bleiben unberührt
$endsAt = hrtime(true) + $config->getStreamTimeout() * 1e9;
$window = new DeferredCancellation();
$expired = false;
$deadline = EventLoop::delay($config->getStreamTimeout(), function () use (&$expired, $window): void {
    $expired = true;
    $window->cancel();
});
$watch = $cancellation?->subscribe(static function () use ($window): void {
    $window->cancel();
});
$socket = null;

try {
    $socket = $this->acquireSocket($config, $window->getCancellation());

    // Ein Connector muss den Token nicht beachten; blockiert er den Event-Loop, feuert der Timer nicht.
    $cancellation?->throwIfRequested();
    if (hrtime(true) >= $endsAt) {
        $expired = true;
        $window->cancel();
    }
    $window->getCancellation()->throwIfRequested();
} catch (\Throwable $e) {
    $socket?->close();                    // ein nach Abbruch gelieferter Socket wird nicht benutzt
    if ($e instanceof CancelledException) {
        $cancellation?->throwIfRequested();   // der Cancel des Aufrufers bleibt, wie er ist
        if ($expired) {
            throw new IcapTimeoutException(/* ... */);
        }
    }
    throw $e;
} finally {
    EventLoop::cancel($deadline);
    if ($cancellation !== null && $watch !== null) {
        $cancellation->unsubscribe($watch);
    }
}
```

**Per-IO, nicht Session-Lifetime:** Jeder `write()`- und `readResponse()`-Aufruf bekommt ein neues Fenster; bei `write()` gilt es für alle Chunks des Aufrufs und wird dort, wo der Producer die Kontrolle zurückgibt (jeder Chunk, auch ein leerer; nach jedem Write; vor der Rückgabe), zusätzlich an einer monotonen Endzeit geprüft, weil der Timer nicht feuert, solange ein Producer den Event-Loop hält. `Socket::write()` hat keinen Cancellation-Parameter: ein unter Backpressure festhängender Write endet nur, indem die Session (der Socket) geschlossen wird. Ein Aufruf, der nicht fertig wird, schließt die Session dauerhaft; `release()` danach poolt nichts.

## Sessions — Strict-§4.5-Preview-Continue

`SessionAwareTransport` ist die Voraussetzung dafür, dass Preview und Continuation auf demselben Socket leben. Lebenszyklus einer Session:

```php
$session = $this->transport->openSession($this->config, $cancellation);
$reusable = false;   // Standard: schließen
try {
    $session->write($this->formatter->format($previewRequest));
    $previewIcapResponse = $this->parser->parse($session->readResponse());
    $serverCloses = HeaderFields::hasToken($previewIcapResponse->headers, 'Connection', 'close');

    if ($previewIsComplete) {
        $result = $this->interpretResponse($previewIcapResponse, $this->config);
        $reusable = !$serverCloses;
        return $result;
    }

    $decision = $this->previewStrategy->handlePreviewResponse($previewIcapResponse);

    if ($decision !== PreviewDecision::CONTINUE_SENDING) {
        $result = new ScanResult(/* ... */);
        $reusable = !$serverCloses;
        return $result;
    }

    // §4.5 continuation: NUR die Chunked-Body-Reste, kein neuer ICAP-Head.
    $session->write((new ChunkedBodyEncoder())->encodeRemainderFromStream($stream));

    $finalIcapResponse = $this->parser->parse($session->readResponse());
    $result = $this->interpretResponse($finalIcapResponse, $this->config);
    $reusable = !$serverCloses
        && !HeaderFields::hasToken($finalIcapResponse->headers, 'Connection', 'close');
    return $result;
} finally {
    $reusable ? $session->release() : $session->close();
}
```

Faustregeln:
- `release()` erst nach geprüftem Frame, erlaubtem Status/Verdikt und ohne `close`-Token in `Connection` einer Antwort des Austauschs.
- Jeder Exception-Pfad und jeder nicht freigegebene Austausch endet in `close()`. Ein halb gesprochener Socket darf den nächsten Pool-User nicht sehen.
- `release()` ist der letzte Schritt: danach ist die Session beendet, ein `close()` im Fehlerpfad wäre wirkungslos.

## Streaming, kein Buffering

`RequestFormatter::format(): iterable<string>` liefert drei Phasen:
1. ICAP-Head + Encapsulated-Offsets
2. encapsulated HTTP-Header-Block
3. Chunked-Body (HTTP/1.1) inkl. Terminator (`0\r\n\r\n` regulär, `0; ieof\r\n\r\n` bei Preview-Complete).

Niemals den Body in einen einzigen String puffern. `scanFile()` öffnet die Datei innerhalb des Futures (`fopen()`, dann `fstat()` am Handle), reicht den Stream als `body` in den `HttpResponse` und lässt den Encoder daraus Chunks ziehen; den eigenen Handle schließt es in `finally`. Übergebene Streams liest der Client nur. Der Encoder terminiert (`0\r\n\r\n` / `0; ieof`) erst nach bestätigtem EOF; Lesefehler, leerer Read vor dem Ende oder ein nötiger, fehlgeschlagener Rewind werfen. Kündigt die Nachricht eine einzelne `Content-Length` an, muss der Stream genau so viele Bytes liefern (der Formatter reicht sie an den Encoder), sonst wirft er vor dem Abschlusschunk (außer bei einem Teil-Preview: `Preview` ohne `ieof`).

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

**Pool-Key:** `AmpConnectionPool::bucketOf()` hängt ein vom Pool vergebenes, nie wiederverwendetes Token je `ClientTlsContext`-Objekt an (`\WeakMap`, B07). Gleiche Objekte teilen Sockets, verschiedene nie — auch nicht, nachdem das Original freigegeben wurde.

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
- LIFO pro Bucket (warmer Socket zuerst).
- `acquire()` reserviert vor dem Connect Platz (Endpoint-Cap `maxConnectionsPerHost` und Gesamtcap `maxConnections` über verbindende, aktive und idle Sockets) und wirft `IcapConnectionException`, wenn der Pool voll ist und keine Queue (`maxWaiters`, `waitTimeout`) konfiguriert ist. Einen Idle-Socket liest es vor der Ausgabe einmal (Bytes oder EOF schließen ihn).
- `release()` nimmt nur verliehene Sockets zurück und nur für ihren Bucket; Fremdsockets, ein anderer Endpoint/TLS-Kontext und ein Socket über einem gesenkten Cap werden geschlossen. Ein zweites Release im Idle-Zustand ändert nichts (ein Eintrag, eine Ausgabe); ein spätes nach erneuter Ausgabe ist ohne Lease-Token nicht vom Release des neuen Halters zu unterscheiden (die Session schützt sich mit `disposed`).

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

`SynchronousStreamTransport` nutzt `stream_socket_client` und blockt direkt. Jeder Chunk geht ganz hinaus oder der Aufruf wirft; ein abgelaufenes Stream-Timeout ist `IcapTimeoutException`, nie ein EOF. Der Request geht über einen nicht blockierenden Socket hinaus (`stream_select()` mit Frist je Stillstand), das Lesen blockierend mit Stream-Timeout; ein laufender Wait ist durch den Cancel des Aufrufers nicht unterbrechbar (siehe CLAUDE.md). `SynchronousIcapClient` ist nur ein `await()`-Wrapper:

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
| `DeferredCancellation` + `EventLoop::delay()` | User-Abbruch + Lib-Fenster je IO-Phase | `AsyncAmpTransport::openSession()`, `AmpTransportSession::inWindow()`, `Config::getStreamTimeout()` |
| `SessionAwareTransport` | Multi-IO auf einem Socket | Strict-§4.5-Pfad |
| `Socket\connect()` / `connectTls()` | Plain / TLS | `AsyncAmpTransport::acquireSocket()` |
| `\Amp\delay()` | Cancellation-aware Sleep | `RetryingIcapClient`-Backoff |
| Symfony HttpClient | — | **nicht verwendet** in icap-flow |
| ReactPHP / Swoole | — | **nicht verwendet** |
