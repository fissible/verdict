<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use DateTimeImmutable;
use DateTimeInterface;
use Fissible\Verdict\Contracts\ConsumedBindingGuardStore;

final class InMemoryConsumedBindingGuardStore implements ConsumedBindingGuardStore
{
    /** @var array<string, array{consumedAt: DateTimeImmutable, algorithm: ?string, keyVersion: ?string}> */
    private array $guards = [];

    public function has(string $digest): bool
    {
        return isset($this->guards[$digest]);
    }

    public function lookup(string $digest): ?DerivedGuard
    {
        if (! isset($this->guards[$digest])) {
            return null;
        }

        $guard = $this->guards[$digest];

        return new DerivedGuard($digest, $guard['algorithm'], $guard['keyVersion']);
    }

    public function remember(string $digest, DateTimeInterface $consumedAt, ?string $algorithm = null, ?string $keyVersion = null): void
    {
        $this->guards[$digest] ??= [
            'consumedAt' => DateTimeImmutable::createFromInterface($consumedAt),
            'algorithm' => $algorithm,
            'keyVersion' => $keyVersion,
        ];
    }
}
