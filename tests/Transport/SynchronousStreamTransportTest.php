<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 *
 * This file is part of icap-flow.
 *
 * Licensed under the EUPL, Version 1.2 only (the "Licence");
 * you may not use this work except in compliance with the Licence.
 * You may obtain a copy of the Licence at:
 *
 *     https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the Licence is distributed on an "AS IS" basis,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 */

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\Exception\IcapTimeoutException;
use Ndrstmr\Icap\Transport\SynchronousStreamTransport;
use Ndrstmr\Icap\Transport\TransportInterface;
use PHPUnit\Framework\Assert;

const SYNC_REQUEST = "OPTIONS icap://127.0.0.1/scan ICAP/1.0\r\n\r\n";
const SYNC_FRAME = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";

/**
 * The client of these tests runs in a child process (SyncChild). It blocks, and
 * a client that is broken may block for good: one that falls back to a blocking
 * send() waits for ever on macOS for a peer that does not read, and a write
 * that keeps resending fills the socket and never returns. A test that ran it
 * in-process could only wait; this way the test kills it, and plays the server
 * on its own side with plain blocking calls.
 *
 * The child reads one JSON line (host, port, timeout, chunks, cancel,
 * descriptors, and for the operation budget of M10: operation, responseStart,
 * hold, client, operationTimeout, and for AP-15 discard: a budget makes it call requestDiscardingBody() with that many bytes), runs the transport and writes one JSON line
 * back. With "operation" the transport gets the end of an operation of that many
 * seconds as its cancellation, "hold" seconds are spent (blocking) before it is
 * called, "responseStart" is the response start timeout of the Config, and
 * with "client" it is an IcapClient with "operationTimeout" that makes an
 * OPTIONS request, not the transport that is called. A chunk is a
 * string, or one of {"big": n} (n times a 1021 byte block), {"await": true}
 * (wait for the test to release it), {"cancel": true} and {"fail": "message"}
 * (producer behaviour). "descriptors" opens that many files first, so that the
 * socket gets a descriptor beyond what stream_select() can hold; the child says
 * at once {"ready": true}, or {"skipped": reason} and ends if its limit does not
 * allow that many, and the test is skipped.
 */
const SYNC_CHILD = <<<'PHP'
require $argv[1];
// A client whose test died must not outlive it, whatever the broken code does.
set_time_limit(60);
$job = json_decode((string) fgets(STDIN), true);
$burned = [];
if ($job['descriptors'] > 0) {
    // The client needs a few descriptors of its own on top of the ones burned here.
    $wanted = $job['descriptors'] + 32;
    while (count($burned) < $wanted && ($file = @fopen('/dev/null', 'r')) !== false) {
        $burned[] = $file;
    }
    if (count($burned) < $wanted) {
        array_map('fclose', $burned);
        echo json_encode(['skipped' => 'This process cannot hold enough open descriptors.']), "\n";
        exit;
    }
    array_map('fclose', array_splice($burned, $job['descriptors']));
    echo json_encode(['ready' => true]), "\n";
}
$block = substr(pack('N*', ...range(1, 256)), 0, 1021);
$asked = 0;
$cancel = new \Amp\DeferredCancellation();
$cancellation = match ($job['cancel']) {
    'beforehand' => (static function () use ($cancel) {
        $cancel->cancel();

        return $cancel->getCancellation();
    })(),
    'later' => $cancel->getCancellation(),
    // Requested once the test has written a line to the child's stdin (SyncChild::release()), which it does while the
    // client is blocked in a read: the look at the cancellation after that read finds it.
    'on-signal' => new class () implements \Amp\Cancellation {
        private bool $signalled = false;

        private function poll(): bool
        {
            if (!$this->signalled) {
                $read = [STDIN];
                $write = $except = [];
                $this->signalled = @stream_select($read, $write, $except, 0) > 0 && fgets(STDIN) !== false;
            }

            return $this->signalled;
        }

        public function subscribe(\Closure $callback): string
        {
            return 'none';
        }

        public function unsubscribe(string $id): void
        {
        }

        public function isRequested(): bool
        {
            return $this->poll();
        }

        public function throwIfRequested(): void
        {
            if ($this->poll()) {
                throw new \Amp\CancelledException();
            }
        }
    },
    // The first look (before connecting) finds nothing, every later one the cancellation.
    'after-first-look' => new class () implements \Amp\Cancellation {
        private int $looks = 0;

        public function subscribe(\Closure $callback): string
        {
            return 'none';
        }

        public function unsubscribe(string $id): void
        {
        }

        public function isRequested(): bool
        {
            return $this->looks > 0;
        }

        public function throwIfRequested(): void
        {
            if ($this->looks++ > 0) {
                throw new \Amp\CancelledException();
            }
        }
    },
    default => null,
};
$chunks = (static function () use ($job, $block, $cancel, &$asked): \Generator {
    foreach ($job['chunks'] as $chunk) {
        if (!is_array($chunk)) {
            $asked++;
            yield $chunk;
        } elseif (isset($chunk['big'])) {
            $asked++;
            yield str_repeat($block, $chunk['big']);
        } elseif (isset($chunk['await'])) {
            stream_set_timeout(STDIN, 10);
            if (fgets(STDIN) === false) {
                throw new \RuntimeException('The test did not release the client.');
            }
        } elseif (isset($chunk['cancel'])) {
            $cancel->cancel();
        } else {
            throw new \RuntimeException($chunk['fail']);
        }
    }
})();
// Every notice that is not suppressed with an @ is a finding.
$leaked = [];
error_reporting(E_ALL);
set_error_handler(static function (int $level, string $message) use (&$leaked): bool {
    if ((error_reporting() & $level) !== 0) {
        $leaked[] = $message;
    }

    return true;
});
$streams = count(get_resources('stream'));
// The settings of M10 only where a test sets them, so that the other tests ask nothing new of the client.
$settings = ['streamTimeout' => (float) $job['timeout']];
if ($job['responseStart'] !== null) {
    $settings['responseStartTimeout'] = (float) $job['responseStart'];
}
if ($job['operationTimeout'] !== null) {
    $settings['operationTimeout'] = (float) $job['operationTimeout'];
}
$config = new \Ndrstmr\Icap\Config($job['host'], $job['port'], ...$settings);
if ($job['operation'] !== null) {
    $cancellation = \Ndrstmr\Icap\OperationDeadline::begin($cancellation, (float) $job['operation']);
}
usleep((int) ($job['hold'] * 1_000_000));
$started = hrtime(true);
try {
    if ($job['client']) {
        $icap = new \Ndrstmr\Icap\IcapClient($config, new \Ndrstmr\Icap\Transport\SynchronousStreamTransport(), new \Ndrstmr\Icap\RequestFormatter(), new \Ndrstmr\Icap\ResponseParser());
        $out = ['response' => (string) $icap->options('/scan')->await()->statusCode];
    } elseif ($job['discard'] !== null) {
        $response = (new \Ndrstmr\Icap\Transport\SynchronousStreamTransport())
            ->requestDiscardingBody($config, $chunks, $job['discard'], $cancellation)
            ->await();
        $out = ['status' => $response->statusCode, 'discarded' => $response->discardedBodyBytes, 'body' => $response->body];
    } else {
        $out = ['response' => (new \Ndrstmr\Icap\Transport\SynchronousStreamTransport())
            ->request($config, $chunks, $cancellation)
            ->await()];
    }
} catch (\Throwable $e) {
    $out = ['error' => $e::class, 'message' => $e->getMessage()];
}
$out['elapsed'] = (hrtime(true) - $started) / 1e9;
$out['asked'] = $asked;
$out['streams'] = count(get_resources('stream')) - $streams;
$out['leaked'] = $leaked;
echo json_encode($out), "\n";
PHP;

/**
 * The transport under test, in a child process the test can kill.
 */
