<?php

declare(strict_types=1);

namespace Workbench\App\Storefront;

use Illuminate\Auth\Access\Response;

/**
 * Authorizes the *scope*, not a record: for a set-returning tool there is no single target to
 * inspect, so the policy question becomes "may this actor search within this scope". The scope is
 * context-resolved and therefore always the actor's own by construction; this check is the
 * defense-in-depth statement of that invariant, and the place a richer scope (a team, a tenant, a
 * delegated account) would express who may search it.
 */
final class OrderSearchScopePolicy
{
    public function search(Customer $customer, OrderSearchScope $scope): Response
    {
        return $customer->id === $scope->customerId
            ? Response::allow('Customer searches within their own order scope.')
            : Response::deny("The scope belongs to customer {$scope->customerId}.");
    }
}
