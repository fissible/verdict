# Capability security test kit

`CapabilitySecurityTestKit` drives a capability through `VerdictManager::runBound()` without
depending on Pest, PHPUnit, or a test-double version of Verdict. It raises
`CapabilitySecurityAssertionFailed`, so use it from any PHP test runner.

Create a fresh kit in each test for the capability your application has already registered. This
matches the normal provider-based registration and prevents a test helper from silently replacing
production wiring.

Approval decisions are fail-closed, and `assertApprovalBindingInvalidation()` decides a receipt —
so the test environment must configure an authorizer, or that assertion throws
`ApprovalAuthorizerMissing`. Verdict ships `Fissible\Verdict\Testing\AllowAllApprovalAuthorizer`
for exactly this: set `verdict.approvals.authorizer` to it in the test environment. It authorizes
everything, exercising receipt state machinery while deliberately not testing per-receipt
authorization — cover your own authorizer's deny paths in its own tests, and never configure the
allow-all class outside local/testing (`verdict:validate` warns if you do).

```php
use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Testing\CapabilitySecurityTestKit;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\VerdictManager;

$issuedRefunds = [];
$capability = Capability::usingPolicy(
    name: 'orders.refund',
    ability: 'refund',
    resolveTarget: fn (ActionEnvelope $envelope): Order => Order::findOrFail(
        $envelope->proposal->arguments['order_id'],
    ),
)
    ->executionTarget(ExecutionTargetPolicy::refresh(
        name: 'order-primary-key',
        identityUsing: fn (ActionEnvelope $envelope, Order $order): array => [
            'order_id' => $order->getKey(),
        ],
        refreshUsing: fn (ActionEnvelope $envelope, Order $order): Order => Order::findOrFail(
            $order->getKey(),
        ),
    ))
    ->executeUsing(function ($action) use (&$issuedRefunds): void {
        $issuedRefunds[] = $action->target->getKey();
    });

$deniedRefund = ActionEnvelope::wrap(
    new ActionProposal('orders.refund', ['order_id' => $otherCustomersOrderId]),
    new ActionContext(actor: $customer),
);

// In an application a definition class implementing DefinesCapability registers this at boot.
// Registering directly keeps the test self-contained and independent of discovery.
app(VerdictManager::class)->capability($capability);

CapabilitySecurityTestKit::for(app(VerdictManager::class), 'orders.refund')
    ->assertPolicyDenial(
        $deniedRefund,
        fn (): bool => $policyWasInvoked,
        fn (): bool => $issuedRefunds === [],
    );
```

There are no permissive defaults. The application registers the capability, then supplies its
target resolver, authorization policy, envelopes, and side-effect assertions for every case.
`assertPolicyDenial()` also requires an application-owned observation that its policy ran. Keep
those assertions application-specific: they are where a refund test proves the policy ran and no
refund was issued, rather than merely observing a denied Verdict result.

The kit provides these independent assertions:

- `assertPolicyDenial()` verifies Verdict stopped at proposal authorization and that the supplied
  policy observation and no-side-effect assertions both hold.
- `assertRefreshedTargetSubstitution()` requires a `refresh()` execution-target policy, verifies
  Verdict reached execution, and leaves the application to assert its executor observed the
  refreshed target fixture.
- `assertApprovalBindingInvalidation()` issues and approves a receipt, applies an application-owned
  binding mutation, confirms the exact receipt remains approved, then runs in Verdict's approval
  execution context and requires the execution to be denied. `not_found` alone is insufficient:
  a lost receipt produces the same outcome without demonstrating binding invalidation.
- `assertExecutionClaimDuplicateAdmission()` verifies the first action reaches execution and its
  duplicate is denied at the execution-claim gate.
- `assertRateLimitEnforcement()` requires one permitted action (and accepts additional permitted
  actions), then verifies the supplied next action is denied at the rate-limit gate.
- `assertExecutorFailureLeavesIndeterminateClaim()` verifies the expected executor failure leaves
  the capability's claim indeterminate for reconciliation.

The invariant names in kit failures identify the capability but deliberately do not render action
arguments or target values. Let the surrounding application test render any safe, local detail it
needs.

## Generated test skeletons

`verdict:make-capability` generates a test skeleton using the registered capability name and the
applicable assertions from this kit. It leaves every target, tenant or ownership fixture, policy
observation, binding mutation, and side-effect assertion for the application to write; it never
generates permissive fixtures or replaces an application's provider registration. See the
[generator example](../README.md#generate-a-fail-closed-capability-skeleton).

## Testing approval flows

A consumer test driving a confirmation-gated capability the obvious way — Laravel's
`RefreshDatabase` trait plus `ApprovalManager::issue()`/`approve()` — fails with:

> Verdict cannot issue an approval receipt while the store connection is already inside
> transaction level 1. Run Verdict outside the outer transaction or configure this store on a
> separately committed database connection.

That is the deliberate `UnsafeOuterTransaction` guard, not a bug. An approval receipt is security
state: it must survive whatever transaction the application is mid-way through, because a receipt
that silently vanishes with a rollback would let the same approval be issued twice.
`RefreshDatabase` wraps every test in exactly the kind of uncommitted outer transaction the guard
refuses to mutate inside.

Two sanctioned ways to test through it:

- **Use `DatabaseMigrations` instead of `RefreshDatabase`** for tests that exercise approval
  round-trips. It migrates without a wrapping transaction, which is cheap on an in-memory SQLite
  database.
- **Point the approval store at a separately committed connection** — set `approvals.connection`
  to a second connection that is not inside the test transaction. This mirrors what the guard's
  message recommends for production and keeps `RefreshDatabase` for everything else.

Two adjacent behaviours worth knowing before writing assertions:

- An approved receipt does not execute anything by itself. A resumed tool call executes only
  inside `ApprovalManager::withinApprovedToolCalls()` with a decision for that specific call —
  asserting "approved, therefore ran" without that wrapper tests a flow Verdict never promises.
- On a database that boots before it migrates (every per-test application under either trait),
  the capability-configuration audit row is skipped at boot and recorded on the first boot after
  a persistent migration. The store is a write-only audit trail, so the skip cannot change any
  authorization outcome.