final class SyncChild
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    /**
     * @param list<string|array<string, mixed>> $chunks
     * @param 'beforehand'|'later'|'after-first-look'|'on-signal'|null $cancel
     */
    public static function start(
        int $port,
        float $timeout,
        array $chunks,
        ?string $cancel = null,
        string $host = '127.0.0.1',
        int $descriptors = 0,
        ?float $operation = null,
        ?float $responseStart = null,
        float $hold = 0.0,
        bool $client = false,
        ?float $operationTimeout = null,
        ?int $discard = null,
    ): self {
        $child = new self();
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-r', SYNC_CHILD, '--', dirname(__DIR__, 2) . '/vendor/autoload.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $child->pipes,
        );
        Assert::assertIsResource($process);
        $child->process = $process;
        fwrite($child->pipes[0], json_encode(compact('host', 'port', 'timeout', 'chunks', 'cancel', 'descriptors', 'operation', 'responseStart', 'hold', 'client', 'operationTimeout', 'discard'), JSON_THROW_ON_ERROR) . "\n");
        if ($descriptors > 0) {
            // The child says first whether its limit allows that many files; if not, the test is skipped.
            $child->reply(10.0);
        }

        return $child;
    }

    /** Lets a client that waits at an {"await": true} chunk go on. */
    public function release(): void
    {
        fwrite($this->pipes[0], "go\n");
    }

    /**
     * What the transport did: the response or the exception, how long it took,
     * how many chunks were asked for, the streams it left open and the notices
     * that got past its own @. A client that does not answer in time is killed.
     *
     * @return array{response?: string, status?: int, discarded?: int|null, body?: string, error?: class-string, message?: string, elapsed: float, asked: int, streams: int, leaked: list<string>}
     */
    public function outcome(float $within = 10.0): array
    {
        $outcome = $this->reply($within);

        /** @var array{response?: string, status?: int, discarded?: int|null, body?: string, error?: class-string, message?: string, elapsed: float, asked: int, streams: int, leaked: list<string>} $outcome */
        return $outcome;
    }

    /**
     * The next line the client wrote. A client that does not write one in time
     * is killed; one that cannot run here (see SYNC_CHILD) skips the test.
     *
     * @return array<mixed>
     */
    private function reply(float $within): array
    {
        $line = '';
        $until = microtime(true) + $within;
        while (!str_contains($line, "\n")) {
            $left = $until - microtime(true);
            $ready = [$this->pipes[1]];
            $write = $except = [];
            if ($left <= 0 || stream_select($ready, $write, $except, (int) floor($left), (int) (fmod($left, 1.0) * 1_000_000)) < 1) {
                $this->stop();
                Assert::fail(sprintf('The client did not answer within %s s and was killed.', $within));
            }
            $part = fread($this->pipes[1], 65536);
            if ($part === false || $part === '') {
                $error = stream_get_contents($this->pipes[2]);
                $this->stop();
                Assert::fail('The client ended without an answer: ' . $error);
            }
            $line .= $part;
        }
        $reply = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($reply);
        if (isset($reply['skipped']) && is_string($reply['skipped'])) {
            $this->stop();
            Assert::markTestSkipped($reply['skipped']);
        }

        return $reply;
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process, 9);
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($this->process);
            $this->process = null;
        }
    }
}

/**
 * @return array{resource, int} a listening socket on a free loopback port, and the port
 */
function syncListen(string $address = '127.0.0.1'): array
{
    $server = @stream_socket_server('tcp://' . (str_contains($address, ':') ? "[$address]" : $address) . ':0');
    if ($server === false) {
        Assert::markTestSkipped("Cannot listen on $address.");
    }
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    return [$server, $port];
}

/**
 * Accepts the waiting client and reads $expected bytes of what it sent, after
 * waiting $pause seconds if asked to (a peer that is slow to read).
 *
 * @param resource $server
 * @return array{resource, string} the peer, and the bytes
 */
function syncServe($server, int $expected, float $pause = 0.0): array
{
    $peer = @stream_socket_accept($server, 5);
    Assert::assertIsResource($peer);
    stream_set_timeout($peer, 5);
    // No read-ahead: what was not asked for stays unread in the kernel, which is what makes a close reset.
    stream_set_read_buffer($peer, 0);
    usleep((int) ($pause * 1_000_000));
    $received = '';
    while (($missing = $expected - strlen($received)) > 0 && ($part = fread($peer, $missing)) !== false && $part !== '') {
        $received .= $part;
    }

    return [$peer, $received];
}

/**
 * Everything the peer still gets until the client closes its end (or the
 * peer's own 5 s timeout runs out), and whether that close was seen. It stops
 * reading beyond $limit bytes, so that a client that sends far too much does not
 * fill the failure message of the test with it.
 *
 * @param resource $peer
 * @return array{string, bool}
 */
function syncDrain($peer, int $limit = 65536): array
{
    $data = '';
    while (strlen($data) <= $limit && ($part = fread($peer, 65536)) !== false && $part !== '') {
        $data .= $part;
    }

    return [$data, stream_get_meta_data($peer)['eof']];
}

/**
 * Writes the parts to the client until it has them all or has closed its end. A client that refuses a response or
 * has given up closes while the rest is on its way, and the write fails with a reset or a broken pipe: PHP reports
 * that as a notice, which PHPUnit records even under @. Here it only ends the writing; any other error goes on to the
 * handler that was there.
 *
 * @param resource         $peer
 * @param iterable<string> $parts
 */
function syncSend($peer, iterable $parts): void
{
    $previous = null;
    $previous = set_error_handler(static function (int $type, string $message, string $file, int $line) use (&$previous): bool {
        if (preg_match('/^fwrite\(\): Send of \d+ bytes failed with errno=\d+ (Broken pipe|Connection reset by peer)$/', $message) === 1) {
            return true;
        }

        return is_callable($previous) && (bool) $previous($type, $message, $file, $line);
    });
    try {
        foreach ($parts as $part) {
            if (fwrite($peer, $part) === false) {
                return;
            }
        }
    } finally {
        restore_error_handler();
    }
}

/**
 * What a client that was never accepted wrote, and whether it closed.
 *
 * @param resource $server
 * @return array{string, bool}
 */
function syncPeerSaw($server, int $limit = 65536): array
{
    [$peer] = syncServe($server, 0);
    try {
        return syncDrain($peer, $limit);
    } finally {
        fclose($peer);
    }
}

/**
 * A stream whose writes and reads answer from a script, for the results a
 * real socket does not give on demand (a short write that stops without a
 * timeout, no progress, false). It records the bytes it took, so a test can
 * compare them with the bytes that were meant to go out. An exhausted write
 * script throws: a loop that never ends fails the test instead of hanging it.
 *
 * stream_select() waits for it through a socket pair end, which is writable at
 * once: a wait on this stream returns immediately, and a loop that never ends
 * runs into the exhausted script.
 */
final class SyncScriptedStream
{
    private const string SCHEME = 'syncscripted';

    /** @var list<int|false|'all'> */
    private static array $writes = [];

    private static string|false $read = '';

    private static bool $eof = false;

    public static string $taken = '';

    public static ?Closure $afterWrite = null;

    /** Runs inside every read, while the caller is blocked in it: it holds the read up or cancels meanwhile. */
    public static ?Closure $onRead = null;

    /** @var array{resource, resource}|null */
    private static ?array $pair = null;

    public mixed $context = null;

    /**
     * @param list<int|false|'all'> $writes what each stream_write() takes: a byte count, 'all', or false
     * @param string|false          $read   what every stream_read() answers
     * @return resource
     */
    public static function open(array $writes = [], string|false $read = '', bool $eof = false)
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
        self::$writes = $writes;
        self::$read = $read;
        self::$eof = $eof;
        self::$taken = '';
        self::$afterWrite = null;
        self::$onRead = null;
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        Assert::assertIsArray($pair);
        self::$pair = [$pair[0], $pair[1]];
        $stream = fopen(self::SCHEME . '://stream', 'r+');
        Assert::assertIsResource($stream);

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int|false
    {
        if (self::$writes === []) {
            throw new LogicException('The write script is exhausted: the transport keeps writing.');
        }
        $took = array_shift(self::$writes);
        if ($took === 'all') {
            $took = strlen($data);
        }
        if ($took !== false) {
            self::$taken .= substr($data, 0, $took);
        }
        if (self::$afterWrite !== null) {
            (self::$afterWrite)();
        }

        return $took;
    }

