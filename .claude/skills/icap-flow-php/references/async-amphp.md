# Async — amphp v3 / Revolt — icap-flow

icap-flow nutzt **amphp v3** (`amphp/socket: ^2.3`, `amphp/file` für Dateiquellen) und **Revolt EventLoop**. Symfony HttpClient kommt **nicht** zum Einsatz. Diese Datei erklärt die Patterns, die in `src/` aktiv sind; die verbindlichen Regeln stehen in [AGENTS.md](../../../../AGENTS.md).

## Mental-Model

- `Amp\Future<T>` ist das Promise-Äquivalent — `await()` blockiert die aktuelle Fiber, bis der Wert da ist.
- `\Amp\async(fn (): T => ...)` startet eine neue Fiber und gibt ein `Future<T>` zurück. Die öffentlichen Methoden des `IcapClient` laufen so über `IcapClient::run()`.
- `Amp\Cancellation` ist der zentrale Abbruch-Mechanismus. Jede public Methode des `IcapClient` nimmt ein optionales `?Cancellation` als letzten Parameter — Patches müssen dieses Muster halten.
- Cancellation des Aufrufers und Library-Timeout bilden je IO-Phase (Erwerb in `openSession()`, `write()`, `readResponse()`) ein Fenster: ein `DeferredCancellation`, ausgelöst von `EventLoop::delay()` (`streamTimeout`; die Antwort: `responseStartTimeout` bis zum ersten Byte, dann `streamTimeout` als Höchstdauer; TLS-Handshake: `tlsHandshakeTimeout` in `SocketOpener`) und einer Subscription auf der User-Cancellation. Die User-Cancellation ist im Client die `OperationDeadline` des Aufrufs (`operationTimeout`): Ende der Operation und Cancel des Aufrufers laufen so durch dieselben Fenster, der Cancel gewinnt und bleibt `CancelledException`, das Ende ist `IcapTimeoutException`. Der Cancel des Aufrufers bleibt `Amp\CancelledException`, das eigene Fenster ist `IcapTimeoutException`. Ein `read()`, der seine Bytes ohne Suspendieren findet, gibt dem Timer keinen Loop-Turn: der Checkpoint prüft Caller und Uhr vor und unmittelbar nach jedem Read (auch bei EOF; beim ersten Read, bevor das Übertragungsfenster das Startfenster ersetzt) und vor der Rückgabe des Frames; `release()` poolt keinen Socket einer abgebrochenen oder abgelaufenen Operation.

## Standardsignatur einer öffentlichen Async-Methode

```php
#[\Override]
public function options(string $service, ?Cancellation $cancellation = null): Future
{
    $deadline = OperationDeadline::begin($cancellation, $this->config->getOperationTimeout());
    $uri = $this->buildServiceUri($service);   // prüfen, bevor ein Byte oder ein Cache-Key entsteht

    return $this->run($deadline, function (OperationDeadline $deadline) use ($uri): IcapResponse {
        // ... executeRaw($request, $deadline)->await(), $deadline->checkpoint(), assertOptionsStatus()
    });
}
```

Regeln, die bei jeder neuen Async-Methode greifen:
1. **Return-Type-Annotation `Future<X>` per PHPDoc**, da PHP keine generischen Native-Types kennt — PHPStan Level 9 mahnt sonst.
2. **Eine Endzeit je Aufruf**: `OperationDeadline::begin()` beim Aufruf, `run()` prüft die Uhr vor dem Rückgabewert und schließt die Deadline in `finally`.
3. **Logging-Sandwich**: `info` beim Start, `warning` im `catch`, `info` beim Erfolg. Keys `method`, `port`, `statusCode`, `infected`, im Fehlerfall `exception_class`; nie URI, Host, Exception-Message oder Throwable. Vorbild: `IcapClient::requestWith()` mit `logContext()` und `logFailure()`.
4. **Try/Finally für Resource-Lifecycle** — Dateien schließen (`close()`/`discard()`), Sessions zurückgeben (`release()`) oder hart schließen (`close()`).

## Cancellation richtig durchreichen

`Cancellation` reicht **immer** bis zum Socket runter. Niemals abschneiden, weil die Methode "nur kurz" ist. Im Client ist es die `OperationDeadline` des Aufrufs:

```php
protected function executeRaw(IcapRequest $request, ?Cancellation $cancellation = null, ?int $discardBudget = null): Future
{
    /** @var Future<IcapResponse> $future */
    $future = \Amp\async(function () use ($request, $cancellation, $discardBudget): IcapResponse {
        $chunks = $this->formatter->format($request);
        // ... mit $discardBudget: requestDiscardingBody()
        $responseString = $this->transport->request($this->config, $chunks, $cancellation)->await();

        return $this->parser->parse($responseString);
    });

    return $future;
}
```

