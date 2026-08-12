# Capability starter patterns

These patterns compose Verdict's existing target-refresh and execution-claim policies. They do not
register a capability, create routes or persistence, supply a Laravel authorization policy, or
choose tenant, ownership, approval, or business-effect semantics.

## Refreshed mutable target

Use `ExecutionTargetPolicy::refresh()` when application data can change between proposal and
execution. Your application supplies both a canonical identity and an application-scoped refresh
query; do not replace either with an unscoped lookup.

```php
$capability = Capability::usingPolicy(
    name: 'orders.refund',
    ability: 'refund',
    resolveTarget: fn (ActionEnvelope $envelope): Order => $orders->forActor($actor)
        ->findOrFail($envelope->proposal->arguments['order_id']),
)->executionTarget(ExecutionTargetPolicy::refresh(
    name: 'tenant-order',
    identityUsing: fn (ActionEnvelope $envelope, Order $order): array => [
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
    ],
    refreshUsing: fn (ActionEnvelope $envelope, Order $order): Order => $orders->forActor($actor)
        ->findOrFail($order->id),
));
```

The application owns the initial lookup, the refresh query, the canonical identity, actor and
tenant scope, the Laravel policy, and the executor.

## One logical operation

Use `ExecutionClaimPolicy::named()` to attach Verdict's existing at-most-once admission to one
application-defined operation. Its `keyUsing` callback must express the business facts that make
two requests the same operation; it is not a transport call ID.

```php
$capability = $capability->atMostOnce(ExecutionClaimPolicy::named(
    name: 'tenant-order-refund',
    keyUsing: fn (ActionEnvelope $envelope, Order $order): array => [
        'tenant_id' => $tenant->id,
        'actor_id' => $actor->id,
        'order_id' => $order->id,
        'order_version' => $order->version,
    ],
));
```

The application owns the binding facts, the authorization policy, side effect, reconciliation of
indeterminate operations, and any downstream idempotency protocol.

## Testing starter patterns

Use the same generated and hand-written test shape as other capabilities. These patterns do not add
a test framework or registry; test the registered capability through `CapabilitySecurityTestKit`.

```php
CapabilitySecurityTestKit::for(app(VerdictManager::class), 'orders.refund')
    ->assertRefreshedTargetSubstitution($permittedEnvelope, $assertRefreshedSideEffects);

CapabilitySecurityTestKit::for(app(VerdictManager::class), 'orders.refund')
    ->assertExecutionClaimDuplicateAdmission($firstEnvelope, $duplicateEnvelope, $assertOneSideEffect);
```

Confirmation and rate-limit starter patterns are intentionally not provided yet: their application
callback contracts need to be settled before a structural helper can be safe.