    public function stream_read(int $count): string|false
    {
        if (self::$onRead !== null) {
            (self::$onRead)();
        }

        return self::$read;
    }

    /** A timeout cannot be set on this stream; saying so keeps PHP from warning about it. */
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_eof(): bool
    {
        return self::$eof;
    }

    /** @return resource */
    public function stream_cast(int $castAs)
    {
        Assert::assertNotNull(self::$pair);

        return self::$pair[0];
    }
}

/**
 * A caller who cancels while the client decodes what it has read, which nothing can make a single thread do at an exact
 * moment otherwise: once the request is out ({@see requestIsOut()}), the look before the read and the look after it find
 * nothing, every later look finds the cancellation.
 */
final class SyncCancelsWhileDecoding implements Cancellation
{
    private const int LOOKS_BEFORE = 2;

    private ?int $looks = null;

    public function __construct(private readonly bool $cancels)
    {
    }

    public function requestIsOut(): void
    {
        $this->looks = 0;
    }

    #[\Override]
    public function subscribe(\Closure $callback): string
    {
        return 'none';
    }

    #[\Override]
    public function unsubscribe(string $id): void
    {
    }

    #[\Override]
    public function isRequested(): bool
    {
        return $this->cancels && $this->looks !== null && $this->looks > self::LOOKS_BEFORE;
    }

    #[\Override]
    public function throwIfRequested(): void
    {
        if ($this->looks !== null && $this->looks++ >= self::LOOKS_BEFORE && $this->cancels) {
            throw new CancelledException();
        }
    }
}

function syncThrown(Closure $call): ?Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/** @param resource $stream */
function syncWriteAll($stream, string $chunk, ?Cancellation $cancellation = null, float $timeout = 0.5): void
{
    (new ReflectionMethod(SynchronousStreamTransport::class, 'writeAll'))
        ->invoke(null, $stream, $chunk, $cancellation, $timeout);
}

/** 1021 is prime, so that a lost or repeated part shows in a comparison. */
function syncBig(int $blocks): string
{
    return str_repeat(substr(pack('N*', ...range(1, 256)), 0, 1021), $blocks);
}

/** @param resource $stream */
function syncReadChunk($stream): mixed
{
    return (new ReflectionMethod(SynchronousStreamTransport::class, 'readChunk'))
        ->invoke(null, $stream, null, 0.5);
}

it('implements TransportInterface', function () {
    $t = new SynchronousStreamTransport();
    expect($t)->toBeInstanceOf(TransportInterface::class);
});

it('throws connection exception on failure', function () {
    $t = new SynchronousStreamTransport();
    $config = new Config('256.256.256.256', 9999); // invalid host

    // stream_socket_client emits E_WARNING for unresolvable hosts even
    // with the @-operator — suppress it in the test to satisfy
    // failOnWarning=true.
    set_error_handler(static fn () => true, E_WARNING);
    try {
        expect(fn () => $t->request($config, [])->await())->toThrow(IcapConnectionException::class);
    } finally {
        restore_error_handler();
    }
});

it('connects to an IPv6 literal host (N04)', function () {
    [$server, $port] = syncListen('::1');
    // Nobody answers, and a zero stream timeout makes the client give up at once,
    // as a timeout. The request bytes on the server side show where it connected.
    $child = SyncChild::start($port, 0.0, ["OPTIONS icap://[::1]/scan ICAP/1.0\r\n\r\n"], host: '::1');
    try {
        expect($child->outcome()['error'] ?? null)->toBe(IcapTimeoutException::class);

        $peer = stream_socket_accept($server, 5);
        Assert::assertIsResource($peer);
        expect(fread($peer, 1024))->toBe("OPTIONS icap://[::1]/scan ICAP/1.0\r\n\r\n");
        fclose($peer);
    } finally {
        $child->stop();
        fclose($server);
    }
});

/**
 * M09 — the stream timeout kept only its whole seconds, so 0.3 became 0, and a
 * read that timed out looked like the end of the stream.
 */
it('waits out a sub-second stream timeout and reports it as a timeout', function () {
    [$server, $port] = syncListen();
    $child = SyncChild::start($port, 0.3, [SYNC_REQUEST]);
    try {
        $outcome = $child->outcome();

        expect($outcome['error'] ?? null)->toBe(IcapTimeoutException::class);
        // Not at once (0 seconds), and not after the 60 s PHP falls back to.
        expect($outcome['elapsed'])->toBeGreaterThanOrEqual(0.25)->toBeLessThan(5.0);
        expect(syncPeerSaw($server))->toBe([SYNC_REQUEST, true]);
    } finally {
        $child->stop();
        fclose($server);
    }
});

it('tells a peer that went quiet in the middle of a frame from one that hung up', function () {
    $head = "ICAP/1.0 200 OK\r\nEncapsulated: res-body=0\r\n";
    // The answer, whether the peer hangs up after it, and the client's stream timeout.
    $cases = [
        'went quiet in the frame' => [$head, false, 0.3],
        'hung up in the frame'    => [$head, true, 5.0],
        'hung up before any byte' => ['', true, 5.0],
    ];

    $seen = [];
    foreach ($cases as $case => [$answer, $hangUp, $timeout]) {
        [$server, $port] = syncListen();
        $child = SyncChild::start($port, $timeout, [SYNC_REQUEST]);
        $peer = null;
        try {
            [$peer] = syncServe($server, strlen(SYNC_REQUEST));
            fwrite($peer, $answer);
            if ($hangUp) {
                fclose($peer);
            }

            $seen[$case] = [$child->outcome()['error'] ?? null, $hangUp ? true : syncDrain($peer)[1]];
        } finally {
            $child->stop();
            if (is_resource($peer)) {
                fclose($peer);
            }
            fclose($server);
        }
    }

    // Hanging up is a framing error; going quiet is a timeout, and the client's end is closed.
    expect($seen)->toBe([
        'went quiet in the frame' => [IcapTimeoutException::class, true],
        'hung up in the frame'    => [IcapMalformedResponseException::class, true],
        'hung up before any byte' => [IcapMalformedResponseException::class, true],
    ]);
});

it('sends every chunk once and in order, returns a complete frame and closes its socket', function () {
    [$server, $port] = syncListen();
    $chunks = [str_repeat('a', 16000), '', str_repeat('b', 16000), "0\r\n\r\n"];
    $expected = implode('', $chunks);
    // Longer than one read of the transport, so that it takes several.
    $frame = "ICAP/1.0 200 OK\r\nEncapsulated: res-body=0\r\n\r\n" . dechex(20000) . "\r\n" . str_repeat('z', 20000) . "\r\n0\r\n\r\n";
    $child = SyncChild::start($port, 5.0, $chunks);
    $peer = null;
    try {
        [$peer, $received] = syncServe($server, strlen($expected));
        fwrite($peer, $frame);

        $outcome = $child->outcome();

        expect($outcome['response'] ?? null)->toBe($frame);
        expect($received)->toBe($expected);
        expect(syncDrain($peer))->toBe(['', true]);
    } finally {
        $child->stop();
        if (is_resource($peer)) {
            fclose($peer);
        }
        fclose($server);
    }
});

/*
 * B02 / B03 — the synchronous transport frames a response with the rules of the asynchronous one
 * (one decoder for both): a terminator or a forged response inside a chunk payload is payload, bytes
 * behind the response are not part of it, and a response that is illegal is refused, not returned.
 */
