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
use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ServerTlsContext;
use Amp\Socket\Socket as SocketInterface;
use Amp\TimeoutCancellation;
use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\Tests\AsyncTestCase;
use Ndrstmr\Icap\Transport\AmpConnectionPool;

use function Amp\async;

uses(AsyncTestCase::class);

/**
 * v2.1 connection pooling. After the M3 follow-up dropped the
 * Connection: close hack, the transport can hand idle sockets back
 * to a pool keyed by host:port[:tls] and reuse them for subsequent
 * requests.
 */

it('reuses a released socket on the next acquire', function () {
    [, $serverEnd1] = Socket\createSocketPair();
    [, $serverEnd2] = Socket\createSocketPair();
    /** @var SocketInterface[] $queue */
    $queue = [$serverEnd1, $serverEnd2];

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        // Inject a connector that hands out our pre-built sockets so
        // we don't need a live TCP listener to exercise the pool.
        connector: function () use (&$queue): SocketInterface {
            $socket = array_shift($queue);
            if ($socket === null) {
                throw new RuntimeException('Test connector exhausted');
            }
            return $socket;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config) {
        $first = $pool->acquire($config);
        $pool->release($config, $first);

        $second = $pool->acquire($config);
        // Identity: the same Socket instance came back from the pool.
        expect($second)->toBe($first);

        // Counter check: there should still be 1 socket in the
        // connector queue — the pool didn't connect a fresh one.
    });

    expect($queue)->toHaveCount(1);
});

it('opens a fresh connection when the pool is empty', function () {
    [, $serverEndA] = Socket\createSocketPair();
    [, $serverEndB] = Socket\createSocketPair();
    /** @var SocketInterface[] $queue */
    $queue = [$serverEndA, $serverEndB];

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use (&$queue): SocketInterface {
            $socket = array_shift($queue);
            if ($socket === null) {
                throw new RuntimeException('Test connector exhausted');
            }
            return $socket;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config) {
        $first = $pool->acquire($config);
        $second = $pool->acquire($config); // pool was empty after first acquire
        expect($second)->not->toBe($first);
    });
});

it('caps idle connections at maxConnectionsPerHost', function () {
    /** @var SocketInterface[] $queue */
    $queue = [];
    for ($i = 0; $i < 5; $i++) {
        [, $end] = Socket\createSocketPair();
        $queue[] = $end;
    }
    $created = $queue;

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 2,
        connector: function () use (&$queue): SocketInterface {
            $socket = array_shift($queue);
            if ($socket === null) {
                throw new RuntimeException('Test connector exhausted');
            }
            return $socket;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config) {
        $sockets = [
            $pool->acquire($config),
            $pool->acquire($config),
            $pool->acquire($config),
        ];
        // Release all three — the third must be closed (cap = 2).
        foreach ($sockets as $s) {
            $pool->release($config, $s);
        }
    });

    // The third created socket should have been closed by the pool.
    expect($created[2]->isClosed())->toBeTrue();
    // The first two should still be open in the idle pool.
    expect($created[0]->isClosed())->toBeFalse()
        ->and($created[1]->isClosed())->toBeFalse();
});

it('skips a closed idle socket and connects fresh', function () {
    [, $stale] = Socket\createSocketPair();
    [, $fresh] = Socket\createSocketPair();
    /** @var SocketInterface[] $queue */
    $queue = [$stale, $fresh];

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use (&$queue): SocketInterface {
            $socket = array_shift($queue);
            if ($socket === null) {
                throw new RuntimeException('Test connector exhausted');
            }
            return $socket;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config, $fresh) {
        $first = $pool->acquire($config);
        // Simulate the server closing the connection between the two
        // requests (or a timeout intermediary dropping the socket).
        $first->close();
        $pool->release($config, $first); // pool sees it's closed → drops it

        $second = $pool->acquire($config);
        expect($second)->toBe($fresh);
    });
});

