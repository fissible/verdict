<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use Fissible\Verdict\Approvals\ApprovalStatusLookup;

/**
 * The read-side half of #425's opt-in collision seam, beside ApprovalStatusReader: the
 * observational read that distinguishes absence from a tool-call collision.
 *
 * ApprovalStatusReader::statusForToolCall() keeps its documented ambiguity and its nullable view,
 * so no existing reader or consumer has to move. A reader implements this when it can answer the
 * question honestly — which, for a store-backed reader, means only when its store implements
 * DistinguishesReceiptCollisions.
 */
interface DistinguishesStatusCollisions
{
    /**
     * The status read of this tool call as one of three outcomes: absent, single with its view, or
     * multiple with every colliding receipt id and no view.
     *
     * Implementations must never report absence for a tool call they cannot resolve — reporting an
     * unresolvable read as "nothing to review" is the defect #425 removed. A reader that cannot
     * answer therefore does not implement this interface at all, rather than implementing it and
     * throwing: instanceof is the probe consumers are told to trust, so it must not be a false
     * positive. The container pairs a custom store accordingly.
     */
    public function statusLookupForToolCall(string $toolCallId): ApprovalStatusLookup;
}
