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

use Amp\Socket;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\NullConnectionPool;
use Ndrstmr\Icap\Transport\TransportInterface;
use PHPUnit\Framework\Assert;

use function Amp\async;

it('implements TransportInterface', function () {
    $t = new AsyncAmpTransport();
    expect($t)->toBeInstanceOf(TransportInterface::class);
});

it('throws connection exception on invalid host', function () {
    $t = new AsyncAmpTransport();
    $config = new Config('127.0.0.1', 1); // unlikely to be open

    expect(fn () => $t->request($config, [])->await())->toThrow(IcapConnectionException::class);
});

it('connects to an IPv6 literal host, with and without a pool (N04)', function () {
    try {
        $server = Socket\listen('[::1]:0');
    } catch (Socket\SocketException) {
        Assert::markTestSkipped('IPv6 loopback is not available.');
    }
    $address = $server->getAddress();
    \assert($address instanceof Socket\InternetAddress);
    $config = new Config('::1', $address->getPort());

    // One connection per transport, each answered with a bare 204.
    $answering = async(function () use ($server): void {
        while (($peer = $server->accept()) !== null) {
            $head = '';
            while (!str_contains($head, "\r\n\r\n") && ($chunk = $peer->read()) !== null) {
                $head .= $chunk;
            }
            $peer->write("ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n");
        }
    });

    $pool = new AmpConnectionPool();
    try {
        foreach ([new AsyncAmpTransport(), new AsyncAmpTransport(new NullConnectionPool()), new AsyncAmpTransport($pool)] as $transport) {
            $response = $transport->request($config, ["OPTIONS icap://[::1]/scan ICAP/1.0\r\nEncapsulated: null-body=0\r\n\r\n"])->await();
            expect($response)->toStartWith('ICAP/1.0 204 No Content');
        }
    } finally {
        $pool->close();
        $server->close();
    }
    $answering->await();
});

/**
 * M06 — request() returns the raw response string, so nothing has checked
 * status or verdict when the transport would give the socket back. It
 * closes the socket, with a pool as well: the pool must connect anew.
 */
it('closes the socket after request() instead of returning it to the pool', function () {
    $replies = [
        'plain 204'         => "ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n",
        'interim 100'       => "ICAP/1.0 100 Continue\r\n\r\n",
        'keep-alive, close' => "ICAP/1.0 204 No Content\r\nConnection: keep-alive, close\r\nEncapsulated: null-body=0\r\n\r\n",
    ];

    foreach ($replies as $case => $reply) {
        [$serverEnd, $clientEnd] = Socket\createSocketPair();
        $connections = 0;
        $pool = new AmpConnectionPool(
            connector: function () use ($clientEnd, &$connections) {
                $connections++;
                return $clientEnd;
            },
        );
        $transport = new AsyncAmpTransport($pool);
        $config = new Config('icap.example', streamTimeout: 2.0);

        $answering = async(function () use ($serverEnd, $reply): void {
            $head = '';
            while (!str_contains($head, "\r\n\r\n") && ($chunk = $serverEnd->read()) !== null) {
                $head .= $chunk;
            }
            $serverEnd->write($reply);
        });

        try {
            $response = $transport->request($config, ["OPTIONS icap://icap.example/scan ICAP/1.0\r\nEncapsulated: null-body=0\r\n\r\n"])->await();
            $answering->await();
            expect($response)->toBe($reply, $case);

            // Closed, not just missing from the pool: a socket left open
            // and unreferenced would pass the connection count as well.
            expect($clientEnd->isClosed())->toBeTrue($case);
            $pool->acquire($config);
            expect($connections)->toBe(2, $case);
        } finally {
            $clientEnd->close();
            $serverEnd->close();
            $pool->close();
        }
    }
});
