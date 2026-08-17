<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use InvalidArgumentException;

/**
 * The declared upstream provenance of one piece of content.
 *
 * Undeclared is a first-class state rather than an empty list: a declared derivation may point at
 * content the application never recorded, so an empty entry list cannot also mean "nothing was
 * declared." See docs/adr/0026-what-an-approver-is-shown.md.
 */
final readonly class DeclaredUpstream
{
    /**
     * @param  list<ProvenanceEntry>  $entries
     * @param  list<string>  $unresolvedContentFingerprints
     */
    private function __construct(
        public array $entries,
        public array $unresolvedContentFingerprints,
    ) {}

    /** No derivation edge was declared for the content. */
    public static function undeclared(): self
    {
        return new self([], []);
    }

    /**
     * @param  list<ProvenanceEntry>  $entries
     * @param  list<string>  $unresolvedContentFingerprints  declared parents with no recorded entry
     */
    public static function declared(array $entries, array $unresolvedContentFingerprints = []): self
    {
        if ($entries === [] && $unresolvedContentFingerprints === []) {
            throw new InvalidArgumentException('Declared upstream provenance requires an entry or an unresolved content fingerprint. Use DeclaredUpstream::undeclared() to report that nothing was declared.');
        }

        return new self($entries, $unresolvedContentFingerprints);
    }

    public function isDeclared(): bool
    {
        return $this->entries !== [] || $this->unresolvedContentFingerprints !== [];
    }
}
