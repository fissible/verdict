<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Fixtures\Capabilities\Nested;

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\DefinesCapability;

/** Dotted capability names generate subdirectories, so discovery must recurse. */
final class NestedAffirmedCapability implements DefinesCapability
{
    public static function make(): Capability
    {
        return Capability::usingPolicy(
            name: 'fixtures.nested',
            ability: 'view',
            resolveTarget: fn (ActionEnvelope $envelope): int => 1,
        )->executeUsing(fn (): string => 'nested');
    }
}
