<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use Fissible\Verdict\Contracts\AttestChainResolver;

final class StaticAttestChainResolver implements AttestChainResolver
{
    public static int $calls = 0;

    /** @var list<int> */
    public static array $instanceIds = [];

    public function resolve(): string
    {
        self::$instanceIds[] = spl_object_id($this);

        return 'tenant:'.(++self::$calls);
    }
}