it('frames a response by its layout and refuses an illegal one', function () {
    $payload = "prefix\r\n0\r\n\r\n" . SYNC_FRAME . 'suffix';
    $head = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($payload) . "\r\n\r\n";
    $wire = "ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, res-body=" . strlen($head) . "\r\n\r\n" . $head
        . dechex(strlen($payload)) . "\r\n" . $payload . "\r\n0\r\n\r\n";
    $cut = strpos($wire, 'prefix') + strlen("prefix\r\n0\r\n\r\n");
    $bad = str_replace(dechex(strlen($payload)) . "\r\n", "ZZ\r\n", $wire);

    // label => [what the server sends, with a pause between the parts, the frame expected, the exception expected]
    $cases = [
        'terminator and forged response in the payload' => [[substr($wire, 0, $cut), substr($wire, $cut)], $wire, null],
        'bytes behind the response'                      => [[SYNC_FRAME . SYNC_FRAME], SYNC_FRAME, null],
        'chunk size that is no number'                   => [[$bad], null, IcapMalformedResponseException::class],
        'final 200 without Encapsulated'                 => [["ICAP/1.0 200 OK\r\nISTag: \"x\"\r\n\r\n"], null, IcapMalformedResponseException::class],
    ];

    foreach ($cases as $case => [$parts, $frame, $error]) {
        [$server, $port] = syncListen();
        $child = SyncChild::start($port, 5.0, [SYNC_REQUEST]);
        $peer = null;
        try {
            [$peer] = syncServe($server, strlen(SYNC_REQUEST));
            foreach ($parts as $i => $part) {
                if ($i > 0) {
                    usleep(100_000);
                }
                fwrite($peer, $part);
            }

            $outcome = $child->outcome();

            expect($outcome['response'] ?? null)->toBe($frame, $case);
            expect($outcome['error'] ?? null)->toBe($error, $case);
        } finally {
            $child->stop();
            if (is_resource($peer)) {
                fclose($peer);
            }
            fclose($server);
        }
    }
});

it('ends a write that fails with a connection error and asks for no further chunk', function () {
    [$server, $port] = syncListen();
    $child = SyncChild::start($port, 5.0, ['first', 'second', ['await' => true], str_repeat('x', 2 * 1024 * 1024), 'never sent']);
    try {
        // Closed with 'second' unread, the peer resets the connection. The next chunk is
        // far bigger than any buffer, so that the failure falls inside it whenever the reset arrives.
        [$peer] = syncServe($server, 5);
        $ready = [$peer];
        $write = $except = [];
        stream_select($ready, $write, $except, 5);
        fclose($peer);
        $child->release();

        $outcome = $child->outcome();

        expect($outcome['error'] ?? null)->toBe(IcapConnectionException::class);
        expect($outcome['message'] ?? '')->toContain('Writing the request');
        expect($outcome['asked'])->toBe(3);
        expect($outcome['streams'])->toBe(0);
        expect($outcome['leaked'])->toBe([]);
    } finally {
        $child->stop();
        fclose($server);
    }
});

it('ends a write to a peer that stopped reading with a timeout, after part of the chunk', function () {
    [$server, $port] = syncListen();
    $big = syncBig(32 * 1024);
    $child = SyncChild::start($port, 0.3, [['big' => 32 * 1024], 'tail']);
    try {
        $outcome = $child->outcome();

        expect($outcome['error'] ?? null)->toBe(IcapTimeoutException::class);
        expect($outcome['asked'])->toBe(1);
        expect($outcome['elapsed'])->toBeGreaterThanOrEqual(0.25)->toBeLessThan(10.0);
        expect($outcome['streams'])->toBe(0);
        expect($outcome['leaked'])->toBe([]);

        [$received, $closed] = syncPeerSaw($server, 64 * 1024 * 1024);
        expect($closed)->toBeTrue();
        expect(strlen($received))->toBeGreaterThan(0)->toBeLessThan(strlen($big));
        expect($received === substr($big, 0, strlen($received)))->toBeTrue();
    } finally {
        $child->stop();
        fclose($server);
    }
});

it('gives up on a stalled write at once when the stream timeout is zero', function () {
    [$server, $port] = syncListen();
    $child = SyncChild::start($port, 0.0, [['big' => 32 * 1024], 'tail']);
    try {
        $outcome = $child->outcome();

        expect($outcome['error'] ?? null)->toBe(IcapTimeoutException::class);
        expect($outcome['asked'])->toBe(1);
        expect($outcome['elapsed'])->toBeLessThan(2.0);
        expect($outcome['streams'])->toBe(0);
    } finally {
        $child->stop();
        fclose($server);
    }
});

/**
 * A request larger than the buffers on the way, a peer that is slow to read it
 * and an answer that comes late too: the client has to wait, in both
 * directions, for something that arrives within its timeout.
 *
 * @return array{array{response?: string, error?: class-string, message?: string, elapsed: float, asked: int, streams: int, leaked: list<string>}, bool}
 *         the outcome, and whether the peer got the request intact
 */
function syncSlowPeer(int $descriptors): array
{
    [$server, $port] = syncListen();
    $big = syncBig(8 * 1024);
    $child = SyncChild::start($port, 5.0, [['big' => 8 * 1024]], descriptors: $descriptors);
    $peer = null;
    try {
        [$peer, $received] = syncServe($server, strlen($big), 0.1);
        usleep(100_000);
        fwrite($peer, SYNC_FRAME);

        return [$child->outcome(), $received === $big];
    } finally {
        $child->stop();
        if (is_resource($peer)) {
            fclose($peer);
        }
        fclose($server);
    }
}

it('waits for a peer that is slow to read and for a late answer', function () {
    [$outcome, $intact] = syncSlowPeer(0);

    // A socket left non-blocking would take the late answer for a timeout.
    expect($outcome['response'] ?? $outcome['error'] ?? null)->toBe(SYNC_FRAME);
    expect($intact)->toBeTrue();
    expect($outcome['streams'])->toBe(0);
    expect($outcome['leaked'])->toBe([]);
});

it('waits for a peer that is slow to read where stream_select() cannot hold the socket', function () {
    // A descriptor beyond FD_SETSIZE makes stream_select() fail at once, as an interrupting signal does.
    // The child burns the descriptors, and the test is skipped if its limit does not allow that many.
    [$outcome, $intact] = syncSlowPeer(1100);

    expect($outcome['response'] ?? $outcome['error'] ?? null)->toBe(SYNC_FRAME);
    expect($intact)->toBeTrue();
    expect($outcome['streams'])->toBe(0);
    // The warning of the failed stream_select() stays behind the @.
    expect($outcome['leaked'])->toBe([]);
});

it('closes its socket when the producer fails', function () {
    [$server, $port] = syncListen();
    $child = SyncChild::start($port, 5.0, ['one', ['fail' => 'source failed']]);
    try {
        $outcome = $child->outcome();

        expect($outcome['error'] ?? null)->toBe(RuntimeException::class);
        expect($outcome['message'] ?? '')->toBe('source failed');
        expect($outcome['streams'])->toBe(0);
        expect(syncPeerSaw($server))->toBe(['one', true]);
    } finally {
        $child->stop();
        fclose($server);
    }
});

/*
 * PHP asks a stream again for the rest of one fwrite(), and stops at the first
 * answer of 0 or false: [4, 0] is one fwrite() that returns 4, and the next 0
 * is a fwrite() that returns 0.
 */
it('continues a short write at the offset it reached', function () {
    $chunk = '0123456789';
    $cases = [
        'short write'                       => [4, 0, 'all'],
        'short write, buffer full for once' => [4, 0, 0, 'all'],
    ];

    $seen = [];
    foreach ($cases as $case => $script) {
        $stream = SyncScriptedStream::open($script);
        try {
            syncWriteAll($stream, $chunk);
            $seen[$case] = SyncScriptedStream::$taken;
        } finally {
            fclose($stream);
        }
    }

    expect($seen)->toBe(array_map(static fn (array $script): string => $chunk, $cases));
});

