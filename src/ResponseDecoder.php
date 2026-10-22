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

namespace Ndrstmr\Icap;

use Ndrstmr\Icap\DTO\IcapResponse;
use Ndrstmr\Icap\Exception\IcapMalformedResponseException;

/**
 * Frames and reads one ICAP response off a stream of byte fragments (RFC 3507
 * §4.3.3, §4.4). The transports use it to find where a response ends, the
 * parser to read a complete one; both ask the same code, so a response is
 * accepted or refused alike, however its bytes arrive.
 *
 * The message ends where its layout says, and nowhere else:
 *
 *  - The head is the status line and the header lines up to the blank line,
 *    each ended by CRLF.
 *  - The `Encapsulated` header names the parts of the body. A response has
 *    one of the forms RFC 3507 §4.4.1 allows for it: `[req-hdr] req-body`,
 *    `[res-hdr] res-body` or `opt-body`, any of them with `null-body` instead
 *    of the body entry. Offsets start at 0, increase, and are decimal numbers
 *    that fit the size limit. A 100, a 204 and an error status may leave the
 *    header out and then end at the blank line; every other status needs it.
 *    A 100 and a 204 carry no message: if they name a layout, it is
 *    `null-body=0`.
 *  - A header section is exactly as long as its offsets say, begins with a
 *    start line, has header lines and ends with a blank line.
 *  - A body is chunked: hex size, optional extensions, CRLF, exactly that many
 *    bytes, CRLF; the last chunk is followed by the trailer fields and a blank
 *    line. Bytes inside a chunk are data, whatever they look like.
 *
 * Anything else is an IcapMalformedResponseException, raised as soon as the
 * bytes that show it have arrived. Numbers are checked against the size limit
 * digit by digit before they are used, so none of them is ever cast,
 * multiplied or added past what fits. The limits for the size of the response,
 * the number of header lines and the length of a line apply to every block of
 * lines it carries: the head, the header sections, the chunk size lines and
 * the trailer.
 *
 * The decoder keeps its place: each byte is searched and checked a constant
 * number of times however it is fragmented ({@see workedBytes()}). Bytes that
 * arrive behind the end of the message are kept apart as {@see remainder()}.
 *
 * @internal
 */
final class ResponseDecoder
{
    private const int HEAD = 0;
    private const int SECTION = 1;
    private const int SIZE = 2;
    private const int DATA = 3;
    private const int DATA_END = 4;
    private const int TRAILER = 5;
    private const int DONE = 6;

    /** The entries of an Encapsulated header that name a body. */
    private const array BODIES = ['req-body', 'res-body', 'opt-body', 'null-body'];

    private const string TOKEN = '[!#$%&\'*+\-.^_`|~0-9A-Za-z]+';

    /** A chunk size line: hex digits and extensions, each a token with an optional token or quoted value. */
    private const string CHUNK_LINE = '/^([0-9A-Fa-f]+)'
        . '(?>[ \t]*;[ \t]*' . self::TOKEN
        . '(?>[ \t]*=[ \t]*(?>' . self::TOKEN . '|"(?>[\t\x20\x21\x23-\x5B\x5D-\x7E\x80-\xFF]|\\\\[\t\x20-\x7E\x80-\xFF])*"))?)*\z/';

    private string $buffer = '';
    /** Where the bytes the state machine has not used yet begin. */
    private int $pos = 0;
    /** Where the next search for CRLF goes on, so that a line is never searched twice. */
    private int $searchFrom = 0;
    private int $state = self::HEAD;
    private int $work = 0;

    /** @var list<string> the status line and the header lines of the head */
    private array $head = [];
    private int $status = 0;
    /** @var array<string, string[]> */
    private array $headers = [];

    /** 'req', 'res' or '' for a layout without a header section. */
    private string $sectionKind = '';
    private int $sectionLength = 0;
    private int $sectionStart = 0;
    /** @var list<string> */
    private array $sectionLines = [];
    private bool $hasBody = false;

    private int $chunkLeft = 0;
    private string $body = '';
    private int $trailerFields = 0;

    private string $frame = '';
    private string $remainder = '';

    /**
     * @param bool $collectBody whether to assemble the decoded body; framing alone does not need it
     */
    public function __construct(
        private readonly int $maxResponseSize,
        private readonly int $maxHeaderCount,
        private readonly int $maxHeaderLineLength,
        private readonly bool $collectBody = true,
    ) {
        if ($maxResponseSize < 1 || $maxHeaderCount < 1 || $maxHeaderLineLength < 1) {
            throw new \InvalidArgumentException('Decoder limits must be >= 1.');
        }
    }

