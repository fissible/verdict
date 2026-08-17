<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use InvalidArgumentException;

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
        public int $withheldSourceCount,
    ) {}

    /** No derivation was declared upstream of the proposal. */
    public static function unknown(): self
    {
        return new self(ProvenanceDisclosure::Unknown, [], 0, 0);
    }

    /** The application has registered no release policy for the approver route. */
    public static function unreleased(): self
    {
        return new self(ProvenanceDisclosure::Unreleased, [], 0, 0);
    }

    /**
     * @param  list<UpstreamSource>  $sources
     * @param  int  $undescribedSourceCount  declared upstream the ledger holds no entry for
     * @param  int  $withheldSourceCount  declared upstream the release policy did not permit
     */
    public static function declared(
        array $sources,
        int $undescribedSourceCount = 0,
        int $withheldSourceCount = 0,
    ): self {
        if ($sources === [] && $undescribedSourceCount === 0 && $withheldSourceCount === 0) {
            throw new InvalidArgumentException('A declared disclosure describes at least one upstream source, or counts the ones it could not describe. Use ProposalProvenance::unknown() to report that nothing was declared.');
        }

        return new self(
            disclosure: ProvenanceDisclosure::Declared,
            sources: $sources,
            undescribedSourceCount: $undescribedSourceCount,
            withheldSourceCount: $withheldSourceCount,
        );
    }

    /**
     * Persisted on the receipt so that the challenge an approver reads — often in another process,
     * long after the invocation ended — carries the payload assembled while the invocation was in
     * scope. See ADR 0026 §6.
     *
     * @return array{disclosure: string, sources: list<array<string, string>>, undescribed_source_count: int, withheld_source_count: int}
     */
    public function toArray(): array
    {
        return [
            'disclosure' => $this->disclosure->value,
            'sources' => array_map(static fn (UpstreamSource $source): array => $source->toArray(), $this->sources),
            'undescribed_source_count' => $this->undescribedSourceCount,
            'withheld_source_count' => $this->withheldSourceCount,
        ];
    }

    /** @param array<string, mixed> $attributes */
    public static function fromArray(array $attributes): self
    {
        $disclosure = ProvenanceDisclosure::from((string) ($attributes['disclosure'] ?? ''));

        if ($disclosure !== ProvenanceDisclosure::Declared) {
            return $disclosure === ProvenanceDisclosure::Unknown ? self::unknown() : self::unreleased();
        }

        $sources = $attributes['sources'] ?? [];

        if (! is_array($sources)) {
            throw new InvalidArgumentException('Stored proposal provenance sources must be an array.');
        }

        return self::declared(
            sources: array_values(array_map(
                static fn (mixed $source): UpstreamSource => UpstreamSource::fromArray(is_array($source) ? $source : []),
                $sources,
            )),
            undescribedSourceCount: (int) ($attributes['undescribed_source_count'] ?? 0),
            withheldSourceCount: (int) ($attributes['withheld_source_count'] ?? 0),
        );
    }
}