it('fails a write that returns false, and sends nothing more', function () {
    $chunk = '0123456789';
    $cases = [
        'false'             => [[false], ''],
        'short, then false' => [[4, false, false], '0123'],
    ];

    $seen = [];
    foreach ($cases as $case => [$script]) {
        $stream = SyncScriptedStream::open($script);
        try {
            $error = syncThrown(fn () => syncWriteAll($stream, $chunk));
            $seen[$case] = [$error === null ? null : $error::class, SyncScriptedStream::$taken];
        } finally {
            fclose($stream);
        }
    }

    expect($seen)->toBe(array_map(
        static fn (array $case): array => [IcapConnectionException::class, $case[1]],
        $cases,
    ));
});

it('looks at a cancellation between the parts of a short write', function () {
    $cancel = new DeferredCancellation();
    $stream = SyncScriptedStream::open([4, 0, 'all']);
    SyncScriptedStream::$afterWrite = static function () use ($cancel): void {
        $cancel->cancel();
    };
    try {
        $error = syncThrown(fn () => syncWriteAll($stream, '0123456789', $cancel->getCancellation()));

        expect($error)->toBeInstanceOf(CancelledException::class);
        expect(SyncScriptedStream::$taken)->toBe('0123');
    } finally {
        fclose($stream);
    }
});

it('looks at a cancellation after waiting for a peer that was slow to read', function () {
    $cancel = new DeferredCancellation();
    $stream = SyncScriptedStream::open([4, 0, 0, 'all']);
    $calls = 0;
    // The caller gives up during the write that finds the buffer full (the third answer),
    // so the look after the first part saw nothing and only the one after the wait does.
    SyncScriptedStream::$afterWrite = static function () use ($cancel, &$calls): void {
        if (++$calls === 3) {
            $cancel->cancel();
        }
    };
    try {
        $error = syncThrown(fn () => syncWriteAll($stream, '0123456789', $cancel->getCancellation()));

        expect($error)->toBeInstanceOf(CancelledException::class);
        expect(SyncScriptedStream::$taken)->toBe('0123');
    } finally {
        fclose($stream);
    }
});

it('counts the stream timeout from the last progress, not from the start of the chunk', function () {
    // Part, full, part, full, rest. Every answer takes 30 ms, so the second wait comes 90 ms after
    // the first: more than the 50 ms timeout, but only a few microseconds after the last progress.
    $stream = SyncScriptedStream::open([1, 0, 0, 1, 0, 0, 'all']);
    SyncScriptedStream::$afterWrite = static function (): void {
        usleep(30_000);
    };
    try {
        syncWriteAll($stream, '0123456789', null, 0.05);

        expect(SyncScriptedStream::$taken)->toBe('0123456789');
    } finally {
        fclose($stream);
    }
});

it('tells data, the end of the stream, a timeout and a read error apart', function () {
    // The branches a real socket reaches with `timed_out` set are covered above; a
    // scripted stream has no such flag, which leaves the zero-timeout answer
    // (an empty string, no end) and the failed read.
    $cases = [
        'data'                    => ['abc', false, 'abc'],
        'end of the stream'       => ['', true, null],
        'empty, stream not ended' => ['', false, IcapTimeoutException::class],
        'failed read'             => [false, false, IcapConnectionException::class],
    ];

    $seen = [];
    foreach ($cases as $case => [$read, $eof]) {
        $stream = SyncScriptedStream::open(read: $read, eof: $eof);
        try {
            $error = syncThrown(function () use ($stream, &$value): void {
                $value = syncReadChunk($stream);
            });
            $seen[$case] = $error === null ? $value : $error::class;
        } finally {
            fclose($stream);
        }
    }

    expect($seen)->toBe(array_map(static fn (array $case): ?string => $case[2], $cases));
});

it('splits the stream timeout into seconds and microseconds and never into a wait without end', function () {
    // PHP reads a tv_sec of -1 as "wait for ever".
    $cases = [
        '0.1'          => [0.1, [0, 100000]],
        '1.5'          => [1.5, [1, 500000]],
        '10'           => [10.0, [10, 0]],
        'rounds up'    => [2.9999996, [3, 0]],
        'zero'         => [0.0, [0, 0]],
        'negative'     => [-1.0, [0, 0]],
        'not a number' => [NAN, [0, 0]],
        'infinite'     => [INF, [0, 0]],
    ];

    $split = new ReflectionMethod(SynchronousStreamTransport::class, 'splitTimeout');
    $seen = [];
    foreach ($cases as $case => [$timeout]) {
        $seen[$case] = $split->invoke(null, $timeout);
    }

    expect($seen)->toBe(array_map(static fn (array $case): array => $case[1], $cases));
});

it('connects to nothing and asks for no chunk when the caller gave up beforehand', function () {
    [$server, $port] = syncListen();
    $child = SyncChild::start($port, 5.0, [SYNC_REQUEST], 'beforehand');
    try {
        $outcome = $child->outcome();

        expect($outcome['error'] ?? null)->toBe(CancelledException::class);
        expect($outcome['asked'])->toBe(0);
        $ready = [$server];
        $write = $except = [];
        expect(stream_select($ready, $write, $except, 0))->toBe(0);
    } finally {
        $child->stop();
        fclose($server);
    }
});

it('notices a cancellation that came in while connecting before it asks for a chunk', function () {
    [$server, $port] = syncListen();
    $child = SyncChild::start($port, 5.0, [SYNC_REQUEST], 'after-first-look');
    try {
        $outcome = $child->outcome();

        expect($outcome['error'] ?? null)->toBe(CancelledException::class);
        expect($outcome['asked'])->toBe(0);
        expect($outcome['streams'])->toBe(0);
        expect(syncPeerSaw($server))->toBe(['', true]);
    } finally {
        $child->stop();
        fclose($server);
    }
});

it('stops between two chunks and before the response is read, and closes its socket', function () {
    $cases = [
        'between two chunks'          => ['one', ['cancel' => true], 'two'],
        'before the response is read' => ['one', ['await' => true], ['cancel' => true]],
    ];

    $seen = [];
    foreach ($cases as $case => $chunks) {
        [$server, $port] = syncListen();
        $child = SyncChild::start($port, 5.0, $chunks, 'later');
        $peer = null;
        try {
            if ($case === 'before the response is read') {
                // A complete answer is waiting, and the caller gives up before it is read.
                [$peer] = syncServe($server, 3);
                fwrite($peer, SYNC_FRAME);
                $child->release();
            }

            $outcome = $child->outcome();
            $peer ??= syncServe($server, 0)[0];
            $seen[$case] = [$outcome['error'] ?? null, $outcome['streams'], syncDrain($peer)];
        } finally {
            $child->stop();
            if (is_resource($peer)) {
                fclose($peer);
            }
            fclose($server);
        }
    }

    // Nothing after the cancellation went out, the client closed its end and left no stream open.
    expect($seen)->toBe([
        'between two chunks'          => [CancelledException::class, 0, ['one', true]],
        'before the response is read' => [CancelledException::class, 0, ['', true]],
    ]);
});

/*
 * AP-12 (M10) — the end of the operation (the client hands it to the transport as its cancellation) bounds every native
 * wait, and the response has two limits: the server's time to begin its answer, and a maximum for the answer to arrive
 * in full, which no byte starts again. A peer that answers, or reads, a little at a time cannot stretch the operation.
 * The client runs in a child process the test can kill.
 */

/**
 * Accepts the waiting client and reads its request up to the blank line that ends the head.
 *
 * @param resource $server
 * @return resource the peer
 */
function syncAcceptHead($server)
{
    $peer = @stream_socket_accept($server, 5);
    Assert::assertIsResource($peer);
    stream_set_timeout($peer, 5);
    $head = '';
    while (!str_contains($head, "\r\n\r\n") && ($part = fread($peer, 4096)) !== false && $part !== '') {
        $head .= $part;
    }

    return $peer;
}