In `AsyncAmpTransport::openSession()` (Erwerb: Pool, Connect, TLS) und in `AmpTransportSession::inWindow()` (für `write()` und `readResponse()`) fließen Library-Timeout und User-Abbruch zu einem Fenster zusammen; dort steht das Muster: vorab gecancelt → Pool und Connector bleiben unberührt, Fensterende als `IcapTimeoutException`, der Cancel des Aufrufers bleibt, ein nach Abbruch gelieferter Socket wird geschlossen. Timer und Subscription werden in `finally` ausdrücklich entfernt: `TimeoutCancellation` und `CompositeCancellation` räumen erst im Destruktor auf, und eine gehaltene Exception hält die Argumente ihrer Frames, damit beide, am Leben.

**Per-IO, nicht Session-Lifetime:** Jeder `write()`- und `readResponse()`-Aufruf bekommt ein neues Fenster; bei `write()` gilt es für alle Chunks des Aufrufs und wird vor dem ersten Chunk und dort, wo der Producer die Kontrolle zurückgibt (jeder Chunk, auch ein leerer; nach jedem Write; vor der Rückgabe), zusätzlich an einer monotonen Endzeit geprüft, weil der Timer nicht feuert, solange ein Producer den Event-Loop hält. `Socket::write()` hat keinen Cancellation-Parameter: ein unter Backpressure festhängender Write endet nur, indem die Session (der Socket) geschlossen wird. Ein Aufruf, der nicht fertig wird, schließt die Session dauerhaft; `release()` danach poolt nichts.

## Sessions — Preview-Continue nach §4.5

`SessionAwareTransport` ist die Voraussetzung dafür, dass Preview und Continuation auf demselben Socket leben. Ablauf in `IcapClient::scanFileWithPreviewStrict()`: `openSession()`, Preview schreiben, Antwort lesen; ist das Preview die ganze Datei, entscheidet `interpretResponse()`, sonst `previewResult()` (Strategie oder Verdikt); bei `100 Continue` geht nur der Rest des Chunked-Bodys hinaus (`ChunkedBodyEncoder::encodeRemainderFromStream()`, kein neuer ICAP-Head), dann entscheidet `interpretResponse()` die finale Antwort.

Faustregeln:
- `release()` erst nach geprüftem Frame, erlaubtem Status/Verdikt und ohne `close`-Token in `Connection` einer Antwort des Austauschs.
- Jeder Exception-Pfad und jeder nicht freigegebene Austausch endet in `close()`. Ein halb gesprochener Socket darf den nächsten Pool-User nicht sehen.
- `release()` ist der letzte Schritt: danach ist die Session beendet, ein `close()` im Fehlerpfad wäre wirkungslos.

## Streaming, kein Buffering

`RequestFormatter::format(): iterable<string>` liefert drei Phasen:
1. ICAP-Head + Encapsulated-Offsets
2. encapsulated HTTP-Header-Block
3. Chunked-Body (HTTP/1.1) inkl. Terminator (`0\r\n\r\n` regulär, `0; ieof\r\n\r\n` bei Preview-Complete).

Niemals den Body in einen einzigen String puffern. `scanFile()` öffnet die Datei innerhalb des Futures über `LocalFile::open()` (amphp/file, eigene Worker-Prozesse), nimmt die Größe der offenen Datei, reicht sie als `body` in den `HttpResponse` und lässt den Encoder daraus Chunks ziehen; nach dem Austausch prüft `assertRead()`, dass der Request bis EOF gelesen hat, und `close()`/`discard()` schließen sie. Übergebene Streams liest der Client nur. Der Encoder terminiert (`0\r\n\r\n` / `0; ieof`) erst nach bestätigtem EOF; Lesefehler, leerer Read vor dem Ende oder ein nötiger, fehlgeschlagener Rewind werfen `IcapStorageException`. Kündigt die Nachricht eine einzelne `Content-Length` an, muss der Stream genau so viele Bytes liefern (der Formatter reicht sie an den Encoder), sonst wirft er vor dem Abschlusschunk (außer bei einem Teil-Preview: `Preview` ohne `ieof`). Details: [`docs/file-sources.md`](../../../../docs/file-sources.md).

## TLS — `icaps://`

`Config::withTlsContext(new ClientTlsContext($host))` schaltet auf TLS um, nur mit `AsyncAmpTransport` (der synchrone Transport lehnt einen TLS-Kontext mit `IcapConnectionException` ab). `SocketOpener::open()` ist der eine Weg zu einer Verbindung (Transport, Pool, NullPool): TCP in `socketTimeout`, dann `setupTls()` in `tlsHandshakeTimeout`, nie `connectTls()`.

