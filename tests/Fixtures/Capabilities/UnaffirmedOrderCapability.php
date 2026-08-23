<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Fixtures\Capabilities;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;

/**
 * The generator's shape without the contract: either unfinished, or finished and never affirmed.
 * Discovery must leave it inert, and verdict:validate must say it is there.
 */
final class UnaffirmedOrderCapability
{
    public static function make(): Capability
    {
        return Capability::usingPolicy(
            name: 'fixtures.unaffirmed',
            ability: 'view',
            resolveTarget: fn (ActionEnvelope $envelope): int => 1,
        )->executeUsing(fn (): string => 'unaffirmed');
    }
}
