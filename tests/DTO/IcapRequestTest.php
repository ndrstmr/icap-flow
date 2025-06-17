<?php

use Ndrstmr\Icap\DTO\IcapRequest;

it('can be instantiated and mutated immutably', function () {
    $req = new IcapRequest('REQMOD');
    expect($req->method)->toBe('REQMOD');
    $req2 = $req->withHeader('X-Test', '1');
    expect($req2)->not->toBe($req)
        ->and($req2->headers['X-Test'])->toEqual(['1']);
});
