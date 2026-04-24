<?php

declare(strict_types=1);

use Amp\Socket\ClientTlsContext;
use Ndrstmr\Icap\Config;

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
