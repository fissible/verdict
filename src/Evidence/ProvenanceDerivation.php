<?php

declare(strict_types=1);

namespace Fissible\Verdict\Evidence;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProvenanceDerivation
{
    public function __construct(
        public string $correlationId,
        public string $childContentFingerprint,
        public string $parentContentFingerprint,
        public DerivationKind $kind,
        public DateTimeImmutable $recordedAt,
    ) {
        ProvenanceEntry::assertIdentifier($this->correlationId, 'Provenance correlation');
        ProvenanceEntry::assertFingerprint($this->childContentFingerprint, 'derived content');
        ProvenanceEntry::assertFingerprint($this->parentContentFingerprint, 'parent content');

        if ($this->childContentFingerprint === $this->parentContentFingerprint) {
            throw new InvalidArgumentException('A provenance derivation cannot derive content from itself.');
        }
    }
}
