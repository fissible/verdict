<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

/**
 * The single consumed-binding guard a consume() write persists: the raw 32-byte digest, plus the
 * algorithm label and key version identifying the scheme it was derived under. Keyless guards carry
 * a null algorithm and key version; a keyed guard names both (ADR 0039).
 */
final readonly class DerivedGuard
{
    public function __construct(
        public string $digest,
        public ?string $algorithm,
        public ?string $keyVersion,
    ) {}
}
