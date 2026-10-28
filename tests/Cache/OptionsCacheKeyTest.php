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

namespace Ndrstmr\Icap\Tests\Cache;

use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use Ndrstmr\Icap\Cache\OptionsCacheKey;

/*
 * M12: the key of an OPTIONS entry is a hash of structured parts. Parts that
 * run into each other when written one after the other must still give
 * different keys, and a key carries nothing a backend could refuse.
 */
it('derives a key of 64 hex digits from endpoint, service and security profile, the same one every time', function () {
    $key = OptionsCacheKey::forService('icap.example', 1344, '/avscan');

    expect(preg_match('/^[0-9a-f]{64}\z/', $key))->toBe(1);
    expect(OptionsCacheKey::forService('icap.example', 1344, '/avscan'))->toBe($key);
    expect(OptionsCacheKey::forService('icap.example', 1344, '/avscan', new ClientTlsContext('icap.example')))->not->toBe($key);
});

it('tells endpoint and service apart, however their parts run together', function () {
    $keys = [
        'base'                   => OptionsCacheKey::forService('icap.example', 1344, '/avscan'),
        'other host'             => OptionsCacheKey::forService('icap2.example', 1344, '/avscan'),
        'other port'             => OptionsCacheKey::forService('icap.example', 1345, '/avscan'),
        'other service'          => OptionsCacheKey::forService('icap.example', 1344, '/other'),
        'other query'            => OptionsCacheKey::forService('icap.example', 1344, '/avscan?a=b'),
        'port into the service'  => OptionsCacheKey::forService('icap.example', 134, '4/avscan'),
        // host:port + service reads 'h:1:2/s' for both
        'host:port run together' => OptionsCacheKey::forService('h', 1, ':2/s'),
        'host with a colon'      => OptionsCacheKey::forService('h:1', 2, '/s'),
    ];

    expect(count(array_unique($keys)))->toBe(count($keys));
});

/*
 * a plain and a TLS client, or clients whose TLS settings differ, are different security profiles and never share
 * an entry. A profile is what the context applies to the socket, not the object that holds it, so that two processes
 * with the same settings find the same entry. A secret is no part of a key.
 */
it('tells security profiles apart by the settings of the TLS context', function () {
    $context = static fn (): ClientTlsContext => new ClientTlsContext('icap.example');
    $keys = [
        'plain'               => OptionsCacheKey::forService('icap.example', 1344, '/avscan'),
        'default context'     => OptionsCacheKey::forService('icap.example', 1344, '/avscan', new ClientTlsContext()),
        'a context'           => OptionsCacheKey::forService('icap.example', 1344, '/avscan', $context()),
        'other peer name'     => OptionsCacheKey::forService('icap.example', 1344, '/avscan', new ClientTlsContext('other.example')),
        'no peer check'       => OptionsCacheKey::forService('icap.example', 1344, '/avscan', $context()->withoutPeerVerification()),
        'no peer name check'  => OptionsCacheKey::forService('icap.example', 1344, '/avscan', $context()->withoutPeerNameVerification()),
        'other CA file'       => OptionsCacheKey::forService('icap.example', 1344, '/avscan', $context()->withCaFile('/etc/ssl/ca-a.pem')),
        'another CA file'     => OptionsCacheKey::forService('icap.example', 1344, '/avscan', $context()->withCaFile('/etc/ssl/ca-b.pem')),
        'a CA path'           => OptionsCacheKey::forService('icap.example', 1344, '/avscan', $context()->withCaPath('/etc/ssl/certs')),
        'a certificate'       => OptionsCacheKey::forService('icap.example', 1344, '/avscan', $context()->withCertificate(new Certificate('/etc/ssl/a.pem'))),
        'another certificate' => OptionsCacheKey::forService('icap.example', 1344, '/avscan', $context()->withCertificate(new Certificate('/etc/ssl/b.pem'))),
        'a key file'          => OptionsCacheKey::forService('icap.example', 1344, '/avscan', $context()->withCertificate(new Certificate('/etc/ssl/a.pem', '/etc/ssl/a.key'))),
        'TLS 1.3 only'        => OptionsCacheKey::forService('icap.example', 1344, '/avscan', $context()->withMinimumVersion(ClientTlsContext::TLSv1_3)),
        'other depth'         => OptionsCacheKey::forService('icap.example', 1344, '/avscan', $context()->withVerificationDepth(3)),
        'other ciphers'       => OptionsCacheKey::forService('icap.example', 1344, '/avscan', $context()->withCiphers('HIGH')),
        'no SNI'              => OptionsCacheKey::forService('icap.example', 1344, '/avscan', $context()->withoutSni()),
        'a pinned peer'       => OptionsCacheKey::forService('icap.example', 1344, '/avscan', $context()->withPeerFingerprint(str_repeat('a', 64))),
        'another pinned peer' => OptionsCacheKey::forService('icap.example', 1344, '/avscan', $context()->withPeerFingerprint(str_repeat('b', 64))),
    ];

    expect(count(array_unique($keys)))->toBe(count($keys));
});

it('treats equal TLS settings as one profile, and a passphrase as none', function () {
    $key = static fn (ClientTlsContext $tls): string => OptionsCacheKey::forService('icap.example', 1344, '/avscan', $tls);
    $client = static fn (?string $passphrase): ClientTlsContext => (new ClientTlsContext('icap.example'))
        ->withCaFile('/etc/ssl/ca.pem')
        ->withCertificate(new Certificate('/etc/ssl/client.pem', null, $passphrase));

    // Two objects, one profile.
    expect($key($client(null)))->toBe($key($client(null)));
    // The passphrase is a secret, and it does not tell one profile from another.
    expect($key($client('first secret')))->toBe($key($client('second secret')))->toBe($key($client(null)));
});

it('derives the backend key from prefix and key without ambiguity', function () {
    $keys = [
        'base'                => OptionsCacheKey::backend('icap.', 'k'),
        'other prefix'        => OptionsCacheKey::backend('icap2.', 'k'),
        'other key'           => OptionsCacheKey::backend('icap.', 'k2'),
        'prefix into the key' => OptionsCacheKey::backend('icap', '.k'),
        'key into the prefix' => OptionsCacheKey::backend('icap.k', ''),
        'no prefix'           => OptionsCacheKey::backend('', 'icap.k'),
        'client key as a key' => OptionsCacheKey::forService('icap.example', 1344, '/avscan'),
    ];

    expect(count(array_unique($keys)))->toBe(count($keys));
    foreach ($keys as $case => $key) {
        expect(preg_match('/^[0-9a-f]{64}\z/', $key))->toBe(1, $case);
    }
    expect(OptionsCacheKey::backend('icap.', 'k'))->toBe($keys['base']);
});

it('keeps the scheme of version 2, so that a persistent cache finds its entries after a restart', function () {
    // Each part as "<length>:<part>;", the scheme and its version first.
    expect(OptionsCacheKey::forService('icap.example', 1344, '/avscan'))
        ->toBe(hash('sha256', '20:icap-flow/options/v2;12:icap.example;4:1344;7:/avscan;5:plain;'))
        ->toBe('5b35157c21f8da5a9ebc7b7fe1209203650f4a939411850fec5fbc5728a96cfa');
    expect(OptionsCacheKey::backend('icap.', 'k'))
        ->toBe(hash('sha256', '26:icap-flow/options-cache/v2;5:icap.;1:k;'))
        ->toBe('aadacb533434333abade7e33a4b0c384aa0e160bf3204244ca7d6b42511ef503');
});
