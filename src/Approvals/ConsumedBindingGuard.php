<?php

declare(strict_types=1);

namespace Fissible\Verdict\Approvals;

final class ConsumedBindingGuard
{
    public static function digest(string $toolCallId, string $capability, string $bindingFingerprint): string
    {
        return hash('sha256', hash('sha256', $toolCallId).hash('sha256', $capability).$bindingFingerprint, true);
    }
}
