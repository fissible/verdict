<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Evidence\DeclaredUpstream;

/**
 * What an approver is told about where a proposal came from.
 *
 * Assembled from declared derivations only — never from invocation correlation, which would
 * manufacture a causal claim the provenance ledger refuses to make.
 * See docs/adr/0026-what-an-approver-is-shown.md §3.
 */
final readonly class ProposalProvenance
{
    /** @param list<UpstreamSource> $sources */
    private function __construct(
        public ProvenanceDisclosure $disclosure,
        public array $sources,
        public int $undescribedSourceCount,
    ) {}

    public static function fromDeclaredUpstream(DeclaredUpstream $upstream): self
    {
        if (! $upstream->isDeclared()) {
            return new self(ProvenanceDisclosure::Unknown, [], 0);
        }

        return new self(
            disclosure: ProvenanceDisclosure::Declared,
            sources: array_map(UpstreamSource::fromEntry(...), $upstream->entries),
            undescribedSourceCount: count($upstream->unresolvedContentFingerprints),
        );
    }
}
