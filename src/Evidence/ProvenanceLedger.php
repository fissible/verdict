<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Contracts\ProvenanceLedgerStore;
use InvalidArgumentException;
use LogicException;

final readonly class ProvenanceLedger
{
    /**
     * @internal Resolve ProvenanceLedger from the container. This constructor is not part of the
     *           supported surface and may gain required parameters in any release.
     *           See docs/adr/0019-verdict-services-are-container-resolved.md.
     */
    public function __construct(
        private EvidenceWriter $writer,
        private ProvenanceLedgerStore $store,
        private Clock $clock,
    ) {}

    public function record(
        string $correlationId,
        Source $source,
        Trust $trust,
        DataClass $dataClass,
        ContextChannel $channel,
        mixed $content,
        ?string $componentLabel = null,
        ?string $componentVersion = null,
    ): ProvenanceEntry {
        ProvenanceEntry::assertIdentifier($correlationId, 'Provenance correlation');

        if ($componentLabel !== null) {
            ProvenanceEntry::assertIdentifier($componentLabel, 'Provenance component');
        }

        if ($componentVersion !== null) {
            ProvenanceEntry::assertIdentifier($componentVersion, 'Provenance component version');
        }

        if ($componentVersion !== null && $componentLabel === null) {
            throw new InvalidArgumentException('A provenance component version requires a component label.');
        }

        $entry = new ProvenanceEntry(
            correlationId: $correlationId,
            source: $source,
            trust: $trust,
            dataClass: $dataClass,
            channel: $channel,
            contentFingerprint: ContentFingerprint::make($content),
            componentLabel: $componentLabel,
            componentFingerprint: $componentVersion === null
                ? null
                : ContentFingerprint::make($componentVersion),
            recordedAt: $this->clock->now(),
        );

        $this->writer->recordProvenance($entry);

        return $entry;
    }

    /** @return list<ProvenanceEntry> */
    public function forCorrelation(string $correlationId): array
    {
        ProvenanceEntry::assertIdentifier($correlationId, 'Provenance correlation');

        return $this->store->provenanceFor($correlationId);
    }

    /**
     * @param  list<string>  $parentContentFingerprints
     * @return list<ProvenanceDerivation>
     */
    public function declareDerivation(
        string $correlationId,
        string $childContentFingerprint,
        array $parentContentFingerprints,
        DerivationKind $kind,
    ): array {
        ProvenanceEntry::assertIdentifier($correlationId, 'Provenance correlation');
        ProvenanceEntry::assertFingerprint($childContentFingerprint, 'derived content');

        if ($parentContentFingerprints === []) {
            throw new InvalidArgumentException('A provenance derivation requires at least one parent content fingerprint.');
        }

        $derivations = [];

        foreach (array_values(array_unique($parentContentFingerprints)) as $parentContentFingerprint) {
            ProvenanceEntry::assertFingerprint($parentContentFingerprint, 'parent content');

            if ($this->reaches($correlationId, $parentContentFingerprint, $childContentFingerprint, [])) {
                throw new LogicException('A provenance derivation cannot create a cycle.');
            }

            $derivation = new ProvenanceDerivation(
                correlationId: $correlationId,
                childContentFingerprint: $childContentFingerprint,
                parentContentFingerprint: $parentContentFingerprint,
                kind: $kind,
                recordedAt: $this->clock->now(),
            );
            $this->writer->recordDerivation($derivation);
            $derivations[] = $derivation;
        }

        return $derivations;
    }

    /** @return list<string> */
    public function backwardReachableContentFingerprints(string $correlationId, string $childContentFingerprint): array
    {
        ProvenanceEntry::assertIdentifier($correlationId, 'Provenance correlation');
        ProvenanceEntry::assertFingerprint($childContentFingerprint, 'derived content');

        $reachable = [];
        $this->collectAncestors($correlationId, $childContentFingerprint, $reachable);

        return $reachable;
    }

    /**
     * Summarises the provenance declared upstream of one piece of content.
     *
     * Traverses declared derivation edges only. Entries that merely share the correlation are never
     * reported as upstream: sharing an invocation is not evidence of influence.
     *
     * The traversal is scoped to one invocation at every hop, which matters because the anchor is
     * content-addressed: identical tool-call arguments in two invocations are the same node, and an
     * unscoped walk would let a derivation declared for Tuesday's proposal surface as Thursday's
     * provenance. Nobody declared that, and it would be right often enough to be trusted.
     */
    public function declaredUpstreamOf(string $correlationId, string $contentFingerprint): DeclaredUpstream
    {
        $parents = $this->backwardReachableContentFingerprints($correlationId, $contentFingerprint);

        if ($parents === []) {
            return DeclaredUpstream::undeclared();
        }

        /** @var array<string, list<ProvenanceEntry>> $recorded */
        $recorded = [];

        foreach ($this->store->provenanceFor($correlationId) as $entry) {
            $recorded[$entry->contentFingerprint][] = $entry;
        }

        $entries = [];
        $unresolved = [];

        foreach ($parents as $parent) {
            if (! isset($recorded[$parent])) {
                $unresolved[] = $parent;

                continue;
            }

            foreach ($recorded[$parent] as $entry) {
                $entries[] = $entry;
            }
        }

        return DeclaredUpstream::declared($entries, $unresolved);
    }

    /** @param array<string, true> $visited */
    private function reaches(string $correlationId, string $from, string $target, array $visited): bool
    {
        if ($from === $target) {
            return true;
        }

        if (isset($visited[$from])) {
            return false;
        }

        $visited[$from] = true;

        foreach ($this->store->derivationsFor($correlationId, $from) as $derivation) {
            if ($this->reaches($correlationId, $derivation->parentContentFingerprint, $target, $visited)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $reachable */
    private function collectAncestors(string $correlationId, string $childContentFingerprint, array &$reachable): void
    {
        foreach ($this->store->derivationsFor($correlationId, $childContentFingerprint) as $derivation) {
            $parent = $derivation->parentContentFingerprint;

            if (in_array($parent, $reachable, true)) {
                continue;
            }

            $reachable[] = $parent;
            $this->collectAncestors($correlationId, $parent, $reachable);
        }
    }
}
