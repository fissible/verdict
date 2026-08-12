<?php

declare(strict_types=1);

namespace Fissible\Verdict\Testing;

use RuntimeException;

final class CapabilitySecurityAssertionFailed extends RuntimeException
{
    public static function forInvariant(string $capability, string $invariant): self
    {
        return new self("Capability [{$capability}] failed security test invariant [{$invariant}].");
    }
}
