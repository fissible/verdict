<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use Fissible\Verdict\Contracts\AttestChainResolver;
use RuntimeException;

final class ThrowingAttestChainResolver implements AttestChainResolver
{
    public function resolve(): string
    {
        throw new RuntimeException('tenant resolution failed');
    }
}
