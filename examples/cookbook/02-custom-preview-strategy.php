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
 * IMPORTANT — 200/206 during preview:
 * RFC 3507 §4.3.3 / §6 allows servers to respond 200 or 206 in the
 * preview phase if they detect malware in the first chunk.  Always
 * inspect vendor-specific virus headers (e.g. X-Virus-ID for Symantec,
 * X-Violations-Found for Trend Micro) to distinguish "infected 200"
 * from "clean 200".  Mapping 200 unconditionally to ABORT_CLEAN is a
 * security anti-pattern — it silently discards virus verdicts.
 *
 * The example below shows the correct pattern.  Note: 304 is not a
 * defined ICAP status code (RFC 3507 §4.3.3 only mandates 1xx/2xx/
 * 4xx/5xx); the branch is kept only for vendor profiles that still
 * emit it.  Remove it for RFC-compliant servers.
 */
final class McAfeePreviewStrategy implements PreviewStrategyInterface
{
    /**
     * McAfee Web Gateway uses X-Virus-ID to signal infection in the
     * preview response; a 200 without this header means the server
     * finished early but found no malware.
     */
    public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision
    {
        return match ($previewResponse->statusCode) {
            100     => PreviewDecision::CONTINUE_SENDING,
            204     => PreviewDecision::ABORT_CLEAN,
            200,
            206     => isset($previewResponse->headers['X-Virus-ID'])
                        && $previewResponse->headers['X-Virus-ID'] !== []
                           ? PreviewDecision::ABORT_INFECTED
                           : PreviewDecision::ABORT_CLEAN,
            default => PreviewDecision::ABORT_INFECTED, // treat unknown as suspicious
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