    /**
     * Takes the next bytes of the stream. Bytes behind the end of the message are not read as part
     * of it, now or in a later call.
     *
     * @throws IcapMalformedResponseException When the bytes so far cannot belong to a legal response
     */
    public function feed(string $bytes): void
    {
        if ($this->state === self::DONE) {
            $this->remainder .= $bytes;

            return;
        }

        $this->buffer .= $bytes;
        $this->advance();

        // Not complete, and already more than the response may have.
        if ($this->state !== self::DONE && strlen($this->buffer) > $this->maxResponseSize) {
            throw $this->tooLarge();
        }
    }

    public function isComplete(): bool
    {
        return $this->state === self::DONE;
    }

    /**
     * The bytes of the message, from the first byte to the last, exactly.
     */
    public function frame(): string
    {
        $this->assertComplete();

        return $this->frame;
    }

    /**
     * The bytes that came behind the message: the start of what follows, or bytes that no
     * request is waiting for.
     */
    public function remainder(): string
    {
        $this->assertComplete();

        return $this->remainder;
    }

    /**
     * Status, headers and decoded body of the message.
     */
    public function response(): IcapResponse
    {
        $this->assertComplete();
        if (!$this->collectBody) {
            throw new \LogicException('The decoder was built to frame, not to collect the body.');
        }

        return new IcapResponse($this->status, $this->headers, $this->body);
    }

    /**
     * How many bytes the decoder has searched, checked or copied so far. It grows with the bytes
     * of the message, not with the number of fragments they came in.
     */
    public function workedBytes(): int
    {
        return $this->work;
    }

    private function assertComplete(): void
    {
        if ($this->state !== self::DONE) {
            throw new \LogicException('The ICAP response is not complete yet.');
        }
    }

    private function advance(): void
    {
        do {
            $progress = match ($this->state) {
                self::HEAD => $this->readHead(),
                self::SECTION => $this->readSection(),
                self::SIZE => $this->readSize(),
                self::DATA => $this->readData(),
                self::DATA_END => $this->readDataEnd(),
                self::TRAILER => $this->readTrailer(),
                default => false,
            };

            if ($this->pos > $this->maxResponseSize) {
                throw $this->tooLarge();
            }
        } while ($progress);
    }

    private function readHead(): bool
    {
        $line = $this->line();
        if ($line === null) {
            return false;
        }

        if ($this->head === []) {
            $this->status = $this->statusOf($line);
            $this->head[] = $line;

            return true;
        }

        if ($line !== '') {
            $this->head[] = $line;
            if (count($this->head) > $this->maxHeaderCount + 1) {
                throw $this->tooManyHeaders();
            }

            return true;
        }

        $this->endOfHead();

        return $this->state !== self::DONE;
    }

    private function endOfHead(): void
    {
        // A line without a colon is refused here, before the layout is looked at.
        $this->headers = HeaderFields::parse(array_slice($this->head, 1));
        $encapsulated = HeaderFields::single($this->headers, 'Encapsulated');

        if ($encapsulated === null) {
            if (self::needsLayout($this->status)) {
                throw new IcapMalformedResponseException('The ICAP response lacks its Encapsulated header.');
            }
            $this->complete();

            return;
        }

        $this->layout($encapsulated);
        if (self::isBodyless($this->status) && ($this->sectionKind !== '' || $this->hasBody)) {
            // Refused with the head, before a byte of the message is read or answered.
            throw new IcapMalformedResponseException('A 100 or 204 ICAP response carries no encapsulated message.');
        }
        if ($this->sectionKind !== '') {
            $this->sectionStart = $this->pos;
            $this->state = self::SECTION;
        } elseif ($this->hasBody) {
            $this->state = self::SIZE;
        } else {
            $this->complete();
        }
    }

