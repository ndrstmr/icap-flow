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

/*
 * A preview strategy decides on the answer to a preview that holds only the
 * start of the file: CONTINUE_SENDING, ABORT_CLEAN or ABORT_INFECTED. After a
 * preview the server may answer 100 Continue, 204 or a final response
 * (RFC 3507 §4.5), e.g. a 200 that reports an infection, so a 200 is never
 * clean by itself: inspect the header your server reports an infection in.
 *
 * The strategy does not see the answer to the rest of the file, nor to a
 * preview that holds the whole file: those are read by the virus header list
 * of the Config, so list the same header there.
 *
 * Default rules only: the verified mode for c-icap (docs/cicap-clamav.md)
 * refuses a strategy of your own.
 */
final class VirusIdPreviewStrategy implements PreviewStrategyInterface
{
    #[\Override]
    public function handlePreviewResponse(IcapResponse $previewResponse): PreviewDecision
    {
        // Header names keep the server's spelling: compare them without case.
        $infected = false;
        foreach (array_keys($previewResponse->headers) as $name) {
            $infected = $infected || strcasecmp((string) $name, 'X-Virus-ID') === 0;
        }

        return match ($previewResponse->statusCode) {
            100     => PreviewDecision::CONTINUE_SENDING,
            204     => PreviewDecision::ABORT_CLEAN,
            200,
            206     => $infected ? PreviewDecision::ABORT_INFECTED : PreviewDecision::ABORT_CLEAN,
            default => PreviewDecision::ABORT_INFECTED, // treat unknown as suspicious
        };
    }
}

$config = (new Config('icap.example.com'))->withVirusFoundHeaders(['X-Virus-ID']);
$client = new IcapClient(
    $config,
    new AsyncAmpTransport(),
    new RequestFormatter(),
    new ResponseParser(
        maxHeaderCount: $config->getMaxHeaderCount(),
        maxHeaderLineLength: $config->getMaxHeaderLineLength(),
    ),
    new VirusIdPreviewStrategy(),
);

\Amp\async(function () use ($client) {
    $result = $client->scanFileWithPreview('/avscan', __DIR__ . '/../eicar.com')->await();
    echo $result->isInfected()
        ? 'Virus: ' . ($result->getVirusName() ?? 'unknown') . PHP_EOL
        : 'Clean' . PHP_EOL;
});

\Revolt\EventLoop::run();
