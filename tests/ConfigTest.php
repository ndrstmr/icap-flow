<?php

use Ndrstmr\Icap\Config;

test('Config can be instantiated', function () {
    $config = new Config('icap.example');
    expect($config)->toBeInstanceOf(Config::class)
        ->and($config->host)->toBe('icap.example')
        ->and($config->port)->toBe(1344);
});