**Pool-Key:** `AmpConnectionPool::bucketOf()` hängt ein vom Pool vergebenes, nie wiederverwendetes Token je `ClientTlsContext`-Objekt an (`\WeakMap`, B07). Gleiche Objekte teilen Sockets, verschiedene nie — auch nicht, nachdem das Original freigegeben wurde.

## Connection-Pool

Opt-in über `new AsyncAmpTransport($pool)`; ohne Pool öffnet jeder Request eine eigene Verbindung. Verhalten:
- LIFO pro Bucket (warmer Socket zuerst); Idle-Sockets älter als `maxIdleSeconds` schließt der Pool in allen Buckets (monotone Uhr).
- `acquire()` reserviert vor dem Connect Platz (Endpoint-Cap `maxConnectionsPerHost` und Gesamtcap `maxConnections` über verbindende, aktive und idle Sockets) und wirft `IcapConnectionException`, wenn der Pool voll ist und keine Queue (`maxWaiters`, `waitTimeout`) konfiguriert ist. Einen Idle-Socket liest es vor der Ausgabe einmal (Bytes oder EOF schließen ihn).
- `release()` nimmt nur verliehene Sockets zurück und nur für ihren Bucket; Fremdsockets, ein anderer Endpoint/TLS-Kontext und ein Socket über einem gesenkten Cap werden geschlossen. Ein zweites Release im Idle-Zustand ändert nichts (ein Eintrag, eine Ausgabe); ein spätes nach erneuter Ausgabe ist ohne Lease-Token nicht vom Release des neuen Halters zu unterscheiden (die Session schützt sich mit `disposed`).
- `tuneFromOptions($response, $config)` senkt die Caps auf `Max-Connections` (positive Dezimalzahl bis 9 Stellen).

## Concurrency-Primitiven aus amphp

- `\Amp\async(fn (): T): Future<T>` — neuer Fiber-Job, sofort gestartet.
- `\Amp\delay(float $seconds, ?Cancellation): void` — Sleep, der Cancellation respektiert. Vorbild: `RetryingIcapClient`-Backoff.
- `Future::await()` — blockiert die aktuelle Fiber bis Resolve; außerhalb einer Fiber läuft dabei der Event-Loop.
- `Amp\async()`-Closures dürfen **nicht** auf `$this->state` schreiben, ohne sich der Fiber-Re-Entrancy bewusst zu sein. In icap-flow sind alle Async-Methoden re-entrant-safe, weil `IcapClient` selbst stateless ist (`$transport`, `$formatter`, `$parser` werden nur gelesen).

## Synchroner Pfad

`SynchronousStreamTransport` nutzt `stream_socket_client` und blockt direkt. Jeder Chunk geht ganz hinaus oder der Aufruf wirft; ein abgelaufenes Stream-Timeout ist `IcapTimeoutException`, nie ein EOF. Der Request geht über einen nicht blockierenden Socket hinaus (`stream_select()`; bei `request()` mit Frist je Stillstand), das Lesen blockierend mit Stream-Timeout; ein laufender Wait ist durch den Cancel des Aufrufers nicht unterbrechbar. `SynchronousIcapClient` ist nur ein `await()`-Wrapper:

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

Der synchrone Transport ist ebenfalls `SessionAwareTransport` (`SynchronousStreamSession`, blockierend): `scanFileWithPreview()` setzt die Preview auf der Verbindung der Preview fort. Sein `write()` hat wie `AmpTransportSession` ein `streamTimeout`-Fenster für alle Chunks, die Zeit des Producers eingeschlossen; nur der eigene Request von `request()` behält die Stillstandsfrist (jedes genommene Byte startet sie neu).

## Quick-Reference

| Tool | Use case | Vorkommen in icap-flow |
|---|---|---|
| `\Amp\async()` | Public-Async-Methode | `IcapClient::run()`, `executeRaw()` |
| `Future<T>::await()` | In Fiber blockierend warten | Fast alle async-Methoden |
| `Cancellation` Param | Externes Abbrechen | Pflicht-Last-Param public-API |
| `OperationDeadline` | Eine Endzeit je öffentlichem Aufruf | `IcapClient`, `RetryingIcapClient` |
| `DeferredCancellation` + `EventLoop::delay()` | User-Abbruch + Lib-Fenster je IO-Phase | `AsyncAmpTransport::openSession()`, `AmpTransportSession::inWindow()` |
| `SessionAwareTransport` | Multi-IO auf einem Socket | Preview-Continue (§4.5) |
| `SocketOpener::open()` | TCP, dann `setupTls()` | Transport, Pool, `NullConnectionPool` |
| `\Amp\delay()` | Cancellation-aware Sleep | `RetryingIcapClient`-Backoff |
| Symfony HttpClient | — | **nicht verwendet** in icap-flow |
| ReactPHP / Swoole | — | **nicht verwendet** |
