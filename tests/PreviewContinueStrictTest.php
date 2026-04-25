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

use Amp\Socket;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\AmpConnectionPool;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;

uses(AsyncTestCase::class);

/**
 * Strict RFC 3507 §4.5 preview-continue: when the server answers
 * `100 Continue`, the client sends ONLY the chunked body remainder
 * on the same socket — no second ICAP request. This test wires a
 * Socket pair into the connection pool, drives a fake server on the
 * far end, and asserts the client never opens a second connection.
 */

it('sends preview, reads 100, then writes only the body remainder on the same socket', function () {
    [$serverEnd, $clientEnd] = Socket\createSocketPair();

    // Inject our pre-paired client socket via the pool's connector.
    $connectorCalls = 0;
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use ($clientEnd, &$connectorCalls) {
            $connectorCalls++;
            return $clientEnd;
        },
    );
    $config = new Config('icap.example');
    $client = new IcapClient(
        $config,
        new AsyncAmpTransport($pool),
        new RequestFormatter(),
        new ResponseParser(),
    );

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, str_repeat('A', 100));

    $observed = ['phase1' => '', 'phase2' => ''];

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use (
        $client,
        $tmp,
        $serverEnd,
        &$observed,
    ) {
        // Drive the fake server on a parallel fiber.
        $server = \Amp\async(static function () use ($serverEnd, &$observed): void {
            // Phase 1 — read until we see the preview's 0\\r\\n\\r\\n
            // terminator, then answer 100 Continue.
            $buf = '';
            while (!str_contains($buf, "0\r\n\r\n")) {
                $chunk = $serverEnd->read();
                if ($chunk === null) {
                    return;
                }
                $buf .= $chunk;
            }
            $observed['phase1'] = $buf;
            $serverEnd->write("ICAP/1.0 100 Continue\r\n\r\n");

            // Phase 2 — read the chunked-body continuation (also
            // ending in 0\\r\\n\\r\\n), then answer 204 Clean.
            $buf = '';
            while (!str_contains($buf, "0\r\n\r\n")) {
                $chunk = $serverEnd->read();
                if ($chunk === null) {
                    return;
                }
                $buf .= $chunk;
            }
            $observed['phase2'] = $buf;
            $serverEnd->write("ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n");
            $serverEnd->close();
        });

        $result = $client->scanFileWithPreview('/svc', $tmp, 32)->await();
        $server->await();

        expect($result->isInfected())->toBeFalse();
    });

    // The pool's connector was called exactly once: preview and
    // continuation share a socket.
    expect($connectorCalls)->toBe(1);

    // Phase 1 must be a real RESPMOD with the Preview / Allow:204
    // headers and a 0\\r\\n\\r\\n terminator.
    expect($observed['phase1'])->toStartWith('RESPMOD ')
        ->and($observed['phase1'])->toContain('Preview: 32')
        ->and($observed['phase1'])->toContain('Allow: 204')
        ->and($observed['phase1'])->toContain("0\r\n\r\n");

    // Phase 2 must NOT be a new ICAP request — it's only the
    // chunked body continuation, terminated by 0\\r\\n\\r\\n.
    expect($observed['phase2'])->not->toContain('RESPMOD ')
        ->and($observed['phase2'])->not->toContain('ICAP/1.0')
        ->and($observed['phase2'])->toContain("0\r\n\r\n");

    unlink($tmp);
});
