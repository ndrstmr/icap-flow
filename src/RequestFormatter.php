<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\DTO\IcapRequest;

/**
 * Default implementation converting an {@link IcapRequest} into a raw string.
 */
class RequestFormatter implements RequestFormatterInterface
{
    /**
     * @param IcapRequest $request
     */
    public function format(IcapRequest $request): string
    {
        $parts = parse_url($request->uri);
        $host = $parts['host'] ?? '';

        $requestLine = sprintf('%s %s ICAP/1.0', $request->method, $request->uri);

        $headers = $request->headers;
        if (!isset($headers['Host'])) {
            $headers['Host'] = [$host];
        }
        if (!isset($headers['Encapsulated'])) {
            $headers['Encapsulated'] = ['null-body=0'];
        }

        $headerLines = '';
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $headerLines .= $name . ': ' . $value . "\r\n";
            }
        }

        $body = '';
        if (is_resource($request->body)) {
            rewind($request->body);
            while (!feof($request->body)) {
                $chunk = fread($request->body, 8192);
                if ($chunk === false) {
                    break;
                }
                $len = dechex(strlen($chunk));
                $body .= $len . "\r\n" . $chunk . "\r\n";
            }
            $body .= "0\r\n\r\n";
        } elseif (is_string($request->body) && $request->body !== '') {
            $body = $request->body;
        }

        return $requestLine . "\r\n" . $headerLines . "\r\n" . $body;
    }
}
