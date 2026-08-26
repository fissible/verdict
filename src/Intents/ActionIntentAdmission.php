<?php

declare(strict_types=1);

namespace Fissible\Verdict\Intents;

use Fissible\Verdict\Decisions\Decision;

/**
 * The outcome of the pre-mutation intent write: a committed intent plus a permitting decision,
 * or no intent plus a policy-shaped denial. Every caller receives a Decision either way — the
 * intent gate reports like every other gate (#160).
 */
final readonly class ActionIntentAdmission
{
    private function __construct(
        public ?ActionIntent $intent,
        public Decision $decision,
        /** The underlying store failure, for operational alerting; null when the write succeeded. */
        public ?string $failureMessage,
    ) {}

    public static function recorded(ActionIntent $intent): self
    {
        return new self($intent, Decision::permit('A durable intent record was written.', [
            'intent_id' => $intent->id,
        ]), null);
    }

    public static function refused(string $failureMessage): self
    {
        return new self(null, Decision::deny(
            'A durable intent record could not be written, and this capability must not act unrecorded.',
        ), $failureMessage);
    }
}
