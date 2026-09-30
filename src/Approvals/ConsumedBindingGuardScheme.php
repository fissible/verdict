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
        $effectiveKeys = [];

        foreach ($configuredKeys as $version => $secret) {
            if (strlen((string) $version) > 255) {
                throw new InvalidConsumedBindingGuardConfig(
                    'verdict.approvals.consumed_binding_guard.keys version labels must not exceed the key_version storage limit of 255 bytes.'
                );
            }

            if (! is_string($secret) || strlen($secret) < self::MINIMUM_SECRET_LENGTH) {
                throw new InvalidConsumedBindingGuardConfig(
                    "verdict.approvals.consumed_binding_guard.keys[{$version}] must be a string of at least "
                    .self::MINIMUM_SECRET_LENGTH.' characters.'
                );
            }

            $keys[(string) $version] = $secret;
            // HMAC-SHA256 pre-hashes only keys longer than its block, then zero-pads to 64 bytes.
            $effectiveKeys[] = str_pad(strlen($secret) > 64 ? hash('sha256', $secret, true) : $secret, 64, "\0");
        }

        // Two versions sharing an effective HMAC key produce identical digests, so a probe match could not
        // attribute a version and the after-match validation would be ambiguous. Enforced across
        // every retained key (active or not), catching non-adjacent and active-vs-retained pairs.
        if (count(array_unique($effectiveKeys, SORT_STRING)) !== count($effectiveKeys)) {
            throw new InvalidConsumedBindingGuardConfig(
                'verdict.approvals.consumed_binding_guard.keys must not share an effective HMAC-SHA256 key across versions.'
            );
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
     * Whether any keyed version is retained. A scheme with no keys is keyless-effective: it derives
     * only the unkeyed digest, so it can neither re-probe nor re-derive a surviving keyed guard.
     */
    public function hasKeys(): bool
    {
        return $this->keys !== [];
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

    /**
     * Whether the stored guard's own metadata self-consistently describes its digest (ADR 0039's
     * after-match validation): re-derive the digest from the stored scheme and compare. Fails closed
     * — returning false, never throwing — for any claim this scheme cannot re-derive (an unknown
     * algorithm, a partial-null metadata pair, or a keyed version no longer retained), so the probe
     * can convert the inconsistency into a ConsumedBindingGuardSchemeMismatch.
     */
    public function describes(DerivedGuard $stored, string $toolCallId, string $capability, string $bindingFingerprint): bool
    {
        if ($stored->algorithm === null && $stored->keyVersion === null) {
            return $stored->digest === ConsumedBindingGuard::digest($toolCallId, $capability, $bindingFingerprint);
        }

        if ($stored->algorithm === ConsumedBindingGuard::ALGORITHM_KEYED && $stored->keyVersion !== null) {
            if (! array_key_exists($stored->keyVersion, $this->keys)) {
                return false;
            }

            return $stored->digest === ConsumedBindingGuard::keyed(
                $toolCallId,
                $capability,
                $bindingFingerprint,
                $this->keys[$stored->keyVersion],
            );
        }

        return false;
    }
}