it('close() shuts down every pooled socket', function () {
    [, $a] = Socket\createSocketPair();
    [, $b] = Socket\createSocketPair();
    /** @var SocketInterface[] $queue */
    $queue = [$a, $b];

    $config = new Config('icap.example');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use (&$queue): SocketInterface {
            $socket = array_shift($queue);
            if ($socket === null) {
                throw new RuntimeException('Test connector exhausted');
            }
            return $socket;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $config) {
        $first = $pool->acquire($config);
        $second = $pool->acquire($config);
        $pool->release($config, $first);
        $pool->release($config, $second);
        $pool->close();
    });

    expect($a->isClosed())->toBeTrue()
        ->and($b->isClosed())->toBeTrue();
});

it('isolates idle sockets for different TLS contexts on the same host:port', function () {
    [, $socketA] = Socket\createSocketPair();
    [, $socketB] = Socket\createSocketPair();
    /** @var SocketInterface[] $queue */
    $queue = [$socketA, $socketB];

    $tlsA = new \Amp\Socket\ClientTlsContext('server-a.example');
    $tlsB = new \Amp\Socket\ClientTlsContext('server-b.example');

    $configA = (new Config('icap.example'))->withTlsContext($tlsA);
    $configB = (new Config('icap.example'))->withTlsContext($tlsB);

    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use (&$queue): SocketInterface {
            $socket = array_shift($queue);
            if ($socket === null) {
                throw new RuntimeException('Test connector exhausted');
            }
            return $socket;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $configA, $configB, $socketA, $socketB) {
        $a = $pool->acquire($configA);
        $pool->release($configA, $a);

        // Re-acquire with a DIFFERENT TLS context on the SAME host:port.
        // Must NOT return the socket that was pooled for configA.
        $b = $pool->acquire($configB);
        expect($b)->not->toBe($socketA)
            ->and($b)->toBe($socketB);
    });
});

it('isolates idle sockets per host:port', function () {
    [, $h1] = Socket\createSocketPair();
    [, $h2] = Socket\createSocketPair();
    /** @var SocketInterface[] $queue */
    $queue = [$h1, $h2];

    $configA = new Config('host-a');
    $configB = new Config('host-b');
    $pool = new AmpConnectionPool(
        maxConnectionsPerHost: 4,
        connector: function () use (&$queue): SocketInterface {
            $socket = array_shift($queue);
            if ($socket === null) {
                throw new RuntimeException('Test connector exhausted');
            }
            return $socket;
        },
    );

    /** @var AsyncTestCase $this */
    $this->runAsyncTest(function () use ($pool, $configA, $configB, $h1, $h2) {
        $a = $pool->acquire($configA);
        $b = $pool->acquire($configB);
        $pool->release($configA, $a);
        $pool->release($configB, $b);
        // Re-acquire from B should NOT return the A socket.
        expect($pool->acquire($configB))->toBe($h2);
        expect($pool->acquire($configA))->toBe($h1);
    });
});

/**
 * B07 — the pool key must not rest on an object identity PHP may hand to
 * the next object. connectTls() connects with a clone when the peer name
 * is empty, so nothing keeps the configured context alive; once it is
 * collected, its object hash can belong to a context that trusts other
 * authorities. Real default connector, local TLS server, peer and peer
 * name verification enabled.
 */