    /**
     * RFC 3507 §4.4.1 for a response: [req-hdr] req-body, [res-hdr] res-body or opt-body, each
     * with null-body in place of the body. Sets the header section (kind and length) and
     * whether a chunked body follows.
     */
    private function layout(string $value): void
    {
        $entries = [];
        foreach (explode(',', $value) as $part) {
            if (preg_match('/^[ \t]*([A-Za-z]+-[A-Za-z]+)=([0-9]+)[ \t]*\z/', $part, $m) !== 1) {
                throw $this->invalidLayout();
            }
            $name = strtolower($m[1]);
            if (isset($entries[$name])) {
                throw $this->invalidLayout();
            }
            $entries[$name] = $this->number($m[2], 10, $this->maxResponseSize);
        }

        $names = array_keys($entries);
        $offsets = array_values($entries);

        if (count($names) === 1 && in_array($names[0], self::BODIES, true) && $offsets[0] === 0) {
            $this->sectionKind = '';
            $this->hasBody = $names[0] !== 'null-body';

            return;
        }

        if (count($names) === 2 && $offsets[0] === 0 && $offsets[1] > 0) {
            $body = match ($names[0]) {
                'req-hdr' => 'req-body',
                'res-hdr' => 'res-body',
                default => null,
            };
            if ($body !== null && ($names[1] === $body || $names[1] === 'null-body')) {
                $this->sectionKind = substr($names[0], 0, 3);
                $this->sectionLength = $offsets[1];
                $this->hasBody = $names[1] !== 'null-body';

                return;
            }
        }

        throw $this->invalidLayout();
    }

    /**
     * An encapsulated header section: a start line, header lines and a blank line, and the blank
     * line is the last byte of the length the layout gave the section.
     */
    private function readSection(): bool
    {
        $line = $this->line();
        if ($line === null) {
            return false;
        }

        if ($this->sectionLines === [] && !$this->isStartLine($line)) {
            throw new IcapMalformedResponseException('Invalid start line in the encapsulated headers of the ICAP response.');
        }

        if ($line !== '') {
            $this->sectionLines[] = $line;
            if (count($this->sectionLines) > $this->maxHeaderCount + 1) {
                throw $this->tooManyHeaders();
            }

            return true;
        }

        if ($this->pos !== $this->sectionStart + $this->sectionLength) {
            throw new IcapMalformedResponseException('The encapsulated headers of the ICAP response do not end where its Encapsulated header says.');
        }
        HeaderFields::parse(array_slice($this->sectionLines, 1));
        $this->sectionLines = [];

        if (!$this->hasBody) {
            $this->complete();

            return false;
        }
        $this->state = self::SIZE;

        return true;
    }

    private function readSize(): bool
    {
        $line = $this->line();
        if ($line === null) {
            return false;
        }

        if (preg_match(self::CHUNK_LINE, $line, $m) !== 1) {
            throw new IcapMalformedResponseException('Invalid chunk size line in the ICAP response.');
        }
        // What is left for the data and the CRLF behind it; a size that cannot fit is refused now.
        $size = $this->number($m[1], 16, max(0, $this->maxResponseSize - $this->pos - 2));

        if ($size === 0) {
            $this->state = self::TRAILER;
        } else {
            $this->chunkLeft = $size;
            $this->state = self::DATA;
        }

        return true;
    }

    private function readData(): bool
    {
        $take = min(strlen($this->buffer) - $this->pos, $this->chunkLeft);
        if ($take > 0) {
            if ($this->collectBody) {
                $this->body .= substr($this->buffer, $this->pos, $take);
                $this->work += $take;
            }
            $this->pos += $take;
            $this->chunkLeft -= $take;
        }
        if ($this->chunkLeft > 0) {
            return false;
        }

        $this->state = self::DATA_END;

        return true;
    }

    private function readDataEnd(): bool
    {
        $available = strlen($this->buffer) - $this->pos;
        if ($available >= 1 && $this->buffer[$this->pos] !== "\r") {
            throw new IcapMalformedResponseException('Invalid chunk in the ICAP response: no CRLF behind the chunk data.');
        }
        if ($available < 2) {
            return false;
        }
        if ($this->buffer[$this->pos + 1] !== "\n") {
            throw new IcapMalformedResponseException('Invalid chunk in the ICAP response: no CRLF behind the chunk data.');
        }

        $this->pos += 2;
        $this->work += 2;
        $this->state = self::SIZE;

        return true;
    }

    private function readTrailer(): bool
    {
        $line = $this->line();
        if ($line === null) {
            return false;
        }

        if ($line === '') {
            $this->complete();

            return false;
        }

        if (preg_match('/^' . self::TOKEN . ':[\t\x20-\x7E\x80-\xFF]*\z/', $line) !== 1) {
            throw new IcapMalformedResponseException('Invalid trailer field in the ICAP response.');
        }
        if (++$this->trailerFields > $this->maxHeaderCount) {
            throw $this->tooManyHeaders();
        }

        return true;
    }

