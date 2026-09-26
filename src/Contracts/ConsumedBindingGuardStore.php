<?php

declare(strict_types=1);

namespace Fissible\Verdict\Contracts;

use DateTimeInterface;

interface ConsumedBindingGuardStore
{
    public function has(string $digest): bool;

    public function remember(string $digest, DateTimeInterface $consumedAt, ?string $algorithm = null, ?string $keyVersion = null): void;
}
