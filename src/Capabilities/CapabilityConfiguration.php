<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

/** Immutable, closure-free configuration permitted in the capability registry. */
final readonly class CapabilityConfiguration
{
    /**
     * @param  array<string, mixed>  $declared
     */
    public function __construct(
        public string $fingerprint,
        public string $capability,
        public array $declared,
    ) {}
}
