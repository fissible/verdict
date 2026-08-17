<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Fixtures\Capabilities;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\DefinesCapability;

/** A finished definition: affirmed, instantiable, and its make() builds cleanly. */
final class AffirmedCapability implements DefinesCapability
{
    public static function make(): Capability
    {
        return Capability::usingPolicy(
            name: 'fixtures.affirmed',
            ability: 'view',
            resolveTarget: fn (ActionEnvelope $envelope): int => 1,
        )->executeUsing(fn (): string => 'affirmed');
    }
}
