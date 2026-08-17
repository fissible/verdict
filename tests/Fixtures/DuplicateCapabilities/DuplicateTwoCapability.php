<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Fixtures\DuplicateCapabilities;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\DefinesCapability;

/** Copy-paste a capability file, forget to rename it: two affirmed classes, one capability name. */
final class DuplicateTwoCapability implements DefinesCapability
{
    public static function make(): Capability
    {
        return Capability::usingPolicy(
            name: 'fixtures.duplicate',
            ability: 'view',
            resolveTarget: fn (ActionEnvelope $envelope): int => 1,
        )->executeUsing(fn (): string => 'duplicate-Two');
    }
}