it('bounds a wait for the answer by what is left of the operation, not by the stream timeout', function () {
    [$server, $port] = syncListen();
    $child = SyncChild::start($port, 5.0, [SYNC_REQUEST], operation: 0.25);
    $peer = null;
    try {
        [$peer] = syncServe($server, strlen(SYNC_REQUEST));
        // The answer comes within the stream timeout (5 s), after the operation is over.
        usleep(700_000);
        syncSend($peer, [SYNC_FRAME]);
        $outcome = $child->outcome();

        expect($outcome['error'] ?? null)->toBe(IcapTimeoutException::class);
        // It ended at the end of the operation, not when the answer came in.
        expect($outcome['elapsed'])->toBeGreaterThanOrEqual(0.2)->toBeLessThan(0.65);
        expect($outcome['streams'])->toBe(0);
        expect($outcome['leaked'])->toBe([]);
    } finally {
        $child->stop();
        if (is_resource($peer)) {
            fclose($peer);
        }
        fclose($server);
    }
});

it('ends an answer that is dripped a byte at a time at the stream timeout from its first byte', function () {
    // The time to begin an answer is the stream timeout (0.5 s), or - the server is given five seconds to begin - much
    // longer: once the first byte is in, the answer has the stream timeout either way.
    foreach ([null, 5.0] as $responseStart) {
        [$server, $port] = syncListen();
        $child = SyncChild::start($port, 0.5, [SYNC_REQUEST], responseStart: $responseStart);
        $peer = null;
        try {
            [$peer] = syncServe($server, strlen(SYNC_REQUEST));
            // One byte every 0.15 seconds: every read finds a byte and no read waits for half a second, and the frame
            // would take eight seconds. The peer stops after two, and stays silent; it does not write to a client that
            // has gone, which PHP would call a broken pipe.
            stream_set_blocking($peer, false);
            foreach (str_split(SYNC_FRAME) as $i => $byte) {
                fread($peer, 1);
                if ($i > 12 || feof($peer)) {
                    break;
                }
                fwrite($peer, $byte);
                usleep(150_000);
            }
            $outcome = $child->outcome();

            expect($outcome['error'] ?? null)->toBe(IcapTimeoutException::class, (string) $responseStart);
            // The stream timeout from the first byte (0.5 s), not the end of the dripping, and not an idle timeout that every byte starts again.
            expect($outcome['elapsed'])->toBeGreaterThanOrEqual(0.4)->toBeLessThan(1.5, (string) $responseStart);
            expect($outcome['streams'])->toBe(0);
        } finally {
            $child->stop();
            if (is_resource($peer)) {
                fclose($peer);
            }
            fclose($server);
        }
    }
});

it('gives the server the response start timeout to begin its answer, longer than the stream timeout', function () {
    [$server, $port] = syncListen();
    $child = SyncChild::start($port, 0.3, [SYNC_REQUEST], responseStart: 2.0);
    $peer = null;
    try {
        [$peer] = syncServe($server, strlen(SYNC_REQUEST));
        usleep(600_000); // a scan: more than the stream timeout, within the time to begin
        fwrite($peer, SYNC_FRAME);
        $outcome = $child->outcome();

        expect($outcome['response'] ?? $outcome['error'] ?? null)->toBe(SYNC_FRAME);
        expect($outcome['elapsed'])->toBeGreaterThanOrEqual(0.55)->toBeLessThan(2.0);
    } finally {
        $child->stop();
        if (is_resource($peer)) {
            fclose($peer);
        }
        fclose($server);
    }
});

it('ends an answer that does not begin within the response start timeout, shorter than the stream timeout', function () {
    [$server, $port] = syncListen();
    $child = SyncChild::start($port, 5.0, [SYNC_REQUEST], responseStart: 0.3);
    $peer = null;
    try {
        [$peer] = syncServe($server, strlen(SYNC_REQUEST));
        usleep(900_000);
        syncSend($peer, [SYNC_FRAME]);
        $outcome = $child->outcome();

        expect($outcome['error'] ?? null)->toBe(IcapTimeoutException::class);
        expect($outcome['elapsed'])->toBeGreaterThanOrEqual(0.25)->toBeLessThan(0.85);
    } finally {
        $child->stop();
        if (is_resource($peer)) {
            fclose($peer);
        }
        fclose($server);
    }
});

it('ends a write to a peer that reads a little at a time at the end of the operation', function () {
    [$server, $port] = syncListen();
    // 32 MiB to a peer that takes 4 KiB every 20 ms: it makes progress all the time, so no stalled-write timeout of
    // 5 seconds ever passes, and the upload would take minutes.
    $child = SyncChild::start($port, 5.0, [['big' => 32 * 1024]], operation: 0.5);
    $peer = null;
    try {
        [$peer] = syncServe($server, 0);
        $until = microtime(true) + 1.5;
        while (microtime(true) < $until && ($part = fread($peer, 4096)) !== false && $part !== '') {
            usleep(20_000);
        }
        $outcome = $child->outcome();

        expect($outcome['error'] ?? null)->toBe(IcapTimeoutException::class);
        expect($outcome['asked'])->toBe(1);
        expect($outcome['elapsed'])->toBeGreaterThanOrEqual(0.4)->toBeLessThan(2.0);
        expect($outcome['streams'])->toBe(0);
        expect($outcome['leaked'])->toBe([]);
    } finally {
        $child->stop();
        if (is_resource($peer)) {
            fclose($peer);
        }
        fclose($server);
    }
});

it('connects to nothing and asks for no chunk when the operation has run out before the transport is called', function () {
    [$server, $port] = syncListen();
    // The operation has 10 ms, and the child spends 50 before it calls the transport.
    $child = SyncChild::start($port, 5.0, [SYNC_REQUEST], operation: 0.01, hold: 0.05);
    try {
        $outcome = $child->outcome();

        expect($outcome['error'] ?? null)->toBe(IcapTimeoutException::class);
        expect($outcome['asked'])->toBe(0);
        expect($outcome['elapsed'])->toBeLessThan(0.5);
        // Nobody connected.
        $waiting = [$server];
        $none = null;
        expect(stream_select($waiting, $none, $none, 0, 300_000))->toBe(0);
    } finally {
        $child->stop();
        fclose($server);
    }
});

it('ends an OPTIONS call of the client at its operation timeout, over the synchronous transport', function () {
    $options = "ICAP/1.0 200 OK\r\nMethods: RESPMOD\r\nEncapsulated: null-body=0\r\n\r\n";

    // [operation timeout, how long the server takes, what the client ends with]
    $cases = [
        'over the operation timeout'   => [0.25, 0.7, IcapTimeoutException::class],
        'inside the operation timeout' => [5.0, 0.1, '200'],
    ];
    foreach ($cases as $case => [$budget, $pause, $expected]) {
        [$server, $port] = syncListen();
        $child = SyncChild::start($port, 5.0, [], client: true, operationTimeout: $budget);
        $peer = null;
        try {
            $peer = syncAcceptHead($server);
            usleep((int) ($pause * 1_000_000));
            syncSend($peer, [$options]);
            $outcome = $child->outcome();

            expect($outcome['error'] ?? $outcome['response'] ?? null)->toBe($expected, $case);
            if ($expected === IcapTimeoutException::class) {
                expect($outcome['elapsed'])->toBeGreaterThanOrEqual(0.2)->toBeLessThan(0.65, $case);
            }
            expect($outcome['streams'])->toBe(0, $case);
        } finally {
            $child->stop();
            if (is_resource($peer)) {
                fclose($peer);
            }
            fclose($server);
        }
    }
});

