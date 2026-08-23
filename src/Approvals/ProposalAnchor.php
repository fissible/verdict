<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Evidence\ContentFingerprint;

/**
 * The provenance node a tool call's proposed arguments hang from.
 *
 * An application declaring where a proposal came from and Verdict reading it back must land on the
 * same node, so this is the single supported way to compute it. A hand-rolled
 * `hash('sha256', json_encode($arguments))` differs on key order and encoding flags, and a
 * declaration made against that hash is unreachable by construction — it would not fail, it would
 * quietly never match, and every approver would be told the origin is unknown.
 *
 * The digest is the ledger's own content fingerprint, so declaring a derivation against the entry
 * recorded for those arguments and declaring one against this anchor are the same declaration.
 *
 * Attribution is scoped to the invocation that carried the call: identical arguments in a later
 * invocation are the same node but a different proposal, and the ledger read refuses to carry one
 * invocation's declarations to another. See ADR 0026 §3.
 */
final readonly class ProposalAnchor
{
    /** @param array<string, mixed> $arguments */
    public static function for(array $arguments): string
    {
        return ContentFingerprint::make($arguments);
    }
}
