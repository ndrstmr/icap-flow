<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Ndrstmr\Icap\Config;
use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\IcapClient;
use Ndrstmr\Icap\PreviewDecision;
use Ndrstmr\Icap\PreviewStrategyInterface;
use Ndrstmr\Icap\RequestFormatter;
use Ndrstmr\Icap\ResponseParser;
use Ndrstmr\Icap\Transport\AsyncAmpTransport;
use Revolt\EventLoop;

/*
 * Vendors disagree on what constitutes a "clean" or "infected"
 * preview response. Implement PreviewStrategyInterface to route the
 * vendor-specific status code matrix into the three decisions
 * IcapClient understands: CONTINUE_SENDING, ABORT_CLEAN, ABORT_INFECTED.
 *
 * Note: 304 is not a defined ICAP status code (RFC 3507 §4.3.3 only
 * mandates 1xx/2xx/4xx/5xx). The example below maps it conservatively
 * to ABORT_INFECTED ("treat as suspicious") for vendor profiles that
 * still emit it; if your server is RFC-compliant, remove that branch.
 */
final class McAfeePreviewStrategy implements PreviewStrategyInterface
{
    public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision
    {
        return match ($previewResponse->statusCode) {
            100      => PreviewDecision::CONTINUE_SENDING,
            200, 204 => PreviewDecision::ABORT_CLEAN,
            default  => PreviewDecision::ABORT_INFECTED,
        };
    }
}

$config = new Config('127.0.0.1');
$client = new IcapClient(
    $config,
    new AsyncAmpTransport(),
    new RequestFormatter(),
    new ResponseParser(
        maxHeaderCount: $config->getMaxHeaderCount(),
        maxHeaderLineLength: $config->getMaxHeaderLineLength(),
    ),
    new McAfeePreviewStrategy(),
);

EventLoop::run(function () use ($client) {
    $result = $client->scanFileWithPreview('/avscan', __DIR__ . '/../eicar.com')->await();
    echo $result->isInfected()
        ? 'Virus: ' . ($result->getVirusName() ?? 'unknown') . PHP_EOL
        : 'Clean' . PHP_EOL;
});
