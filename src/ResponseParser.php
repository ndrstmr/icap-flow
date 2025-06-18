<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapResponseException;

class ResponseParser implements ResponseParserInterface
{
    public function parse(string $rawResponse): IcapResponse
    {
        $parts = preg_split("/\r?\n\r?\n/", $rawResponse, 2);
        if ($parts === false || count($parts) < 1) {
            throw new IcapResponseException('Invalid ICAP response');
        }
        [$head, $body] = $parts + ['', ''];

        $lines = preg_split('/\r?\n/', $head);
        if ($lines === false || count($lines) === 0) {
            throw new IcapResponseException('Invalid ICAP response');
        }

        $statusLine = array_shift($lines);
        if (!preg_match('/ICAP\/1\.0\s+(\d+)/', (string)$statusLine, $m)) {
            throw new IcapResponseException('Invalid status line');
        }
        $statusCode = (int)$m[1];

        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            $value = trim($value);
            $headers[$name][] = $value;
        }

        // decode chunked body if applicable
        if ($body !== '' && preg_match('/^[0-9a-fA-F]+\r\n/i', $body)) {
            $decoded = '';
            while (preg_match('/^([0-9a-fA-F]+)\r\n/', $body, $mm)) {
                $len = hexdec($mm[1]);
                $body = substr($body, strlen($mm[0]));
                if ($len === 0) {
                    break;
                }
                $decoded .= substr($body, 0, (int)$len);
                $body = substr($body, (int)$len + 2); // skip chunk and CRLF
            }
            $body = $decoded;
        }

        return new IcapResponse($statusCode, $headers, $body);
    }
}
