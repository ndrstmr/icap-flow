<?php

use Ndrstmr\Icap\Transport\TransportInterface;

it('TransportInterface exists', function () {
    expect(interface_exists(TransportInterface::class))->toBeTrue();
});
