<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Intents\ActionIntent;

/**
 * Durable storage for write-ahead intent records (#160).
 *
 * Intent rows are operational security state, not evidence: with the intent lever on, the write
 * gates admission to the mutating phase, in the layer where Verdict's other guarantees live
 * (ADR 0007's update for #160). The contract is deliberately write-once — record and find, no
 * update, no delete, no status transition. An intent with no outcome evidence referencing it is
 * the gap signal; a store that could rewrite intents could also erase the gap.
 */
interface ActionIntentStore
{
    /**
     * Persist one intent record durably, or throw.
     *
     * A throw here is the fail-closed signal: the caller treats it as "this action must not
     * begin", returns a policy-shaped denial, and consumes nothing. Implementations must not
     * swallow failures.
     */
    public function record(ActionIntent $intent): void;

    public function find(string $id): ?ActionIntent;
}
