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

use Ndrstmr\Icap\DTO\IcapResponse;

/**
 * Values a shared backend can hold in the slot of an OPTIONS entry that are no entry for the key `svc` at a given
 * time: corrupt, of another version or another key, outside their lifetime, outside the budgets, or contradicting
 * what the answer says about itself. The adapters answer every one of them with a miss.
 */
final class StoredEntryCases
{
    /**
     * Entries that are valid, next to the ones that are not: what a stricter check must still let through.
     *
     * @param array<string, mixed> $entry a valid entry for `svc`, stored at $now for 60 seconds
     *
     * @return array<string, mixed> description => stored value
     */
    public static function usable(array $entry, int $now): array
    {
        $began = [...$entry, 'stored' => $now - 5, 'expires' => $now + 55];

        return [
            'an Options-TTL as long as the lifetime'          => [...$entry, 'headers' => ['Options-TTL' => ['60']]],
            'an Options-TTL longer than the lifetime'         => [...$entry, 'headers' => ['Options-TTL' => ['3600']]],
            'an Options-TTL beyond an hour'                   => [...$entry, 'headers' => ['Options-TTL' => ['99999999999999999999']]],
            'an Options-TTL with leading zeros'               => [...$entry, 'headers' => ['Options-TTL' => ['060']]],
            'the same Options-TTL twice'                      => [...$entry, 'headers' => ['Options-TTL' => ['60', '60']]],
            'the same Options-TTL in two spellings'           => [...$entry, 'headers' => ['Options-TTL' => ['60'], 'options-ttl' => ['60']]],
            'the same ISTag twice, in two spellings'          => [...$entry, 'headers' => ['ISTag' => ['"a"', '"a"'], 'istag' => ['"a"']]],
            'an entry that began before, within its TTL'      => [...$began, 'headers' => ['Options-TTL' => ['60']]],
            'a field name that is a number'                   => [...$entry, 'headers' => [7 => ['v']]],
            'a layout without a body'                         => [...$entry, 'headers' => ['Encapsulated' => ['null-body=0']], 'body' => ''],
            'a layout with an empty body'                     => [...$entry, 'headers' => ['Encapsulated' => ['opt-body=0']], 'body' => ''],
        ];
    }

