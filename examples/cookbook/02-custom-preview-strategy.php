<?php
require __DIR__ . '/../../vendor/autoload.php';

use Ndrstmr\Icap\PreviewStrategyInterface;
use Ndrstmr\Icap\PreviewDecision;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\DTO\IcapResponse;

class McAfeePreviewStrategy implements PreviewStrategyInterface
{
    public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision
    {
        return match ($previewResponse->statusCode) {
            100 => PreviewDecision::CONTINUE_SENDING,
            200, 204 => PreviewDecision::ABORT_CLEAN,
            304 => PreviewDecision::ABORT_INFECTED,
            default => PreviewDecision::ABORT_INFECTED,
        };
    }
}

$client = new IcapClient(
    new Ndrstmr\Icap\Config('127.0.0.1'),
    new Ndrstmr\Icap\Transport\AsyncAmpTransport(),
    new Ndrstmr\Icap\RequestFormatter(),
    new Ndrstmr\Icap\ResponseParser(),
    new McAfeePreviewStrategy()
);

$future = $client->scanFileWithPreview('/service', __DIR__ . '/../eicar.com');
$response = $future->await();

echo 'ICAP Status: ' . $response->statusCode . PHP_EOL;
