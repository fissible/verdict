<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Fixtures\LegacyCapabilities;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;

/**
 * An app/Capabilities/ class as it exists in every application that generated one before discovery
 * shipped: the right shape, no contract. Discovery being on by default is only safe if a directory
 * full of these registers nothing and fails nothing.
 */
final class LegacyOrderCapability
{
    public static function make(): Capability
    {
        return Capability::usingPolicy(
            name: 'fixtures.legacy',
            ability: 'view',
            resolveTarget: fn (ActionEnvelope $envelope): int => 1,
        )->executeUsing(fn (): string => 'legacy');
    }
}
