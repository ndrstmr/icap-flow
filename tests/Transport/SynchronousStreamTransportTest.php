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

use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Exception\IcapConnectionException;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;
use Ndrstmr\Icap\Transport\SynchronousStreamTransport;
use Ndrstmr\Icap\Transport\TransportInterface;
use PHPUnit\Framework\Assert;

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
    $server = @stream_socket_server('tcp://[::1]:0');
    if ($server === false) {
        Assert::markTestSkipped('IPv6 loopback is not available.');
    }
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    try {
        // Nobody can answer on this thread while the blocking client
        // waits, so a zero stream timeout makes it give up at once. The
        // request bytes on the server side show where it connected.
        expect(fn () => (new SynchronousStreamTransport())
            ->request(new Config('::1', $port, streamTimeout: 0.0), ["OPTIONS icap://[::1]/scan ICAP/1.0\r\n\r\n"])
            ->await())
            ->toThrow(IcapMalformedResponseException::class);

        $peer = stream_socket_accept($server, 0);
        \assert(is_resource($peer));
        expect(fread($peer, 1024))->toBe("OPTIONS icap://[::1]/scan ICAP/1.0\r\n\r\n");
        fclose($peer);
    } finally {
        fclose($server);
    }
});
