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
 * v2.2-P — per-IO timeout instead of session-lifetime timeout.
 *
 * The previous implementation created a single TimeoutCancellation when
 * opening the session. For multi-round-trip flows (strict §4.5 preview-
 * continue), the timer ran continuously across all IO phases — a server
 * that legitimately takes longer than streamTimeout to scan a large file
 * after the 100 Continue would trigger a spurious cancellation.
 *
 * The fix resets the timeout on each write() and readResponse() call,
 * so each individual IO operation gets the full streamTimeout window.
 */

it('per-IO timeout resets between preview phases — slow server does not trigger spurious cancellation', function () {
    [$serverEnd, $clientEnd] = Socket\createSocketPair();

    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use ($clientEnd) {
            return $clientEnd;
        },
    );

    // streamTimeout = 2s — each IO phase gets 2 s, but the total
    // session (~3.5 s) exceeds 2 s due to simulated server delays.
    $config = new Config('icap.example', streamTimeout: 2.0);
    $client = new IcapClient(
        $config,
        new AsyncAmpTransport($pool),
        new RequestFormatter(),
        new ResponseParser(),
    );

    $tmp = tempnam(sys_get_temp_dir(), 'icap');
    file_put_contents($tmp, str_repeat('X', 200));

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($client, $tmp, $serverEnd) {
        $server = \Amp\async(static function () use ($serverEnd): void {
            // Phase 1 — read preview, answer 100 Continue.
            $buf = '';
            while (!str_contains($buf, "0\r\n\r\n")) {
                $chunk = $serverEnd->read();
                if ($chunk === null) {
                    return;
                }
                $buf .= $chunk;
            }
            // Simulate a slow 100 Continue — 1.5s delay before answering.
            // Each delay is within the 2s per-IO window, but the two
            // delays combined (3s) exceed the 2s session-lifetime.
            \Amp\delay(1.5);
            $serverEnd->write("ICAP/1.0 100 Continue\r\n\r\n");

            // Phase 2 — read remainder.
            $buf = '';
            while (!str_contains($buf, "0\r\n\r\n")) {
                $chunk = $serverEnd->read();
                if ($chunk === null) {
                    return;
                }
                $buf .= $chunk;
            }

            // Simulate a slow ClamAV scan — another 1.5s delay.
            \Amp\delay(1.5);

            $serverEnd->write("ICAP/1.0 204 No Content\r\nEncapsulated: null-body=0\r\n\r\n");
            $serverEnd->close();
        });

        // This must NOT throw CancelledException — the 0.8s delay is
        // within the per-IO 1s window, even though the total session
        // exceeds 1s when all phases are summed.
        $result = $client->scanFileWithPreview('/svc', $tmp, 32)->await();
        $server->await();

        expect($result->isInfected())->toBeFalse();
    });

    unlink($tmp);
});
