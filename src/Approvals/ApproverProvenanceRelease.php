<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\Context\ReleasePolicyRegistry;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Evidence\ProvenanceLedger;

/**
 * Discloses a proposal's declared upstream provenance to a human approver.
 *
 * Telling an approver where a proposal came from releases application data to a new audience, so it
 * travels the ordinary ADR 0008 path: a route the application has registered a policy for, a
 * projection of allowlisted fields, and release evidence like any other. See ADR 0026 §1.
 */
final readonly class ApproverProvenanceRelease
{
    /**
     * The fields an approver is offered about each upstream source. Identity and kind only — never
     * content, and never a fingerprint of it (ADR 0026 §2).
     *
     * @var list<string>
     */
    private const array DISCLOSED_PATHS = ['source', 'trust', 'data_class', 'channel'];

    /**
     * @internal Resolve ApproverProvenanceRelease from the container. This constructor is not part
     *           of the supported surface and may gain required parameters in any release.
     *           See docs/adr/0019-verdict-services-are-container-resolved.md.
     */
    public function __construct(
        private ProvenanceLedger $provenance,
        private ContextReleaseManager $releases,
        private ReleasePolicyRegistry $policies,
    ) {}

    /**
     * @param  ?string  $correlationId  the invocation that carried the proposal; null outside one,
     *                                  where there is no scope to read declared derivations in
     */
    public function disclose(?string $correlationId, string $proposalContentFingerprint): ProposalProvenance
    {
        // Asked before the ledger is read, and answered as its own disclosure state: with no
        // registered policy there is no audience to release to, and reporting "unknown" would
        // describe the ledger when the fact is about configuration.
        if (! $this->policies->hasRoute(ApproverAudience::source(), ApproverAudience::destination())) {
            return ProposalProvenance::unreleased();
        }

        if ($correlationId === null) {
            return ProposalProvenance::unknown();
        }

        $upstream = $this->provenance->declaredUpstreamOf($correlationId, $proposalContentFingerprint);

        if (! $upstream->isDeclared()) {
            return ProposalProvenance::unknown();
        }

        $sources = [];
        $withheld = 0;

        foreach ($upstream->entries as $entry) {
            if ($this->release($entry)) {
                $sources[] = UpstreamSource::fromEntry($entry);

                continue;
            }

            $withheld++;
        }

        return ProposalProvenance::declared(
            sources: $sources,
            undescribedSourceCount: count($upstream->unresolvedContentFingerprints),
            withheldSourceCount: $withheld,
        );
    }

    /**
     * The policy is consulted with the upstream entry's own trust and data class, so an application
     * that allowlists internal sources but not sensitive ones withholds the sensitive one and keeps
     * the rest — and the count of what it withheld stays visible to the approver.
     */
    private function release(ProvenanceEntry $entry): bool
    {
        return $this->releases->release(
            payload: [
                'source' => $entry->source->identity(),
                'trust' => $entry->trust->value,
                'data_class' => $entry->dataClass->value,
                'channel' => $entry->channel->value,
            ],
            source: ApproverAudience::source(),
            trust: $entry->trust,
            dataClass: $entry->dataClass,
            paths: self::DISCLOSED_PATHS,
            destination: ApproverAudience::destination(),
        )->permitted;
    }
}
