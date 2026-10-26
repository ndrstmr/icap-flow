# Scanning files

`scanFile()` and `scanFileWithPreview()` read a local file through [amphp/file](https://github.com/amphp/file), so the event loop keeps running while the file is read.

## Source contract

- The application provides a file that does not change until the call has returned, and relates the result to that version. Quarantine, snapshots and release stay with the application.
- The client checks the type of the path, opens the file once and reads that open file: its size, its position and its end. After the open the path is not looked at again, so a path that is replaced during the scan does not change what is scanned.
- A file that shrinks or grows during the scan fails the call with an `IcapStorageException` before the final chunk. New content of the same size is not detected.
- Supported are regular files of the local file system (a link counts as its target). A device, a FIFO, a directory and a path that does not exist are refused with an `IcapStorageException` before anything is opened. A path is no stream-wrapper URL. A relative path is taken from the working directory of the call. Network file systems and FUSE are not qualified.
- The request has to read the whole file. A custom `RequestFormatterInterface` receives it as the body of the encapsulated response (a `LocalFile`, internal) and passes it to `ChunkedBodyEncoder`. A scan whose request did not read the file to its end fails with an `IcapStorageException`; the answer of the server is no verdict on a body that is not the file. A file that cannot be closed after a clean scan fails the scan as well.

## Driver

- The client reads through worker processes (`amphp/parallel`, at most 8 for the files and one more for the lookup of the type of a path, started on first use; `proc_open` has to be available), whatever driver the application set with `Amp\File\filesystem()`. No other driver is used (a blocking one would hold the event loop; `ext-uv` and `ext-eio` are not qualified).
- The first scan of a PHP process starts two workers (about 0.2 s), and every read crosses a process boundary (about 500 MB/s).
- A scan holds one block of 64 KiB; the preview (at most 1 MiB) is the only larger buffer.

## Deadline and cancellation

- The wait for a file call ends with the operation: `IcapTimeoutException` at its timeout, `Amp\CancelledException` on the caller's cancellation. A failure of the driver that comes in after that is not reported over it.
- A call into the operating system that hangs (a stalled mount) cannot be interrupted. The operation ends, the worker stays busy with that call, and the handle is closed when the call returns. A worker blocked that way is not replaced, and the scans whose files it holds wait behind that call until their own timeout. A lookup that hangs blocks the lookup worker, and every new scan waits behind it.
- At most 64 file calls are on their way at the same time, those that no operation waits for any more included; a call keeps its place until the driver has returned. When hanging calls hold all places, new scans wait for one, end at their timeout and send nothing to the workers. A call whose operation ended before it started is not started.
