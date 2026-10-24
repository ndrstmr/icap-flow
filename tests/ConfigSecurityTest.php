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

use Amp\Socket\ClientTlsContext;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\IcapClient;

/**
 * Finding J / N: Config must expose TLS and DoS-limit knobs.
 */

it('defaults TLS to off and exposes a context setter', function () {
    $cfg = new Config('icap.example');
    expect($cfg->getTlsContext())->toBeNull();

    $tls = new ClientTlsContext('icap.example');
    $secure = $cfg->withTlsContext($tls);

    expect($secure->getTlsContext())->toBe($tls)
        ->and($cfg->getTlsContext())->toBeNull(); // original untouched
});

it('exposes sensible DoS-limit defaults', function () {
    $cfg = new Config('icap.example');
    expect($cfg->getMaxResponseSize())->toBe(10 * 1024 * 1024)
        ->and($cfg->getMaxHeaderCount())->toBe(100)
        ->and($cfg->getMaxHeaderLineLength())->toBe(8192);
});

it('accepts tuned DoS limits', function () {
    $cfg = (new Config('icap.example'))->withLimits(
        maxResponseSize: 42,
        maxHeaderCount: 5,
        maxHeaderLineLength: 64,
    );

    expect($cfg->getMaxResponseSize())->toBe(42)
        ->and($cfg->getMaxHeaderCount())->toBe(5)
        ->and($cfg->getMaxHeaderLineLength())->toBe(64);
});

it('rejects zero / negative limits', function () {
    expect(fn () => (new Config('x'))->withLimits(maxResponseSize: 0))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => (new Config('x'))->withLimits(maxHeaderCount: 0))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => (new Config('x'))->withLimits(maxHeaderLineLength: 0))
        ->toThrow(InvalidArgumentException::class);
});

/*
 * N01: the constructor is the one place that checks a configuration. The
 * withers and the factories build their result through it, so a Config never
 * holds a value that only fails once a request is made, and no wither can
 * produce what the constructor refuses.
 */

/**
 * Header name lists a Config must refuse, each with the case it stands for.
 *
 * @return array<string, array<mixed>>
 */
function invalidVirusHeaderNames(): array
{
    return [
        'no header name'                   => [],
        'empty header name'                => [''],
        'header name with a space'         => ['X Virus'],
        'header name with a colon'         => ['X-Virus:'],
        'header name with a line break'    => ["X-Virus\r\nX-Injected: 1"],
        'header name with a final newline' => ["X-Virus-Name\n"],
        'header name that is no string'    => [42],
        'header name that is null'         => [null],
        'list inside the list'             => [['X-Virus-Name']],
        'bad name after a good one'        => ['X-Virus-Name', 'X Virus'],
        'string keys'                      => ['vendor' => 'X-Virus-Name'],
        'gap in the keys'                  => [0 => 'X-Virus-Name', 2 => 'X-Violations-Found'],
    ];
}

