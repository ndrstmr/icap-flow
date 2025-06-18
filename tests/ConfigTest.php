<?php

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
