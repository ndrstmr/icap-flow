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

use Amp\Socket\ClientTlsContext;
use Ndrstmr\Icap\Config;

test('Config can be instantiated', function () {
    $config = new Config('icap.example');
    expect($config)->toBeInstanceOf(Config::class)
        ->and($config->host)->toBe('icap.example')
        ->and($config->port)->toBe(1344)
        ->and($config->getSocketTimeout())->toBe(10.0)
        ->and($config->getStreamTimeout())->toBe(10.0);
});

test('virus header can be customized', function () {
    $config = new Config('icap.example');
    $new = $config->withVirusFoundHeader('X-Infection-Found');

    expect($new)->not->toBe($config)
        ->and($new->getVirusFoundHeader())->toBe('X-Infection-Found')
        ->and($config->getVirusFoundHeader())->toBe('X-Virus-Name');
});

/**
 * N01: what a deployment actually configures keeps working.
 */
it('accepts the addresses, timeouts and header names a deployment uses', function () {
    foreach (['icap.example', 'localhost', 'icap-1.example.internal', '10.0.0.5', '::1', '2001:db8::1', '[::1]'] as $host) {
        expect((new Config($host))->host)->toBe($host, $host);
    }
    foreach ([1, 1344, 11344, 65535] as $port) {
        expect((new Config('icap.example', $port))->port)->toBe($port, (string) $port);
    }

    // Sub-second timeouts are valid. A stream timeout of 0 is the immediate
    // deadline of the transports, not "no limit", and stays valid.
    $config = new Config('icap.example', 1344, 0.25, 0.0);
    expect($config->getSocketTimeout())->toBe(0.25)
        ->and($config->getStreamTimeout())->toBe(0.0);
    $tiny = new Config('icap.example', socketTimeout: 0.001, streamTimeout: 0.001);
    expect($tiny->getSocketTimeout())->toBe(0.001)
        ->and($tiny->getStreamTimeout())->toBe(0.001);

    $smallest = new Config('icap.example', maxResponseSize: 1, maxHeaderCount: 1, maxHeaderLineLength: 1);
    expect([$smallest->getMaxResponseSize(), $smallest->getMaxHeaderCount(), $smallest->getMaxHeaderLineLength()])
        ->toBe([1, 1, 1]);

    // Header names are tokens, kept in the order given; the single name seeds the list.
    $names = ['X-Virus-Name', 'X-Violations-Found', 'X_Vendor.Id', 'X-Vendor~1'];
    expect((new Config('icap.example', virusFoundHeaders: $names))->getVirusFoundHeaders())->toBe($names)
        ->and((new Config('icap.example'))->withVirusFoundHeaders($names)->getVirusFoundHeaders())->toBe($names)
        ->and((new Config('icap.example', virusFoundHeader: 'X-Infection-Found'))->getVirusFoundHeaders())->toBe(['X-Infection-Found'])
        ->and((new Config('icap.example'))->getVirusFoundHeader())->toBe('X-Virus-Name');
});

it('keeps every value and the order of the header names across the withers', function () {
    $tls = new ClientTlsContext('icap.example');
    $config = new Config(
        host: '2001:db8::1',
        port: 11344,
        socketTimeout: 2.5,
        streamTimeout: 0.0,
        tlsContext: $tls,
        maxResponseSize: 1000,
        maxHeaderCount: 7,
        maxHeaderLineLength: 99,
        virusFoundHeaders: ['X-One', 'X-Two'],
        operationTimeout: 33.0,
        tlsHandshakeTimeout: 4.5,
        responseStartTimeout: 120.0,
    );
    $snapshot = static fn (Config $c): array => [
        $c->host, $c->port, $c->getSocketTimeout(), $c->getStreamTimeout(), $c->getTlsContext(),
        $c->getMaxResponseSize(), $c->getMaxHeaderCount(), $c->getMaxHeaderLineLength(),
        $c->getVirusFoundHeaders(), $c->getVirusFoundHeader(),
        $c->getOperationTimeout(), $c->getTlsHandshakeTimeout(), $c->getResponseStartTimeout(),
    ];
    $before = $snapshot($config);
    $other = new ClientTlsContext('other.example');

    // Each wither changes what it is for and nothing else.
    expect($snapshot($config->withTlsContext($other)))->toBe(array_replace($before, [4 => $other]))
        ->and($snapshot($config->withTlsContext(null)))->toBe(array_replace($before, [4 => null]))
        ->and($snapshot($config->withLimits(maxResponseSize: 5)))->toBe(array_replace($before, [5 => 5]))
        ->and($snapshot($config->withLimits(maxHeaderCount: 6, maxHeaderLineLength: 8)))->toBe(array_replace($before, [6 => 6, 7 => 8]))
        ->and($snapshot($config->withLimits()))->toBe($before)
        ->and($snapshot($config->withVirusFoundHeaders(['X-Three', 'X-Four'])))->toBe(array_replace($before, [8 => ['X-Three', 'X-Four'], 9 => 'X-Three']))
        ->and($snapshot($config->withVirusFoundHeader('X-Five')))->toBe(array_replace($before, [8 => ['X-Five'], 9 => 'X-Five']))
        // The three timeouts of M10 survive every other wither, and withTimeouts() changes what it is given and nothing else.
        ->and($snapshot($config->withTimeouts(operationTimeout: 9.0)))->toBe(array_replace($before, [10 => 9.0]))
        ->and($snapshot($config->withTimeouts(tlsHandshakeTimeout: 1.5, responseStartTimeout: 2.5)))->toBe(array_replace($before, [11 => 1.5, 12 => 2.5]))
        ->and($snapshot($config->withTimeouts()))->toBe($before);

    // The original is as it was.
    expect($snapshot($config))->toBe($before);
});

/**
 * M10: one operation has one end, and the phases that have no setting of their own use the one they always had.
 */
it('limits an operation to 60 seconds by default and lets the TLS handshake and the response start follow the stream timeout', function () {
    $config = new Config('icap.example', streamTimeout: 7.0);

    expect($config->getOperationTimeout())->toBe(60.0)
        ->and($config->getTlsHandshakeTimeout())->toBe(7.0)
        ->and($config->getResponseStartTimeout())->toBe(7.0);

    // A fallback is not a copy: it follows the stream timeout it stands for, an own value does not.
    $own = new Config('icap.example', streamTimeout: 7.0, operationTimeout: 5.0, tlsHandshakeTimeout: 2.0, responseStartTimeout: 90.0);
    expect($own->getOperationTimeout())->toBe(5.0)
        ->and($own->getTlsHandshakeTimeout())->toBe(2.0)
        ->and($own->getResponseStartTimeout())->toBe(90.0);

    // A stream timeout of 0 is the immediate deadline of the transports, and the phases that follow it give up at once too.
    $immediate = new Config('icap.example', streamTimeout: 0.0);
    expect($immediate->getTlsHandshakeTimeout())->toBe(0.0)
        ->and($immediate->getResponseStartTimeout())->toBe(0.0);

    // The fallback is not frozen into the Config: a value that was never set stays unset across a wither.
    $unset = new Config('icap.example', socketTimeout: 3.0, streamTimeout: 4.0);
    expect($unset->withTimeouts(operationTimeout: 8.0)->getResponseStartTimeout())->toBe(4.0)
        ->and($unset->withTimeouts(operationTimeout: 8.0)->getTlsHandshakeTimeout())->toBe(4.0)
        ->and($unset->withLimits(maxResponseSize: 5)->getOperationTimeout())->toBe(60.0);
});
