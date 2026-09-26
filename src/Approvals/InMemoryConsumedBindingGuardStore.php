<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use DateTimeImmutable;
use DateTimeInterface;
use Fissible\Verdict\Contracts\ConsumedBindingGuardStore;

final class InMemoryConsumedBindingGuardStore implements ConsumedBindingGuardStore
{
    /** @var array<string, DateTimeImmutable> */
    private array $guards = [];

    public function has(string $digest): bool
    {
        return isset($this->guards[$digest]);
    }

    public function remember(string $digest, DateTimeInterface $consumedAt, ?string $algorithm = null, ?string $keyVersion = null): void
    {
        $this->guards[$digest] ??= DateTimeImmutable::createFromInterface($consumedAt);
    }
}
