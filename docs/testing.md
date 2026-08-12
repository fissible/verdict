# Capability security test kit

`CapabilitySecurityTestKit` drives a capability through `VerdictManager::runBound()` without
depending on Pest, PHPUnit, or a test-double version of Verdict. It raises
`CapabilitySecurityAssertionFailed`, so use it from any PHP test runner.

Create a fresh kit in each test for the capability your application has already registered. This
matches the normal provider-based registration used by an application and prevents a test helper
from silently replacing production wiring.

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

// In an application this normally happens in a service provider.
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
  binding mutation, then runs in Verdict's approval execution context and requires the execution to
  be denied.
- `assertExecutionClaimDuplicateAdmission()` verifies the first action reaches execution and its
  duplicate is denied at the execution-claim gate.
- `assertRateLimitEnforcement()` requires one permitted action (and accepts additional permitted
  actions), then verifies the supplied next action is denied at the rate-limit gate.
- `assertExecutorFailureLeavesIndeterminateClaim()` verifies the expected executor failure leaves
  the capability's claim indeterminate for reconciliation.

The invariant names in kit failures identify the capability but deliberately do not render action
arguments or target values. Let the surrounding application test render any safe, local detail it
needs.

## Generator-facing shape

The registered capability name passed to `CapabilitySecurityTestKit::for()` and the six explicit
assertion methods are the stable public seam for the future `verdict:make-capability` skeleton.
That generator should leave every target, tenant or ownership fixture, policy observation, binding
mutation, and side-effect assertion for the application to write; it must not generate permissive
fixtures or replace an application's provider registration.
