<?php

declare(strict_types=1);

namespace Fissible\Verdict\Context;

use LogicException;

final class ReleasePolicyRegistry
{
    /** @var array<string, ReleasePolicy> */
    private array $policies = [];

    public function register(ReleasePolicy $policy): self
    {
        if (array_key_exists($policy->routeKey(), $this->policies)) {
            throw new LogicException("A context release policy is already registered for [{$policy->routeKey()}].");
        }

        $this->policies[$policy->routeKey()] = $policy;

        return $this;
    }

    public function permits(Source $source, Destination $destination, DataClass $dataClass, Trust $trust): bool
    {
        $key = $source->identity().'->'.$destination->identity();

        return ($this->policies[$key] ?? null)?->permits($source, $destination, $dataClass, $trust) ?? false;
    }

    /** @return array<string, ReleasePolicy> */
    public function all(): array
    {
        return $this->policies;
    }
}