it('refuses a configuration that cannot work, in the constructor', function () {
    // Wrong types go through a closure without a parameter type, like a caller no static analysis checks.
    $withNames = fn ($names) => new Config('icap.example', virusFoundHeaders: $names);

    // case => [build, the setting the message names]
    $cases = [
        'empty host'                        => [fn () => new Config(''), 'host'],
        'host with a path'                  => [fn () => new Config('scanner.example/other'), 'host'],
        'host with a query'                 => [fn () => new Config('scanner.example?query='), 'host'],
        'host with a fragment'              => [fn () => new Config('scanner.example#fragment'), 'host'],
        'host with user info'               => [fn () => new Config('user@scanner.example'), 'host'],
        'host with a space'                 => [fn () => new Config('scanner example'), 'host'],
        'host with a line break'            => [fn () => new Config("scanner.example\r\nevil"), 'host'],
        'host with a final newline'         => [fn () => new Config("scanner.example\n"), 'host'],
        'host with a port'                  => [fn () => new Config('scanner.example:1344'), 'host'],
        'host with a scheme'                => [fn () => new Config('icap://scanner.example'), 'host'],
        'unclosed IPv6 literal'             => [fn () => new Config('[::1'), 'host'],
        'bracketed text that is no address' => [fn () => new Config('[scanner]'), 'host'],

        'port 0'                            => [fn () => new Config('icap.example', 0), 'port'],
        'negative port'                     => [fn () => new Config('icap.example', -1), 'port'],
        'port above 65535'                  => [fn () => new Config('icap.example', 65536), 'port'],
        'largest int as port'               => [fn () => new Config('icap.example', PHP_INT_MAX), 'port'],

        'socket timeout 0'                  => [fn () => new Config('icap.example', socketTimeout: 0.0), 'socketTimeout'],
        'negative socket timeout'           => [fn () => new Config('icap.example', socketTimeout: -1.0), 'socketTimeout'],
        'socket timeout NAN'                => [fn () => new Config('icap.example', socketTimeout: NAN), 'socketTimeout'],
        'socket timeout INF'                => [fn () => new Config('icap.example', socketTimeout: INF), 'socketTimeout'],
        'socket timeout -INF'               => [fn () => new Config('icap.example', socketTimeout: -INF), 'socketTimeout'],
        'negative stream timeout'           => [fn () => new Config('icap.example', streamTimeout: -0.001), 'streamTimeout'],
        'stream timeout NAN'                => [fn () => new Config('icap.example', streamTimeout: NAN), 'streamTimeout'],
        'stream timeout INF'                => [fn () => new Config('icap.example', streamTimeout: INF), 'streamTimeout'],
        'stream timeout -INF'               => [fn () => new Config('icap.example', streamTimeout: -INF), 'streamTimeout'],

        // M10: the new timeouts are finite and above 0 (0 is no way to say "without limit"), or null for the ones that fall back.
        'operation timeout 0'               => [fn () => new Config('icap.example', operationTimeout: 0.0), 'operationTimeout'],
        'negative operation timeout'        => [fn () => new Config('icap.example', operationTimeout: -1.0), 'operationTimeout'],
        'operation timeout NAN'             => [fn () => new Config('icap.example', operationTimeout: NAN), 'operationTimeout'],
        'operation timeout INF'             => [fn () => new Config('icap.example', operationTimeout: INF), 'operationTimeout'],
        'operation timeout -INF'            => [fn () => new Config('icap.example', operationTimeout: -INF), 'operationTimeout'],
        'TLS handshake timeout 0'           => [fn () => new Config('icap.example', tlsHandshakeTimeout: 0.0), 'tlsHandshakeTimeout'],
        'negative TLS handshake timeout'    => [fn () => new Config('icap.example', tlsHandshakeTimeout: -0.5), 'tlsHandshakeTimeout'],
        'TLS handshake timeout NAN'         => [fn () => new Config('icap.example', tlsHandshakeTimeout: NAN), 'tlsHandshakeTimeout'],
        'TLS handshake timeout INF'         => [fn () => new Config('icap.example', tlsHandshakeTimeout: INF), 'tlsHandshakeTimeout'],
        'response start timeout 0'          => [fn () => new Config('icap.example', responseStartTimeout: 0.0), 'responseStartTimeout'],
        'negative response start timeout'   => [fn () => new Config('icap.example', responseStartTimeout: -0.5), 'responseStartTimeout'],
        'response start timeout NAN'        => [fn () => new Config('icap.example', responseStartTimeout: NAN), 'responseStartTimeout'],
        'response start timeout INF'        => [fn () => new Config('icap.example', responseStartTimeout: INF), 'responseStartTimeout'],

        'response size 0'                   => [fn () => new Config('icap.example', maxResponseSize: 0), 'maxResponseSize'],
        'negative response size'            => [fn () => new Config('icap.example', maxResponseSize: -1), 'maxResponseSize'],
        'header count 0'                    => [fn () => new Config('icap.example', maxHeaderCount: 0), 'maxHeaderCount'],
        'header line length 0'              => [fn () => new Config('icap.example', maxHeaderLineLength: 0), 'maxHeaderLineLength'],

        'single virus header: empty'        => [fn () => new Config('icap.example', virusFoundHeader: ''), 'virusFoundHeaders'],
        'single virus header: no token'     => [fn () => new Config('icap.example', virusFoundHeader: 'X Virus'), 'virusFoundHeaders'],
    ];
    foreach (invalidVirusHeaderNames() as $case => $names) {
        $cases["virus headers: {$case}"] = [fn () => $withNames($names), 'virusFoundHeaders'];
    }

    foreach ($cases as $case => [$build, $setting]) {
        expect($build)->toThrow(InvalidArgumentException::class, $setting, $case);
    }
});

