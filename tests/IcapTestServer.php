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

declare(strict_types=1);

namespace Ndrstmr\Icap\Tests;

use Amp\Socket;
use Amp\Socket\Socket as SocketPeer;
use Amp\Socket\ServerSocket;

use function Amp\async;

/**
 * A local ICAP server for tests that need real sockets: it takes in a request to its last byte without keeping its
 * body (after a preview that is not complete it sends the first answer, and reads the rest of the body if that answer
 * is `100 Continue`), and then writes what the test tells it to. The answer is a generator of strings, so a response of
 * a gigabyte is produced as it is written.
 *
 *     $server = IcapTestServer::start(fn (array $request) => IcapTestServer::echo($request['bodyBytes']));
 *     ... connect to 127.0.0.1:$server->port ...
 *     $server->stop();
 *
 * A request is described to the callback as: `stage` (`single`, `preview` or `rest`), `headers` (ICAP headers, names in
 * lower case), `layout` (the offsets of its Encapsulated header), `bodyBytes` (the decoded body bytes so far, preview
 * and rest together) and `continued` (the server answered the preview with `100 Continue`).
 *
 * @phpstan-type Request array{stage: string, headers: array<string, string>, layout: array<string, int>, bodyBytes: int, continued: bool}
 */
final class IcapTestServer
{
    public int $port;

    /** @var list<Request> */
    public array $requests = [];

    public int $connections = 0;

    /** @var list<SocketPeer> */
    private array $peers = [];

    /** @var \Amp\Future<mixed> */
    private \Amp\Future $accepting;

    /**
     * @param \Closure(Request): iterable<string> $respond
     */
    private function __construct(private readonly ServerSocket $server, private readonly \Closure $respond)
    {
        $address = $server->getAddress();
        \assert($address instanceof Socket\InternetAddress);
        $this->port = $address->getPort();
        $this->accepting = async(function (): void {
            while (($peer = $this->server->accept()) !== null) {
                $this->connections++;
                $this->peers[] = $peer;
                async(fn () => $this->serve($peer))->ignore();
            }
        });
    }

    /**
     * @param \Closure(Request): iterable<string> $respond
     */
    public static function start(\Closure $respond): self
    {
        return new self(Socket\listen('127.0.0.1:0'), $respond);
    }

    public function stop(): void
    {
        $this->server->close();
        foreach ($this->peers as $peer) {
            $peer->close();
        }
        $this->accepting->await();
    }

    /**
     * A 200 answer whose body is `$bytes` long, in chunks of `$chunkSize`: the shape of a server that echoes
     * the message it was sent (RFC 3507 §4.6). The last chunk and the trailer end it.
     *
     * @return \Generator<int, string>
     */
    public static function echo(int $bytes, int $chunkSize = 65536, string $icapHeaders = ''): \Generator
    {
        $head = "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: {$bytes}\r\n\r\n";
        yield "ICAP/1.0 200 OK\r\n{$icapHeaders}Encapsulated: res-hdr=0, res-body=" . strlen($head) . "\r\n\r\n" . $head;

        $block = str_repeat('x', $chunkSize);
        for ($left = $bytes; $left > 0; $left -= $chunkSize) {
            $size = min($chunkSize, $left);
            yield dechex($size) . "\r\n" . ($size === $chunkSize ? $block : substr($block, 0, $size)) . "\r\n";
        }

        yield "0\r\n\r\n";
    }

    /** Yielded by an answer: the server closes the connection there. */
    public const string CLOSE = "\0close";

    public const string CLEAN = "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n";

    public const string CONTINUE = "ICAP/1.0 100 Continue\r\nEncapsulated: null-body=0\r\n\r\n";

    private function serve(SocketPeer $peer): void
    {
        $buffer = '';
        try {
            while (!$peer->isClosed()) {
                // The request line; blank lines between requests are skipped.
                if ($this->line($peer, $buffer) === '') {
                    continue;
                }
                $request = $this->head($peer, $buffer);

                // An OPTIONS request has no body to take in.
                if (array_intersect(array_keys($request['layout']), ['req-body', 'res-body', 'opt-body']) !== []) {
                    $this->take($peer, $buffer, $request);
                }
                $this->answer($peer, $request);

                if ($request['stage'] === 'preview' && $request['continued']) {
                    $request['stage'] = 'rest';
                    $this->take($peer, $buffer, $request);
                    $this->answer($peer, $request);
                }
            }
        } catch (\Throwable) {
            // The client went away, or the test stopped the server.
        } finally {
            $peer->close();
        }
    }

    /**
     * @return Request
     */
    private function head(SocketPeer $peer, string &$buffer): array
    {
        $headers = [];
        while (($line = $this->line($peer, $buffer)) !== '') {
            [$name, $value] = explode(':', $line, 2) + [1 => ''];
            $headers[strtolower($name)] = trim($value);
        }

        $layout = [];
        foreach (explode(',', $headers['encapsulated'] ?? '') as $entry) {
            if (preg_match('/^\s*([a-z-]+)=(\d+)\s*$/', $entry, $m) === 1) {
                $layout[$m[1]] = (int) $m[2];
            }
        }

        // The encapsulated header section, if any, is not chunked.
        $this->skip($peer, $buffer, isset($layout['res-hdr'], $layout['res-body']) ? $layout['res-body'] - $layout['res-hdr'] : 0);

        return [
            'stage'     => isset($headers['preview']) ? 'preview' : 'single',
            'headers'   => $headers,
            'layout'    => $layout,
            'bodyBytes' => 0,
            'continued' => false,
        ];
    }

    /**
     * Reads chunks until the last one, counting their data and dropping it.
     *
     * @param Request $request
     */
    private function take(SocketPeer $peer, string &$buffer, array &$request): void
    {
        do {
            $length = (int) hexdec(trim(explode(';', $this->line($peer, $buffer), 2)[0]));
            if ($length > 0) {
                $this->skip($peer, $buffer, $length + 2);
                $request['bodyBytes'] += $length;
            }
        } while ($length > 0);

        // The blank line that ends the trailer.
        $this->line($peer, $buffer);
    }

    /**
     * @param Request $request
     */
    private function answer(SocketPeer $peer, array &$request): void
    {
        $this->requests[] = $request;
        $first = true;
        foreach (($this->respond)($request) as $bytes) {
            if ($first && str_starts_with($bytes, 'ICAP/1.0 100')) {
                $request['continued'] = true;
            }
            $first = false;
            if ($bytes === self::CLOSE) {
                $peer->close();

                return;
            }
            $peer->write($bytes);
        }
    }

    /**
     * The next line without its CRLF.
     *
     * @throws \RuntimeException When the client has closed the connection
     */
    private function line(SocketPeer $peer, string &$buffer): string
    {
        while (($end = strpos($buffer, "\r\n")) === false) {
            $buffer .= $this->more($peer);
        }
        $line = substr($buffer, 0, $end);
        $buffer = substr($buffer, $end + 2);

        return $line;
    }

    /**
     * Passes over the next $length bytes without keeping them.
     */
    private function skip(SocketPeer $peer, string &$buffer, int $length): void
    {
        while ($length > 0) {
            $buffer = $buffer === '' ? $this->more($peer) : $buffer;
            $take = min($length, strlen($buffer));
            $buffer = substr($buffer, $take);
            $length -= $take;
        }
    }

    private function more(SocketPeer $peer): string
    {
        return $peer->read() ?? throw new \RuntimeException('The client closed the connection.');
    }
}
