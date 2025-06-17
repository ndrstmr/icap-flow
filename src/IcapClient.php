<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Transport\TransportInterface;
use Ndrstmr\Icap\Transport\SynchronousStreamTransport;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\RequestFormatterInterface;
use Ndrstmr\Icap\ResponseParserInterface;

class IcapClient
{
    public function __construct(
        private Config $config,
        private TransportInterface $transport,
        private RequestFormatterInterface $formatter,
        private ResponseParserInterface $parser
    ) {
    }

    public static function forServer(string $host, int $port = 1344): self
    {
        return new self(new Config($host, $port), new SynchronousStreamTransport(), new RequestFormatter(), new ResponseParser());
    }

    public function request(IcapRequest $request): IcapResponse
    {
        $raw = $this->formatter->format($request);
        $responseString = $this->transport->request($this->config, $raw)->await();
        return $this->parser->parse($responseString);
    }

    public function options(string $service): IcapResponse
    {
        $uri = sprintf('icap://%s%s', $this->config->host, $service);
        $request = new IcapRequest('OPTIONS', $uri);
        return $this->request($request);
    }

    public function scanFile(string $service, string $filePath): IcapResponse
    {
        $stream = fopen($filePath, 'r');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open file');
        }
        $uri = sprintf('icap://%s%s', $this->config->host, $service);
        $request = new IcapRequest('RESPMOD', $uri, [], $stream);
        return $this->request($request);
    }
}
