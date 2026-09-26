<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

use Fissible\Verdict\Exceptions\InvalidConsumedBindingGuardConfig;
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
     * The shortest secret accepted as a key: a shorter one is a misconfiguration, not a key, and
     * would ship a trivially brute-forceable HMAC. Applied to every retained secret, not only the
     * active one, because retained secrets feed the replay-detection probe.
     */
    public const int MINIMUM_SECRET_LENGTH = 32;

    /**
     * @param  array<string,string>  $keys  Retained (append-only) map of key version to secret.
     */
    public function __construct(
        private array $keys = [],
        private ?string $activeKeyVersion = null,
    ) {}

    /**
     * Build the scheme from the operator-supplied verdict.approvals.consumed_binding_guard config,
     * failing closed on any malformation rather than degrading to keyless (ADR 0039). Null config is
     * the keyless default; anything present but malformed throws InvalidConsumedBindingGuardConfig.
     */
    public static function fromConfig(mixed $config): ?self
    {
        if ($config === null) {
            return null;
        }

        if (! is_array($config)) {
            throw new InvalidConsumedBindingGuardConfig(
                'verdict.approvals.consumed_binding_guard must be an array or null.'
            );
        }

        $configuredKeys = $config['keys'] ?? [];

        if (! is_array($configuredKeys)) {
            throw new InvalidConsumedBindingGuardConfig(
                'verdict.approvals.consumed_binding_guard.keys must be an array.'
            );
        }

        $keys = [];

        foreach ($configuredKeys as $version => $secret) {
            if (! is_string($secret) || strlen($secret) < self::MINIMUM_SECRET_LENGTH) {
                throw new InvalidConsumedBindingGuardConfig(
                    "verdict.approvals.consumed_binding_guard.keys[{$version}] must be a string of at least "
                    .self::MINIMUM_SECRET_LENGTH.' characters.'
                );
            }

            $keys[(string) $version] = $secret;
        }

        $activeKey = $config['active_key'] ?? null;

        if ($activeKey === null) {
            return new self($keys, null);
        }

        if (! is_scalar($activeKey)) {
            throw new InvalidConsumedBindingGuardConfig(
                'verdict.approvals.consumed_binding_guard.active_key must be a scalar key version or null.'
            );
        }

        $activeKeyVersion = (string) $activeKey;

        if (! array_key_exists($activeKeyVersion, $keys)) {
            throw new InvalidConsumedBindingGuardConfig(
                "verdict.approvals.consumed_binding_guard.active_key [{$activeKeyVersion}] is not among the retained keys."
            );
        }

        return new self($keys, $activeKeyVersion);
    }

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
