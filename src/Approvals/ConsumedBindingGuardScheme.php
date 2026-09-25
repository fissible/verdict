<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Exceptions\MissingConsumedBindingGuardKey;

/**
 * Resolves the configured consumed-binding guard keys into the digests issue() must probe and the
 * single guard consume() persists under the active scheme (ADR 0039, slice 10a). The candidate set
 * is always the keyless digest plus one per retained keyed version, so legacy keyless guards stay
 * checkable after migration. Keyed mode is not retroactive: only an explicit active version selects
 * the keyed write scheme. Fails closed — a retained or active key with no secret throws rather than
 * let a check skip a candidate or a write mint an unverifiable guard.
 */
final readonly class ConsumedBindingGuardScheme
{
    /**
     * @param  array<string,string>  $keys  Retained (append-only) map of key version to secret.
     */
    public function __construct(
        private array $keys = [],
        private ?string $activeKeyVersion = null,
    ) {}

    /**
     * @return list<string>
     */
    public function candidates(string $toolCallId, string $capability, string $bindingFingerprint): array
    {
        $candidates = [ConsumedBindingGuard::digest($toolCallId, $capability, $bindingFingerprint)];

        foreach ($this->keys as $version => $secret) {
            if ($secret === '') {
                throw new MissingConsumedBindingGuardKey((string) $version);
            }

            $candidates[] = ConsumedBindingGuard::keyed($toolCallId, $capability, $bindingFingerprint, $secret);
        }

        return $candidates;
    }

    public function active(string $toolCallId, string $capability, string $bindingFingerprint): DerivedGuard
    {
        if ($this->activeKeyVersion === null) {
            return new DerivedGuard(
                ConsumedBindingGuard::digest($toolCallId, $capability, $bindingFingerprint),
                null,
                null,
            );
        }

        $secret = $this->keys[$this->activeKeyVersion] ?? '';

        if ($secret === '') {
            throw new MissingConsumedBindingGuardKey($this->activeKeyVersion);
        }

        return new DerivedGuard(
            ConsumedBindingGuard::keyed($toolCallId, $capability, $bindingFingerprint, $secret),
            ConsumedBindingGuard::ALGORITHM_KEYED,
            $this->activeKeyVersion,
        );
    }
}