    /**
     * @param array<string, mixed> $entry a valid entry for `svc`, stored at $now for 60 seconds
     *
     * @return array<string, mixed> description => stored value
     */
    public static function unusable(array $entry, int $now): array
    {
        $stored = $now;
        $fields = [];
        for ($i = 0; $i < 101; $i++) {
            $fields["X-Field-$i"] = ['v'];
        }
        // 10,000 names of at most 5 bytes: far inside the byte budget, so that only their number can refuse them.
        $names = [];
        for ($i = 0; $i < 10_000; $i++) {
            $names["h$i"] = [];
        }

        $cases = [
            'a string'                              => 'garbage',
            'an int'                                => 7,
            'a float'                               => 1.5,
            'true'                                  => true,
            'an empty array'                        => [],
            'a list'                                => [200, [], ''],
            'an object'                             => new \stdClass(),
            'the shape of an earlier version'       => ['statusCode' => 200, 'headers' => ['Preview' => ['1024']], 'body' => ''],
            'the earlier shape, status as a string' => ['statusCode' => '200', 'headers' => [], 'body' => ''],
            'another version'                       => [...$entry, 'v' => 2],
            'a version as a string'                 => [...$entry, 'v' => '1'],
            'an entry of another key'               => [...$entry, 'key' => 'other'],
            'a key that is no string'               => [...$entry, 'key' => 5],
            'an unknown field'                      => [...$entry, 'extra' => 1],
            'a status as a string'                  => [...$entry, 'status' => '200'],
            'a start as a string'                   => [...$entry, 'stored' => (string) $stored],
            'a start as a float'                    => [...$entry, 'stored' => (float) $stored],
            'an end as a float'                     => [...$entry, 'expires' => $stored + 60.0],
            'an end that has passed'                => [...$entry, 'stored' => $stored - 120, 'expires' => $stored - 60],
            'an end that is now'                    => [...$entry, 'stored' => $stored - 60, 'expires' => $stored],
            'a start in the future'                 => [...$entry, 'stored' => $stored + 1, 'expires' => $stored + 61],
            'an end before the start'               => [...$entry, 'expires' => $stored - 1],
            'an end at the start'                   => [...$entry, 'expires' => $stored],
            'a lifetime beyond an hour'             => [...$entry, 'stored' => $stored - 1, 'expires' => $stored + 3600],
            'a lifetime beyond the range of an int' => [...$entry, 'stored' => PHP_INT_MIN, 'expires' => PHP_INT_MAX],
            'headers that are no array'             => [...$entry, 'headers' => 'Preview: 1024'],
            'a field that is no list'               => [...$entry, 'headers' => ['Preview' => '1024']],
            'a field value that is an int'          => [...$entry, 'headers' => ['Preview' => [1024]]],
            'a field value that is an array'        => [...$entry, 'headers' => ['Preview' => [['1024']]]],
            'a field with named values'             => [...$entry, 'headers' => ['Preview' => ['n' => '1024']]],
            'a field name with CR LF'               => [...$entry, 'headers' => ["X-A\r\nX-B" => ['v']]],
            'an empty field name'                   => [...$entry, 'headers' => ['' => ['v']]],
            'a field name with a space'             => [...$entry, 'headers' => ['X A' => ['v']]],
            'a field value with CR LF'              => [...$entry, 'headers' => ['X-A' => ["a\r\nb"]]],
            'a field value with NUL'                => [...$entry, 'headers' => ['X-A' => ["a\0b"]]],
            'more fields than the budget'           => [...$entry, 'headers' => $fields],
            'a field name beyond the budget'        => [...$entry, 'headers' => ['X' . str_repeat('n', 65_536) => ['v']]],
            'a field value beyond the budget'       => [...$entry, 'headers' => ['X-Big' => [str_repeat('v', 65_536)]]],
            'a Preview that is no decimal'          => [...$entry, 'headers' => ['Preview' => ['abc']]],
            'a negative Preview'                    => [...$entry, 'headers' => ['Preview' => ['-1']]],
            'a Preview with a space'                => [...$entry, 'headers' => ['Preview' => [' 5']]],
            'two Previews'                          => [...$entry, 'headers' => ['Preview' => ['1', '2']]],
            'two Previews in two spellings'         => [...$entry, 'headers' => ['Preview' => ['1'], 'preview' => ['2']]],
            'a field without a value'               => [...$entry, 'headers' => ['X-A' => []]],
            'more field names than the budget, none with a value' => [...$entry, 'headers' => $names],
            'an Options-TTL shorter than the lifetime' => [...$entry, 'headers' => ['Options-TTL' => ['1']]],
            'an Options-TTL of 1 that began 5 seconds ago' => [...$entry, 'stored' => $stored - 5, 'expires' => $stored + 55, 'headers' => ['Options-TTL' => ['1']]],
            'an Options-TTL of 0'                   => [...$entry, 'headers' => ['Options-TTL' => ['0']]],
            'an Options-TTL that is no decimal'     => [...$entry, 'headers' => ['Options-TTL' => ['bad']]],
            'an Options-TTL with a sign'            => [...$entry, 'headers' => ['Options-TTL' => ['+60']]],
            'two Options-TTL that differ'           => [...$entry, 'headers' => ['Options-TTL' => ['60', '30']]],
            'two Options-TTL in two spellings that differ' => [...$entry, 'headers' => ['Options-TTL' => ['60'], 'options-ttl' => ['30']]],
            'two ISTags that differ'                => [...$entry, 'headers' => ['ISTag' => ['"a"', '"b"']]],
            'two ISTags in two spellings that differ' => [...$entry, 'headers' => ['ISTag' => ['"a"'], 'istag' => ['"b"']]],
            'a layout with a request header section' => [...$entry, 'headers' => ['Encapsulated' => ['req-hdr=0, null-body=29']]],
            'a layout with a response header section' => [...$entry, 'headers' => ['encapsulated' => ['Res-Hdr=0, res-body=29']]],
            'a body that is an int'                 => [...$entry, 'body' => 5],
            'a body that is an array'               => [...$entry, 'body' => ['x']],
            'a body that is null'                   => [...$entry, 'body' => null],
            'a body beyond the budget'              => [...$entry, 'body' => str_repeat('b', 65_537)],
            'a body beyond the budget, no fields'   => [...$entry, 'headers' => [], 'body' => str_repeat('b', 65_537)],
        ];
        foreach ([100, 204, 206, 404, 503] as $status) {
            $cases["a status of $status"] = [...$entry, 'status' => $status];
        }
        foreach (array_keys($entry) as $name) {
            $cases["an entry without $name"] = array_diff_key($entry, [$name => true]);
        }

        return $cases;
    }

