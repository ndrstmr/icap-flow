<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

use Amp\Future;
use Ndrstmr\Icap\DTO\IcapRequest;
use Ndrstmr\Icap\DTO\ScanResult;

/**
 * Public contract for ICAP clients.
 *
 * Implementations are async-native and return Amp Futures. The synchronous
 * decorator {@see SynchronousIcapClient} awaits those Futures behind a
 * blocking facade.
 */
interface IcapClientInterface
{
    /**
     * Send a raw ICAP request.
     *
     * @return Future<ScanResult>
     */
    public function request(IcapRequest $request): Future;

    /**
     * Issue an OPTIONS request against the given service.
     *
     * @return Future<ScanResult>
     */
    public function options(string $service): Future;

    /**
     * Scan a local file via RESPMOD.
     *
     * @param array<string, string|string[]> $extraHeaders Extra ICAP request
     *        headers, e.g. `['X-Client-IP' => '203.0.113.5']`. Library-managed
     *        headers (`Host`, `Encapsulated`) always take precedence.
     * @return Future<ScanResult>
     */
    public function scanFile(string $service, string $filePath, array $extraHeaders = []): Future;

    /**
     * Scan a file using preview mode, streaming the remainder on demand.
     *
     * @param array<string, string|string[]> $extraHeaders See {@see scanFile()}.
     *        `Preview` and `Allow` are library-managed and cannot be
     *        overridden via this parameter.
     * @return Future<ScanResult>
     */
    public function scanFileWithPreview(
        string $service,
        string $filePath,
        int $previewSize = 1024,
        array $extraHeaders = [],
    ): Future;
}
