<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Context\ReleasePolicyRegistry;
use LogicException;

/**
 * Whether unattributable consequential proposals are denied, and whether that is even coherent.
 *
 * Strict mode is opt-in and denies at the confirmation gate (ADR 0026 §5). It depends on the
 * approver disclosure working at all, which depends on the application having registered a release
 * policy for the approver route.
 */
final readonly class StrictProvenanceGuard
{
    /**
     * @internal Resolve StrictProvenanceGuard from the container. This constructor is not part of
     *           the supported surface and may gain required parameters in any release.
     *           See docs/adr/0019-verdict-services-are-container-resolved.md.
     */
    public function __construct(
        private bool $enabled,
        private ReleasePolicyRegistry $policies,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Refuse a configuration that defeats its own purpose.
     *
     * Strict mode with no approver route registered denies proposals for having provenance nobody
     * can be shown, while making that provenance undeliverable — the application has asked for a
     * control and disabled the thing the control exists to serve.
     *
     * A registered policy that permits little or nothing is NOT this contradiction. Denying broadly
     * is an adopter deciding what may travel, which is theirs to decide; registering nothing at all
     * is an adopter not deciding while demanding the decision be enforced.
     */
    public function assertSatisfiable(): void
    {
        if (! $this->enabled) {
            return;
        }

        if ($this->policies->hasRoute(ApproverAudience::source(), ApproverAudience::destination())) {
            return;
        }

        throw new LogicException(
            'verdict.approvals.strict_provenance is enabled but no context release policy is registered for the approver route ('
            .ApproverAudience::source()->identity().' -> '.ApproverAudience::destination()->identity()
            .'). Strict mode denies proposals whose provenance is unknown while this configuration makes provenance undeliverable to approvers. '
            .'Register a ReleasePolicy for that route, or disable verdict.approvals.strict_provenance.',
        );
    }
}
