<?php

declare(strict_types=1);

namespace Ndrstmr\Icap;

enum PreviewDecision: string
{
    case CONTINUE_SENDING = 'continue';
    case ABORT_CLEAN = 'abort_clean';
    case ABORT_INFECTED = 'abort_infected';
}
