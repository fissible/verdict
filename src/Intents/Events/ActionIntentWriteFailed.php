<?php

declare(strict_types=1);

namespace Fissible\Verdict\Intents\Events;

/**
 * The pre-mutation intent write failed, so a protected action was denied with nothing consumed
 * (#160). With the intent lever on, a store outage means denied actions and this event — never an
 * unrecorded mutation. Wire it to paging: every dispatch is an action a caller wanted that the
 * intent store refused.
 */
final readonly class ActionIntentWriteFailed
{
    public function __construct(
        public string $capability,
        public ?string $invocationId,
        public string $message,
    ) {}
}
