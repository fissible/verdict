<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Fixtures\ThrowingCapabilities;

use App\Models\Order;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\DefinesCapability;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use LogicException;

/**
 * Verbatim `verdict:make-capability orders.refund --rate-limit` output, with only the namespace, the
 * class name, and the (falsely affirmed) contract changed.
 *
 * Kept verbatim on purpose: it fails for real rather than by contrivance, so this one fixture guards
 * two invariants at once — that discovery lets a broken definition fail the boot, and that the
 * generator's output stays fail-closed. If someone ever makes the generated TODOs non-throwing, this
 * test goes green for the wrong reason and the paired assertion on the cause message catches it.
 *
 * `->rateLimit(self::rateLimitPolicy())` throws while `make()` is still building, before any closure
 * runs — which is why a falsely affirmed capability of this shape is caught at boot rather than at
 * first invocation. See ADR 0027 §4.
 */
final class ThrowingRateLimitCapability implements DefinesCapability
{
    public static function make(): Capability
    {
        return Capability::usingPolicy(
            name: 'orders.refund',
            ability: 'update',
            resolveTarget: function (ActionEnvelope $envelope): Order {
                throw new LogicException('TODO: resolve order_id through tenant- and ownership-scoped application data.');
            },
        )
            ->executionTarget(ExecutionTargetPolicy::refresh(
                name: 'orders.refund-target',
                identityUsing: function (ActionEnvelope $envelope, Order $target): array {
                    throw new LogicException('TODO: return stable, canonical target identity.');
                },
                refreshUsing: function (ActionEnvelope $envelope, Order $target): Order {
                    throw new LogicException('TODO: re-load the target through tenant- and ownership-scoped application data.');
                },
            ))
            ->rateLimit(self::rateLimitPolicy())
            ->executeUsing(function (AuthorizedAction $action): void {
                throw new LogicException('TODO: implement the application side effect after its security material is defined.');
            });
    }

    private static function rateLimitPolicy(): RateLimitPolicy
    {
        throw new LogicException('TODO: choose application-owned rate-limit scope, limit, window, and binding.');
    }
}