it('runs the arguments of every wither through the same checks and leaves the original as it was', function () {
    $config = new Config(
        host: '2001:db8::1',
        port: 11344,
        socketTimeout: 2.5,
        streamTimeout: 0.0,
        tlsContext: new ClientTlsContext('icap.example'),
        maxResponseSize: 1000,
        maxHeaderCount: 7,
        maxHeaderLineLength: 99,
        virusFoundHeaders: ['X-One', 'X-Two'],
    );
    $withNames = fn ($names) => $config->withVirusFoundHeaders($names);

    // case => [build, the setting the message names]
    $cases = [
        'operation timeout 0'            => [fn () => $config->withTimeouts(operationTimeout: 0.0), 'operationTimeout'],
        'operation timeout NAN'          => [fn () => $config->withTimeouts(operationTimeout: NAN), 'operationTimeout'],
        'operation timeout INF'          => [fn () => $config->withTimeouts(operationTimeout: INF), 'operationTimeout'],
        'negative TLS handshake timeout' => [fn () => $config->withTimeouts(tlsHandshakeTimeout: -1.0), 'tlsHandshakeTimeout'],
        'TLS handshake timeout INF'      => [fn () => $config->withTimeouts(tlsHandshakeTimeout: INF), 'tlsHandshakeTimeout'],
        'response start timeout 0'       => [fn () => $config->withTimeouts(responseStartTimeout: 0.0), 'responseStartTimeout'],
        'response start timeout NAN'     => [fn () => $config->withTimeouts(responseStartTimeout: NAN), 'responseStartTimeout'],
        'response size 0'                => [fn () => $config->withLimits(maxResponseSize: 0), 'maxResponseSize'],
        'negative response size'         => [fn () => $config->withLimits(maxResponseSize: -1), 'maxResponseSize'],
        'header count 0'                 => [fn () => $config->withLimits(maxHeaderCount: 0), 'maxHeaderCount'],
        'negative header count'          => [fn () => $config->withLimits(maxHeaderCount: -3), 'maxHeaderCount'],
        'header line length 0'           => [fn () => $config->withLimits(maxHeaderLineLength: 0), 'maxHeaderLineLength'],
        'single virus header: empty'     => [fn () => $config->withVirusFoundHeader(''), 'virusFoundHeaders'],
        'single virus header: no token'  => [fn () => $config->withVirusFoundHeader('X Virus'), 'virusFoundHeaders'],
        'single virus header: line break' => [fn () => $config->withVirusFoundHeader("X-Virus\r\nX-Injected: 1"), 'virusFoundHeaders'],
    ];
    foreach (invalidVirusHeaderNames() as $case => $names) {
        $cases["virus headers: {$case}"] = [fn () => $withNames($names), 'virusFoundHeaders'];
    }

    // No "Undefined array key" warning and no TypeError on the way: the
    // Config says what is wrong with the value it was given.
    foreach ($cases as $case => [$build, $setting]) {
        expect($build)->toThrow(InvalidArgumentException::class, $setting, $case);
    }

    expect($config->getVirusFoundHeaders())->toBe(['X-One', 'X-Two'])
        ->and($config->getMaxResponseSize())->toBe(1000)
        ->and($config->getMaxHeaderCount())->toBe(7)
        ->and($config->getMaxHeaderLineLength())->toBe(99);
});

it('applies the same checks to the factory', function () {
    $cases = [
        'host with a path'   => [fn () => IcapClient::forServer('scanner.example/other'), 'host'],
        'empty host'         => [fn () => IcapClient::forServer(''), 'host'],
        'port 0'             => [fn () => IcapClient::forServer('icap.example', 0), 'port'],
        'port above 65535'   => [fn () => IcapClient::forServer('icap.example', 65536), 'port'],
    ];
    foreach ($cases as $case => [$build, $setting]) {
        expect($build)->toThrow(InvalidArgumentException::class, $setting, $case);
    }

    expect(IcapClient::forServer('::1', 1345))->toBeInstanceOf(IcapClient::class);
});
