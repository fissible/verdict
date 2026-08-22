<?php

declare(strict_types=1);

namespace Workbench\App\Storefront;

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Exceptions\TargetNotResolvable;
use Illuminate\Database\Query\Builder;

/**
 * The scope-as-target value object for the set-returning `orders.search` capability (#251): the
 * boundary's answer to "what is the target when a lookup becomes a search". A scalar-keyed
 * capability resolves a record; this capability's context-resolved resolver returns *this* — a
 * scope bound to the actor alone, derived from the trusted `ActionContext` and never from the
 * model's arguments — and the policy authorizes the scope. The executor then applies it as the
 * query predicate, which is what moves the tenant filter inside the boundary: resolver code,
 * carried in evidence, observable at the connection.
 *
 * `constrain()` is discipline, not a guarantee (#251 round 2): any query builder handle composes,
 * so nothing can *prevent* an executor from widening after constraining. The guarantee lives one
 * layer down — the executed predicate is captured at the connection and its digest compared
 * against the declared scope shape — and this object exists to make the disciplined path the
 * easy one.
 *
 * Deliberately workbench-only, answering the issue's open design question: Verdict ships no
 * scope-target contract yet. `resolveTarget` returns `mixed`, so nothing in core needs to know
 * this type; a marker interface earns its place when a second consumer (the test kit driving
 * scope-as-target capabilities generically, or #237's reference app) actually needs one.
 */
final readonly class OrderSearchScope
{
    private function __construct(public int $customerId) {}

    public static function forContext(ActionContext $context): self
    {
        $actor = $context->actor;

        if (! $actor instanceof Customer) {
            throw TargetNotResolvable::make();
        }

        return new self($actor->id);
    }

    public function constrain(Builder $query): Builder
    {
        return $query->where('customer_id', $this->customerId);
    }
}
