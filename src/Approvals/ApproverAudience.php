<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Context\Destination;
use Fissible\Verdict\Context\Source;

/**
 * The release route Verdict uses to describe a proposal's origin to a human approver.
 *
 * Verdict ships the vocabulary; the application registers the policy. The names exist so that a
 * challenge and a ReleasePolicy can agree on what route they are talking about — what may travel
 * it remains the application's decision, and Verdict registering a default policy would be
 * ADR 0008's exemption wearing a default's clothing. Nothing is disclosed to an approver until an
 * application registers a policy for this route; see ADR 0026 §1 and
 * {@see ProvenanceDisclosure::Unreleased}.
 *
 * The source is Verdict's ledger rather than each upstream entry's own source, so that one
 * registration covers the route. Per-entry classification still decides what travels: the policy
 * is consulted with each upstream entry's own trust and data class.
 */
final readonly class ApproverAudience
{
    public static function source(): Source
    {
        return Source::application('verdict-provenance');
    }

    public static function destination(): Destination
    {
        return Destination::connection('approver', 'human');
    }
}
