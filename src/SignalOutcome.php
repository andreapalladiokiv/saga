<?php

declare(strict_types=1);

namespace Techork\Saga;

/** What {@see SagaRunner::signal()} did. */
enum SignalOutcome
{
    /** The signal's transition was applied, and any follow-up work was queued. */
    case Applied;

    /**
     * No such saga. A signal from outside may legitimately arrive after the saga
     * completed or was rolled back, so this is not an error.
     */
    case NotFound;
}
