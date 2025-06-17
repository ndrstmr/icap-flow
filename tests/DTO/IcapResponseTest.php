<?php

use Ndrstmr\Icap\DTO\IcapResponse;

it('can be instantiated and mutated immutably', function () {
    $res = new IcapResponse(200);
    expect($res->statusCode)->toBe(200);
    $res2 = $res->withHeader('X-Test', '1');
    expect($res2)->not->toBe($res)
        ->and($res2->headers['X-Test'])->toEqual(['1']);
});