it('does not hand a socket to another trust context after the original context was collected', function () {
    $dir = sys_get_temp_dir() . '/icap-pool-tls-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700)) {
        throw new RuntimeException('Cannot create the TLS fixture directory.');
    }
    $cnfPath = $dir . '/openssl.cnf';
    $certPath = $dir . '/cert.pem';
    $keyPath = $dir . '/key.pem';

    $server = null;
    $pool = null;
    $socket = null;
    $peer = null;
    $second = null;
    $refused = null;

    try {
        // A self-signed certificate for 127.0.0.1 that may act as its own CA.
        file_put_contents($cnfPath, <<<'CONF'
[req]
distinguished_name=dn
req_extensions=san
prompt=no
[dn]
CN=127.0.0.1
[san]
subjectAltName=IP:127.0.0.1
basicConstraints=critical,CA:TRUE
keyUsage=critical,digitalSignature,keyEncipherment,keyCertSign
CONF);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        if ($key === false) {
            throw new RuntimeException('Cannot create the TLS fixture key.');
        }
        $options = ['digest_alg' => 'sha256', 'config' => $cnfPath];
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options + ['req_extensions' => 'san']);
        if (!$csr instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('Cannot create the TLS fixture request.');
        }
        $cert = openssl_csr_sign($csr, null, $key, 1, $options + ['x509_extensions' => 'san']);
        if ($cert === false || !openssl_x509_export($cert, $certPem) || !openssl_pkey_export($key, $keyPem)) {
            throw new RuntimeException('Cannot create the TLS fixture certificate.');
        }
        file_put_contents($certPath, $certPem);
        file_put_contents($keyPath, $keyPem);

        $server = Socket\listen('127.0.0.1:0', (new BindContext())->withTlsContext(
            (new ServerTlsContext())->withDefaultCertificate(new Certificate($certPath, $keyPath)),
        ));
        $address = $server->getAddress();
        \assert($address instanceof Socket\InternetAddress);
        $port = $address->getPort();

        $accepted = async(static function () use ($server): ?SocketInterface {
            $client = $server->accept(new TimeoutCancellation(5));
            $client?->setupTls(new TimeoutCancellation(5));

            return $client;
        });

        // The empty peer name makes connectTls() connect with a clone.
        $tls = (new ClientTlsContext())->withCaFile($certPath);
        $original = WeakReference::create($tls);
        $hash = spl_object_hash($tls);
        $config = new Config('127.0.0.1', $port, tlsContext: $tls);

        $pool = new AmpConnectionPool();
        $socket = $pool->acquire($config, new TimeoutCancellation(5));
        $peer = $accepted->await();
        $pool->release($config, $socket);

        unset($tls, $config);
        gc_collect_cycles();
        expect($original->get())->toBeNull('precondition: the configured context is collected');

        // Keep allocating until a context takes over the object hash.
        $held = [];
        $other = null;
        while (count($held) < 1000) {
            $candidate = new ClientTlsContext(); // trusts the system roots, not the fixture CA
            if (spl_object_hash($candidate) === $hash) {
                $other = $candidate;
                break;
            }
            $held[] = $candidate;
        }
        expect($other)->not->toBeNull('precondition: the object hash is handed to a new context');

        $refused = async(static function () use ($server): void {
            // A fresh connection is refused on the client side; answer it
            // without waiting for its outcome.
            $late = $server->accept(new TimeoutCancellation(5));
            try {
                $late?->setupTls(new TimeoutCancellation(5));
            } catch (Throwable) {
                // expected: the client does not trust this certificate
            } finally {
                $late?->close();
            }
        });

        // This context does not trust the certificate, so the only valid
        // outcome is a TLS failure: no socket at all, neither the idle one
        // nor a new one. Any other error fails the test.
        $failure = null;
        try {
            $second = $pool->acquire(new Config('127.0.0.1', $port, tlsContext: $other), new TimeoutCancellation(5));
        } catch (Socket\TlsException $e) {
            $failure = $e;
        }

        expect($failure)->toBeInstanceOf(Socket\TlsException::class, 'the untrusting context must be refused, not served a socket');
    } finally {
        $second?->close();
        $socket?->close();
        if ($peer instanceof SocketInterface) {
            $peer->close();
        }
        $server?->close();
        $pool?->close();
        if ($refused !== null) {
            try {
                $refused->await();
            } catch (Throwable) {
                // nothing left to clean up
            }
        }
        foreach ([$cnfPath, $certPath, $keyPath] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});
