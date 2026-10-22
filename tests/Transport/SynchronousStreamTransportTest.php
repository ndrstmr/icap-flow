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
 * descriptors), runs the transport and writes one JSON line back. A chunk is a
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
$started = hrtime(true);
try {
    $out = ['response' => (new \Ndrstmr\Icap\Transport\SynchronousStreamTransport())
        ->request(new \Ndrstmr\Icap\Config($job['host'], $job['port'], streamTimeout: (float) $job['timeout']), $chunks, $cancellation)
        ->await()];
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
     * @param 'beforehand'|'later'|'after-first-look'|null $cancel
     */
    public static function start(int $port, float $timeout, array $chunks, ?string $cancel = null, string $host = '127.0.0.1', int $descriptors = 0): self
    {
        $child = new self();
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-r', SYNC_CHILD, '--', dirname(__DIR__, 2) . '/vendor/autoload.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $child->pipes,
        );
        Assert::assertIsResource($process);
        $child->process = $process;
        fwrite($child->pipes[0], json_encode(compact('host', 'port', 'timeout', 'chunks', 'cancel', 'descriptors'), JSON_THROW_ON_ERROR) . "\n");
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
     * @return array{response?: string, error?: class-string, message?: string, elapsed: float, asked: int, streams: int, leaked: list<string>}
     */
    public function outcome(float $within = 10.0): array
    {
        $outcome = $this->reply($within);

        /** @var array{response?: string, error?: class-string, message?: string, elapsed: float, asked: int, streams: int, leaked: list<string>} $outcome */
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
        return self::$read;
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
