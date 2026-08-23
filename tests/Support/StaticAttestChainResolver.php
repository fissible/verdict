<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use Fissible\Verdict\Contracts\AttestChainResolver;

final class StaticAttestChainResolver implements AttestChainResolver
{
    public static int $calls = 0;

    public static int $constructions = 0;

    /**
     * One entry per resolve() call, identifying which *construction* made it.
     *
     * Deliberately not `spl_object_id($this)`: PHP reuses a freed object's id, so when the first
     * resolver is collected before the second is built, two genuinely distinct instances report the
     * same id and a freshness assertion fails for a reason that has nothing to do with the code
     * under test. A construction counter cannot be recycled. See #137/#184 for the same trap.
     *
     * @var list<int>
     */
    public static array $instanceIds = [];

    private readonly int $construction;

    public function __construct()
    {
        $this->construction = ++self::$constructions;
    }

    public function resolve(): string
    {
        self::$instanceIds[] = $this->construction;

        return 'tenant:'.(++self::$calls);
    }
}
