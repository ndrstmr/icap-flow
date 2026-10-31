# Scanning files

`scanFile()` and `scanFileWithPreview()` read a local file through [amphp/file](https://github.com/amphp/file), so the event loop keeps running while the file is read.

## Source contract

- The application provides an unchanged file, keeps its path stable from before the type check through the scan, and releases only that same unchanged object. Quarantine, snapshots and release stay with the application.
- The client checks the type of the path, opens the file once and reads that open file: its size, its position and its end. After the open the path is not looked at again, so a path that is replaced during the scan does not change what is scanned.
- A file that shrinks or grows during the scan fails the call with an `IcapStorageException` before the final chunk. New content of the same size is not detected.
- Supported are regular files of the local file system (a link counts as its target). A device, a FIFO, a directory and a path that does not exist are refused with an `IcapStorageException` before anything is opened. A path is no stream-wrapper URL. A relative path is taken from the working directory of the call. Network file systems and FUSE are not qualified.
- The request has to read the whole file. A custom `RequestFormatterInterface` receives it as the body of the encapsulated response (a `LocalFile`, internal) and passes it to `ChunkedBodyEncoder`. A scan whose request did not read the file to its end fails with an `IcapStorageException`; the answer of the server is no verdict on a body that is not the file. A file that cannot be closed after a clean scan fails the scan as well.

## Large responses

A server that is not offered `Allow: 204` returns the whole message (RFC 3507 §4.6), so the answer to a large file is as large as the file. `scanFile()` keeps the body of the response and refuses a response over `Config::getMaxResponseSize()` (10 MiB by default). A caller that needs only the verdict and the metadata of the response uses the discarding scans:

```php
// 1 GiB file, a server that echoes it back; the budget counts every byte of one response.
$result = $client->scanFileDiscardingBody('/avscan', $path, maxResponseBytes: 2 * 1024 ** 3)->await();
// or scanFileWithPreviewDiscardingBody('/avscan', $path, 2 * 1024 ** 3, previewSize: 1024)

$result->isInfected();                                    // the verdict, from status and headers as always
$result->getOriginalResponse()->discardedBodyBytes;       // 1073741824; the body is '' and was not kept
```

- The response is read as it arrives and checked to its last byte (chunks, trailer); bytes that came in behind it are an `IcapMalformedResponseException`, and the socket is released as for any other exchange. The decoder holds at most an unfinished line, however large the body is.
- `$maxResponseBytes` has no default. It bounds the bytes of each response of the exchange (head, headers, chunk framing, body, trailer) in place of `maxResponseSize`; the limits for header lines and their number are those of the `Config`.
- `discardedBodyBytes` is set on every response that was received this way (0 without a body); it is `null` on a response whose body is in `$body`.
- The stream timeout is the time the whole response may take once it has begun, and the operation timeout covers the whole scan: raise both for a transfer of a gigabyte.
- `Allow: 204` is not added (the verified mode, [`docs/cicap-clamav.md`](cicap-clamav.md), adds it); the request is the one of `scanFile()`.
- It needs a transport that implements `DiscardingTransport` (`AsyncAmpTransport` and `SynchronousStreamTransport` do) and the `ResponseParser` of the library; the preview variant, like every preview, also needs sessions (`SessionAwareTransport`, which both have). With another transport or parser the call throws a `LogicException` before it opens the file. `scanFileWithPreviewDiscardingBody()` also needs the `DefaultPreviewStrategy` (status and headers): a `PreviewStrategyInterface` of your own may judge the body of the answer to the preview, which is not kept, and is refused the same way. `SynchronousIcapClient` and `RetryingIcapClient` offer the same methods if the client they wrap does.

## Driver

- The client reads through worker processes (`amphp/parallel`, at most 8 for the files and one more for the lookup of the type of a path, started on first use; `proc_open` has to be available), whatever driver the application set with `Amp\File\filesystem()`. No other driver is used (a blocking one would hold the event loop; `ext-uv` and `ext-eio` are not qualified).
- The first scan of a PHP process starts workers, and every read crosses a process boundary; startup cost and throughput depend on the deployment.
- A scan holds one block of 64 KiB; the preview (at most 1 MiB) is the only larger buffer.

## Deadline and cancellation

- The wait for a file call ends with the operation: `IcapTimeoutException` at its timeout, `Amp\CancelledException` on the caller's cancellation. A failure of the driver that comes in after that is not reported over it.
- A call into the operating system that hangs (a stalled mount) cannot be interrupted. The operation ends, the worker stays busy with that call, and the handle is closed when the call returns. A worker blocked that way is not replaced, and the scans whose files it holds wait behind that call until their own timeout. A lookup that hangs blocks the lookup worker, and every new scan waits behind it.
- At most 64 file calls are on their way at the same time, those that no operation waits for any more included; a call keeps its place until the driver has returned. When hanging calls hold all places, new scans wait for one, end at their timeout and send nothing to the workers. A call whose operation ended before it started is not started.
