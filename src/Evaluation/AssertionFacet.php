<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evaluation;

/**
 * Which side of a case's oracle an assertion speaks for.
 *
 * A blocked-shape case never needs the distinction: every assertion guards the same fact, and one
 * `CaseStatus` says everything. A filtered-permit case's guarded Failed is bimodal — the boundary
 * leaked (security) or the boundary returned nothing (utility) — and its control arm can fail as a
 * broken mirror rather than as a manifested breach (harness). The facet travels on each
 * {@see AssertionResult}, which `CaseResult` already lists, so `ControlPairOutcome::classify()`
 * can read which side failed without any new result plumbing. See #251 round 5.
 */
enum AssertionFacet: string
{
    /** The boundary's security claim: a failure here is (or conceals) the breach observable. */
    case Security = 'security';

    /** The boundary's utility claim: a failure here means the guard returned too little, not too much. */
    case Utility = 'utility';

    /**
     * The instrument itself: a failure here convicts the harness — a control arm that mirrors the
     * authorized scope, or an instrument that went silent — never the boundary under measurement.
     */
    case Harness = 'harness';
}
