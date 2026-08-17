<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Fixtures\ManyBrokenCapabilities;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\DefinesCapability;
use LogicException;

/** A falsely affirmed definition whose TODO throws while make() is still building. */
final class BrokenClaimCapability implements DefinesCapability
{
    public static function make(): Capability
    {
        return Capability::usingPolicy(
            name: 'broken.claim',
            ability: 'update',
            resolveTarget: fn (ActionEnvelope $envelope): int => 1,
        )->executeUsing(self::sideEffect());
    }

    private static function sideEffect(): callable
    {
        throw new LogicException('TODO: bind duplicate admission to canonical application-owned identity.');
    }
}
