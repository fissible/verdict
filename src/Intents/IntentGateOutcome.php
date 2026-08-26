<?php

declare(strict_types=1);

namespace Fissible\Verdict\Intents;

use Fissible\Verdict\Decisions\ExecutionResult;

/**
 * What the write-ahead intent gate decided, as one named shape (#331).
 *
 * The gate has three outcomes and only two shapes: the pipeline proceeds — carrying a committed
 * intent id, or carrying none because the lever is off for this capability — or it does not, and
 * the caller returns the denial verbatim.
 *
 * The two fields are mutually exclusive by construction: a private constructor behind two named
 * factories. That is the whole point of the type. This gate previously reported through an
 * `ExecutionResult|string|null` union, which every call site had to unpack by `instanceof` before
 * it could tell a denial from an intent id — correct at both sites, and one mis-unpack away from
 * executing an action whose intent write had refused.
 *
 * @internal Pipeline reporting between VerdictManager's own gates; not a supported surface.
 */
final readonly class IntentGateOutcome
{
    private function __construct(
        /** The denied result to return verbatim, or null when the pipeline may proceed. */
        public ?ExecutionResult $denial,
        /** The committed intent to thread into later records, or null when the lever is off. */
        public ?string $intentId,
    ) {}

    public static function proceed(?string $intentId): self
    {
        return new self(null, $intentId);
    }

    public static function refused(ExecutionResult $denial): self
    {
        return new self($denial, null);
    }
}
