<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use Fissible\Verdict\Contracts\AttestChainResolver;

final class StaticAttestChainResolver implements AttestChainResolver
{
    public static int $calls = 0;

    public function resolve(): string
    {
        return 'tenant:'.(++self::$calls);
    }
}