it('ends a write to a peer that never reads at the end of the operation, with the stall timeout far away', function () {
    [$server, $port] = syncListen();
    // The stall timeout is 30 seconds and a peer that reads nothing never makes progress: only the end of the
    // operation (0.3 s) can end the wait for the buffer to drain.
    $child = SyncChild::start($port, 30.0, [['big' => 32 * 1024]], operation: 0.3);
    try {
        $outcome = $child->outcome();

        expect($outcome['error'] ?? null)->toBe(IcapTimeoutException::class);
        expect($outcome['asked'])->toBe(1);
        expect($outcome['elapsed'])->toBeGreaterThanOrEqual(0.25)->toBeLessThan(2.0);
        expect($outcome['streams'])->toBe(0);
        expect($outcome['leaked'])->toBe([]);

        [$received, $closed] = syncPeerSaw($server, 64 * 1024 * 1024);
        expect($closed)->toBeTrue();
        expect(strlen($received))->toBeGreaterThan(0);
    } finally {
        $child->stop();
        fclose($server);
    }
});

it('ends a connect that hangs at the end of the operation, with the connect timeout far away', function () {
    // A listener whose accept queue is full: the kernel drops the next attempt to connect, and that attempt hangs.
    $server = @stream_socket_server(
        'tcp://127.0.0.1:0',
        $errno,
        $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        stream_context_create(['socket' => ['backlog' => 1]]),
    );
    if ($server === false) {
        Assert::markTestSkipped('Cannot listen on 127.0.0.1.');
    }
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    // Attempts that do not wait for the answer; one that is not connected after 0.2 seconds is hanging.
    $fillers = [];
    $full = false;
    for ($i = 0; $i < 6 && !$full; $i++) {
        $filler = @stream_socket_client("tcp://127.0.0.1:{$port}", $connectErrno, $connectError, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        if ($filler === false) {
            break;
        }
        $fillers[] = $filler;
        $connected = [$filler];
        $none = null;
        $full = stream_select($none, $connected, $none, 0, 200_000) === 0;
    }

    $child = null;
    try {
        if (!$full) {
            Assert::markTestSkipped('This system lets no connection attempt hang on a full accept queue.');
        }

        // The connect timeout of the Config is its default of 10 seconds; the operation has 0.3.
        $child = SyncChild::start($port, 5.0, [SYNC_REQUEST], operation: 0.3);
        $outcome = $child->outcome(6.0);

        expect($outcome['error'] ?? null)->toBe(IcapTimeoutException::class);
        expect($outcome['asked'])->toBe(0);
        expect($outcome['elapsed'])->toBeGreaterThanOrEqual(0.25)->toBeLessThan(3.0);
        expect($outcome['streams'])->toBe(0);
    } finally {
        $child?->stop();
        foreach ($fillers as $filler) {
            fclose($filler);
        }
        fclose($server);
    }
});

/*
 * A blocking read that returned is looked at before the frame it completed is returned. Every read waits at most for
 * what is left of its phase, so what is left to check is the time between the read and the frame.
 */
it('does not return a complete answer that arrived in one read but took longer than the time for its transfer', function () {
    // 1,000 one-byte chunks: 6,081 bytes that decode in much more than the ten microseconds the transfer has.
    $frame = "ICAP/1.0 200 OK\r\nEncapsulated: res-hdr=0, res-body=19\r\n\r\n"
        . "HTTP/1.1 200 OK\r\n\r\n"
        . str_repeat("1\r\nX\r\n", 1000)
        . "0\r\n\r\n";
    // [stream timeout, how the call ends]
    $cases = ['ample' => [5.0, null], 'ten microseconds' => [0.00001, IcapTimeoutException::class]];

    foreach ($cases as $case => [$timeout, $expected]) {
        [$server, $port] = syncListen();
        $child = SyncChild::start($port, $timeout, [SYNC_REQUEST], responseStart: 5.0);
        $peer = null;
        try {
            [$peer] = syncServe($server, strlen(SYNC_REQUEST));
            // The answer is written at once and whole; the start of the answer (5 s) is far away.
            fwrite($peer, $frame);
            $outcome = $child->outcome();

            if ($expected === null) {
                expect($outcome['response'] ?? $outcome['error'] ?? null)->toBe($frame, $case);
            } else {
                expect($outcome['error'] ?? null)->toBe($expected, $case);
                // The limit of the transfer ran out, not the one for the start of the answer (5 s).
                expect($outcome['message'] ?? '')->toContain('Reading the response');
            }
            expect($outcome['streams'])->toBe(0, $case);
            expect($outcome['leaked'])->toBe([], $case);
        } finally {
            $child->stop();
            if (is_resource($peer)) {
                fclose($peer);
            }
            fclose($server);
        }
    }
});

it('lets the cancellation of the caller win over an answer that was read while they cancelled', function () {
    // [the caller cancels while the client is blocked in its read, how the call ends]
    $cases = ['not cancelled' => [false, null], 'cancelled' => [true, CancelledException::class]];

    foreach ($cases as $case => [$cancels, $expected]) {
        [$server, $port] = syncListen();
        $child = SyncChild::start($port, 5.0, [SYNC_REQUEST], cancel: 'on-signal');
        $peer = null;
        try {
            [$peer] = syncServe($server, strlen(SYNC_REQUEST));
            // The client has written its request and is blocked in the read. The caller cancels, and then the answer comes.
            usleep(200_000);
            if ($cancels) {
                $child->release();
                usleep(50_000);
            }
            fwrite($peer, SYNC_FRAME);
            $outcome = $child->outcome();

            if ($expected === null) {
                expect($outcome['response'] ?? $outcome['error'] ?? null)->toBe(SYNC_FRAME, $case);
            } else {
                expect($outcome['error'] ?? null)->toBe($expected, $case);
            }
            expect($outcome['streams'])->toBe(0, $case);
        } finally {
            $child->stop();
            if (is_resource($peer)) {
                fclose($peer);
            }
            fclose($server);
        }
    }
});

it('does not wait for an answer that never comes once the caller has given up before the response is read', function () {
    [$server, $port] = syncListen();
    // The stream timeout (3 s) would end a wait for an answer: the caller's cancellation comes first, before the read.
    $child = SyncChild::start($port, 3.0, ['one', ['await' => true], ['cancel' => true]], 'later');
    $peer = null;
    try {
        [$peer] = syncServe($server, 3);
        $child->release();
        $outcome = $child->outcome();

        expect($outcome['error'] ?? null)->toBe(CancelledException::class)
            ->and($outcome['elapsed'])->toBeLessThan(1.5)
            ->and($outcome['streams'])->toBe(0);
    } finally {
        $child->stop();
        if (is_resource($peer)) {
            fclose($peer);
        }
        fclose($server);
    }
});

/*
 * A read that returned is looked at before what it brought is told: bytes, the end of the stream and a failure alike.
 * A caller who cancelled while the client was blocked is not answered with a framing error, and a phase that has run
 * out is a timeout, whatever the read brought.
 */
it('lets the cancellation of the caller win over the end of the stream that came while they cancelled', function () {
    // [the caller cancels while the client is blocked in its read, how the call ends]: the peer hangs up either way
    $cases = ['not cancelled' => [false, IcapMalformedResponseException::class], 'cancelled' => [true, CancelledException::class]];

    foreach ($cases as $case => [$cancels, $expected]) {
        [$server, $port] = syncListen();
        $child = SyncChild::start($port, 5.0, [SYNC_REQUEST], cancel: 'on-signal');
        $peer = null;
        try {
            [$peer] = syncServe($server, strlen(SYNC_REQUEST));
            // The client has written its request and is blocked in the read. The caller cancels, and then the peer is gone.
            usleep(200_000);
            if ($cancels) {
                $child->release();
                usleep(50_000);
            }
            fclose($peer);
            $outcome = $child->outcome();

            expect($outcome['error'] ?? null)->toBe($expected, $case);
            expect($outcome['streams'])->toBe(0, $case);
        } finally {
            $child->stop();
            if (is_resource($peer)) {
                fclose($peer);
            }
            fclose($server);
        }
    }
});

it('calls a read that returned after the end of its phase a timeout, whatever it brought, and leaves the cancellation of the caller first', function () {
    // [what the read answers, whether the stream has ended, microseconds the read lasts, whether the caller cancels in
    // it, what the call gives]: the phase has 30 ms
    $cases = [
        'data in time'              => ['abc', false, 0, false, 'abc'],
        'end of the stream in time' => ['', true, 0, false, null],
        'data late'                 => ['abc', false, 60_000, false, IcapTimeoutException::class],
        'end of the stream late'    => ['', true, 60_000, false, IcapTimeoutException::class],
        'failed read late'          => [false, false, 60_000, false, IcapTimeoutException::class],
        'cancelled and late'        => ['abc', false, 60_000, true, CancelledException::class],
    ];

    $readChunk = new ReflectionMethod(SynchronousStreamTransport::class, 'readChunk');
    $seen = [];
    foreach ($cases as $case => [$read, $eof, $lasts, $cancels]) {
        $stream = SyncScriptedStream::open(read: $read, eof: $eof);
        $caller = new DeferredCancellation();
        SyncScriptedStream::$onRead = static function () use ($lasts, $cancels, $caller): void {
            usleep($lasts);
            if ($cancels) {
                $caller->cancel();
            }
        };
        try {
            $error = syncThrown(function () use ($readChunk, $stream, $caller, &$value): void {
                $value = $readChunk->invoke(null, $stream, $caller->getCancellation(), 0.03, hrtime(true) + 3.0e7);
            });
            $seen[$case] = $error === null ? $value : $error::class;
        } finally {
            fclose($stream);
        }
    }

    expect($seen)->toBe(array_map(static fn (array $case): ?string => $case[4], $cases));
});

/*
 * The frame is complete only after it was decoded, and the caller can cancel while that happens. If the limit of the
 * transfer ran out as well, the cancellation of the caller comes first, as everywhere else.
 */
it('lets the cancellation of the caller win over the limit of the transfer when both came while the frame was decoded', function () {
    // [stream timeout, whether the caller cancels while the frame is decoded, how the call ends]: the limit of the
    // transfer begins with the read that brings the whole frame, and one of 0 seconds is over when the frame is complete
    $cases = [
        'in time'                    => [5.0, false, SYNC_FRAME],
        'limit passed'               => [0.0, false, IcapTimeoutException::class],
        'limit passed and cancelled' => [0.0, true, CancelledException::class],
    ];

    $seen = [];
    foreach ($cases as $case => [$timeout, $cancels]) {
        [$server, $port] = syncListen();
        $caller = new SyncCancelsWhileDecoding($cancels);
        $peer = null;
        // The answer is in the socket before the client asks for it: a single read brings the whole frame.
        $request = (static function () use ($server, &$peer, $caller) {
            yield SYNC_REQUEST;
            [$peer] = syncServe($server, 0);
            fwrite($peer, SYNC_FRAME);
            $caller->requestIsOut();
        })();
        try {
            $config = new Config('127.0.0.1', $port, streamTimeout: $timeout, responseStartTimeout: 5.0);
            $response = null;
            $error = syncThrown(function () use ($config, $request, $caller, &$response): void {
                $response = (new SynchronousStreamTransport())->request($config, $request, $caller)->await();
            });
            $seen[$case] = $error === null ? $response : $error::class;
        } finally {
            if (is_resource($peer)) {
                fclose($peer);
            }
            fclose($server);
        }
    }

    expect($seen)->toBe(array_map(static fn (array $case): string => $case[2], $cases));
});

/*
 * AP-15 (M02): the same exchange with a response that is received and not kept. The client blocks in a child
 * process, as above; the test plays the server, here with a response as large as an echo of a large file.
 */
it('receives a response of more than the response limit without keeping it, and refuses it when it is kept', function () {
    $size = 12 * 1024 * 1024;

    // [what the child asks of the transport, what it ends with]
    foreach (['kept' => null, 'not kept' => 4 * $size] as $how => $budget) {
        [$server, $port] = syncListen();
        $child = SyncChild::start($port, 30.0, [SYNC_REQUEST], discard: $budget);
        $peer = null;
        try {
            [$peer] = syncServe($server, strlen(SYNC_REQUEST));
            syncSend($peer, \Ndrstmr\Icap\Tests\IcapTestServer::echo($size));
            $outcome = $child->outcome(30.0);

            if ($budget === null) {
                // The default limit of the configuration is 10 MiB.
                expect($outcome['error'] ?? null)->toBe(IcapMalformedResponseException::class, $how);
            } else {
                expect($outcome['status'] ?? null)->toBe(200, $how)
                    ->and($outcome['discarded'] ?? null)->toBe($size, $how)
                    ->and($outcome['body'] ?? null)->toBe('', $how)
                    ->and($outcome['streams'])->toBe(0, $how);
            }
        } finally {
            $child->stop();
            if (is_resource($peer)) {
                fclose($peer);
            }
            fclose($server);
        }
    }
});

it('refuses a response that is cut, illegal at its end or over its budget, over the synchronous transport', function () {
    $size = 3 * 1024 * 1024;
    $parts = iterator_to_array(\Ndrstmr\Icap\Tests\IcapTestServer::echo($size), false);
    $last = count($parts) - 1;
    $whole = strlen(implode('', $parts));

    // label => [what the server writes, the budget, whether it hangs up, what the client ends with]
    $cases = [
        'a legal response, budget to the byte' => [$parts, $whole, false, 200],
        'one byte over the budget'             => [$parts, $whole - 1, false, IcapMalformedResponseException::class],
        'an invalid trailer'                   => [[...array_slice($parts, 0, $last), "0\r\nnot a field\r\n\r\n"], 8 * $size, false, IcapMalformedResponseException::class],
        'no CRLF behind the last data'         => [[...array_slice($parts, 0, $last - 1), substr($parts[$last - 1], 0, -2) . 'ZZ', $parts[$last]], 8 * $size, false, IcapMalformedResponseException::class],
        'the stream ends inside the body'      => [array_slice($parts, 0, 20), 8 * $size, true, IcapMalformedResponseException::class],
    ];

    foreach ($cases as $case => [$write, $budget, $hangUp, $expected]) {
        [$server, $port] = syncListen();
        $child = SyncChild::start($port, 30.0, [SYNC_REQUEST], discard: $budget);
        $peer = null;
        try {
            [$peer] = syncServe($server, strlen(SYNC_REQUEST));
            syncSend($peer, $write);
            if ($hangUp) {
                fclose($peer);
                $peer = null;
            }
            $outcome = $child->outcome(30.0);

            expect($outcome['error'] ?? $outcome['status'] ?? null)->toBe($expected, $case);
        } finally {
            $child->stop();
            if (is_resource($peer)) {
                fclose($peer);
            }
            fclose($server);
        }
    }
});

it('refuses bytes the server sent behind a response, over the synchronous transport', function () {
    $stray = 'ICAP/1.0 204 No Con';
    $parts = iterator_to_array(\Ndrstmr\Icap\Tests\IcapTestServer::echo(64 * 1024), false);
    $last = array_pop($parts);

    // label => the writes of the server; the end of the response and the bytes behind it go out in one write, after a pause
    $cases = [
        'behind a 200' => [...$parts, $last . $stray],
        'behind a 204' => [\Ndrstmr\Icap\Tests\IcapTestServer::CLEAN . $stray],
    ];

    foreach ($cases as $case => $write) {
        [$server, $port] = syncListen();
        $child = SyncChild::start($port, 30.0, [SYNC_REQUEST], discard: 1 << 20);
        $peer = null;
        try {
            [$peer] = syncServe($server, strlen(SYNC_REQUEST));
            foreach ($write as $i => $part) {
                if ($i === count($write) - 1 && $i > 0) {
                    // What came before has been read when the last write arrives.
                    usleep(100_000);
                }
                fwrite($peer, $part);
            }
            $outcome = $child->outcome(30.0);

            expect($outcome['error'] ?? $outcome['status'] ?? null)->toBe(IcapMalformedResponseException::class, $case);
        } finally {
            $child->stop();
            if (is_resource($peer)) {
                fclose($peer);
            }
            fclose($server);
        }
    }
});