    /**
     * The next line without its CRLF, or null while it is not complete. A line ends at CRLF only:
     * a CR or LF of its own would be a line end for some readers and content for others.
     */
    private function line(): ?string
    {
        $from = max($this->searchFrom, $this->pos);
        $end = $from > strlen($this->buffer) ? false : strpos($this->buffer, "\r\n", $from);

        if ($end === false) {
            $this->work += max(0, strlen($this->buffer) - $from);
            // A CR at the very end may be the first half of the CRLF; the next search starts there.
            $this->searchFrom = max($this->pos, strlen($this->buffer) - 1);
            if (strlen($this->buffer) - $this->pos > $this->maxHeaderLineLength + 1) {
                throw $this->lineTooLong();
            }

            return null;
        }

        $length = $end - $this->pos;
        $this->work += $end + 2 - $from;
        if ($length > $this->maxHeaderLineLength) {
            throw $this->lineTooLong();
        }

        $line = substr($this->buffer, $this->pos, $length);
        $this->work += 2 * $length;
        if (strpbrk($line, "\r\n") !== false) {
            throw new IcapMalformedResponseException('Invalid line ending in the ICAP response.');
        }

        $this->pos = $end + 2;
        $this->searchFrom = $this->pos;

        return $line;
    }

    private function complete(): void
    {
        $this->frame = substr($this->buffer, 0, $this->pos);
        $this->remainder = substr($this->buffer, $this->pos);
        $this->work += $this->pos;
        $this->buffer = '';
        $this->head = [];
        $this->state = self::DONE;
    }

    private function statusOf(string $line): int
    {
        if (preg_match('/^ICAP\/1\.[0-9] ([0-9]{3})(?: (.*))?\z/', $line, $m) !== 1 || !HeaderFields::isText($m[2] ?? '')) {
            // What the server sent stays out of the message: it would end up in logs and in handlers.
            throw new IcapMalformedResponseException('Invalid status line in the ICAP response.');
        }

        return (int) $m[1];
    }

    private function isStartLine(string $line): bool
    {
        $pattern = $this->sectionKind === 'req'
            ? '/^' . self::TOKEN . ' [^\x00-\x20\x7F]+ HTTP\/[0-9]\.[0-9]\z/'
            : '/^HTTP\/[0-9]\.[0-9] [0-9]{3}(?: [\t\x20-\x7E\x80-\xFF]*)?\z/';

        return preg_match($pattern, $line) === 1;
    }

    /**
     * "Send the rest" and "no change" are answers by themselves: no message follows.
     */
    private static function isBodyless(int $status): bool
    {
        return $status === 100 || $status === 204;
    }

    /**
     * Only a bodyless status or an error may do without the header that says where the message ends.
     */
    private static function needsLayout(int $status): bool
    {
        return !(self::isBodyless($status) || ($status >= 400 && $status <= 599));
    }

    /**
     * A number from its digits, refused as soon as it is more than $limit: before the digit that
     * would take it over is multiplied in, so nothing is ever cast or computed past what fits.
     *
     * @param non-empty-string $digits only the digits of the base
     */
    private function number(string $digits, int $base, int $limit): int
    {
        $value = 0;
        for ($i = 0, $length = strlen($digits); $i < $length; $i++) {
            $digit = intval($digits[$i], $base);
            if ($digit > $limit || $value > intdiv($limit - $digit, $base)) {
                throw $this->tooLarge();
            }
            $value = $value * $base + $digit;
        }
        $this->work += strlen($digits);

        return $value;
    }

    private function tooLarge(): IcapMalformedResponseException
    {
        return new IcapMalformedResponseException(sprintf('ICAP response exceeded max size (%d bytes).', $this->maxResponseSize));
    }

    private function tooManyHeaders(): IcapMalformedResponseException
    {
        return new IcapMalformedResponseException(sprintf('ICAP response exceeded max header count (%d).', $this->maxHeaderCount));
    }

    private function lineTooLong(): IcapMalformedResponseException
    {
        return new IcapMalformedResponseException(sprintf('ICAP response line exceeded the max line length (%d).', $this->maxHeaderLineLength));
    }

    private function invalidLayout(): IcapMalformedResponseException
    {
        return new IcapMalformedResponseException('Invalid Encapsulated header in the ICAP response.');
    }
}