    /**
     * Responses that no cache keeps: another status than 200, over the budget of 64 KiB and 100 field values, fields
     * that are no tokens and text or have no value, a Preview that is no single decimal, an Options-TTL that is no
     * decimal above 0 or an ISTag that differs from itself, a body that was discarded, an encapsulated message or the
     * layout of one (a header section).
     *
     * @return array<string, IcapResponse>
     */
    public static function notKept(): array
    {
        $fields = [];
        for ($i = 0; $i < 101; $i++) {
            $fields["X-Field-$i"] = ['v'];
        }
        // 10,000 names of at most 5 bytes: far inside the byte budget, so that only their number can refuse them.
        $names = [];
        for ($i = 0; $i < 10_000; $i++) {
            $names["h$i"] = [];
        }

        return [
            'a 204'                         => new IcapResponse(204),
            'a 206'                         => new IcapResponse(206),
            'a 404'                         => new IcapResponse(404),
            'a 503'                         => new IcapResponse(503),
            'a body over the budget'        => new IcapResponse(200, ['X' => ['a']], str_repeat('b', 65_535)),
            'a body over the budget, no fields' => new IcapResponse(200, [], str_repeat('b', 65_537)),
            'a field name over the budget'  => new IcapResponse(200, ['X' . str_repeat('n', 65_536) => ['v']]),
            'more fields than the budget'   => new IcapResponse(200, $fields),
            'a field name with a space'     => new IcapResponse(200, ['X A' => ['v']]),
            'a field value with a line end' => new IcapResponse(200, ['X-A' => ["a\nb"]]),
            'a Preview that is no decimal'  => new IcapResponse(200, ['Preview' => ['abc']]),
            'two Previews'                  => new IcapResponse(200, ['Preview' => ['1', '2']]),
            'a field without a value'       => new IcapResponse(200, ['X-A' => []]),
            'more field names than the budget, none with a value' => new IcapResponse(200, $names),
            'an Options-TTL of 0'           => new IcapResponse(200, ['Options-TTL' => ['0']]),
            'an Options-TTL that is no decimal' => new IcapResponse(200, ['Options-TTL' => ['bad']]),
            'two Options-TTL that differ'   => new IcapResponse(200, ['Options-TTL' => ['60', '30']]),
            'two Options-TTL in two spellings that differ' => new IcapResponse(200, ['Options-TTL' => ['60'], 'options-ttl' => ['30']]),
            'two ISTags that differ'        => new IcapResponse(200, ['ISTag' => ['"a"', '"b"']]),
            'two ISTags in two spellings that differ' => new IcapResponse(200, ['ISTag' => ['"a"'], 'istag' => ['"b"']]),
            'a layout with a request header section' => new IcapResponse(200, ['Encapsulated' => ['req-hdr=0, null-body=29']]),
            'a layout with a response header section' => new IcapResponse(200, ['encapsulated' => ['Res-Hdr=0, res-body=29']]),
            'a body that was discarded'     => new IcapResponse(200, [], '', 12),
            'an encapsulated message'       => new IcapResponse(200, [], '', null, 403),
        ];
    }

    /**
     * How long an answer is kept when it says how long it is valid: the shortest of the TTL the caller gives, the
     * `Options-TTL` of the answer and an hour.
     *
     * @return array<string, array{array<string, string[]>, int, int}> description => [fields of the answer, TTL of the caller, seconds the entry lives]
     */
    public static function lifetimes(): array
    {
        return [
            'no Options-TTL, the TTL of the caller'    => [[], 90, 90],
            'no Options-TTL, beyond an hour'           => [[], 7200, 3600],
            'an Options-TTL shorter than the caller'   => [['Options-TTL' => ['10']], 3600, 10],
            'an Options-TTL longer than the caller'    => [['Options-TTL' => ['100']], 30, 30],
            'an Options-TTL beyond an hour'            => [['Options-TTL' => ['7200']], 7200, 3600],
            'an Options-TTL in another spelling'       => [['options-ttl' => ['10']], 3600, 10],
            'an Options-TTL with leading zeros'        => [['Options-TTL' => ['010']], 3600, 10],
            'the same Options-TTL twice'               => [['Options-TTL' => ['10', '10']], 3600, 10],
        ];
    }
}
