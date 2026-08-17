<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Fixtures\MixedCapabilities;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\DefinesCapability;

/** A finished definition sharing a directory with a broken one. */
final class MixedWorkingCapability implements DefinesCapability
{
    public static function make(): Capability
    {
        return Capability::usingPolicy(
            name: 'mixed.working',
            ability: 'view',
            resolveTarget: fn (ActionEnvelope $envelope): int => 1,
        )->executeUsing(fn (): string => 'working');
    }
}
