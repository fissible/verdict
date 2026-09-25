<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

/**
 * Derives the permanent consumed-binding guard digest from the (tool_call_id, capability,
 * binding_fingerprint) triple. Keyless by default (an unsalted SHA-256); a keyed HMAC over the same
 * canonical preimage is the opt-in for deployments whose threat model includes a long-lived
 * database-read compromise (ADR 0039). Both schemes emit 32 raw bytes for the fixed-width digest
 * column, and both hash each triple component to a fixed width first, so the component boundaries
 * cannot be shifted to forge a collision.
 */
final class ConsumedBindingGuard
{
    /** The algorithm label persisted alongside a keyed guard row (keyless rows persist null). */
    public const string ALGORITHM_KEYED = 'hmac-sha256';

    public static function digest(string $toolCallId, string $capability, string $bindingFingerprint): string
    {
        return hash('sha256', self::preimage($toolCallId, $capability, $bindingFingerprint), true);
    }

    public static function keyed(string $toolCallId, string $capability, string $bindingFingerprint, string $secret): string
    {
        return hash_hmac('sha256', self::preimage($toolCallId, $capability, $bindingFingerprint), $secret, true);
    }

    private static function preimage(string $toolCallId, string $capability, string $bindingFingerprint): string
    {
        return hash('sha256', $toolCallId).hash('sha256', $capability).$bindingFingerprint;
    }
}
