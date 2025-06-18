<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

/**
 * Result of preview handling during RESPMOD with preview mode.
 */
enum PreviewDecision: string
{
    case CONTINUE_SENDING = 'continue';
    case ABORT_CLEAN = 'abort_clean';
    case ABORT_INFECTED = 'abort_infected';
}
