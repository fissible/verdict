# Verdict

**Verdict is a Laravel security boundary for AI-triggered application actions.**

Verdict makes an AI ask your application for permission before it performs an important action. Language models are good at proposing what to do next; they should not be the final authority on what they are allowed to do.

> Models propose. Applications authorize.

It sits between an AI tool call and your application code. Before a protected action runs, Verdict applies the policies you configure. Your application—not the model—decides whether the actor is allowed, which resource is safe to use, whether a person must approve the operation, and which safety limits apply.

## Why Verdict exists

AI frameworks let models call functions. That is useful, but it means a model can influence real business operations.

Consider a tool that refunds an order:

```php
refundOrder($orderId);
```

Without an application-controlled boundary, a model can influence which order is selected, whether a refund is appropriate, when to make it, and whether to try again. Prompt injection, mistaken instructions, and ordinary model errors can all reach the same function.

Verdict puts an authorization pipeline before that application code executes. The model can recommend an action; your Laravel policies and configured safeguards remain the authority.

## Quick example

Register a capability with the Laravel authorization ability and the trusted resource resolver. Then expose it to Laravel AI through a secure `BoundTool`.

```php
use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Facades\Verdict;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Laravel\Ai\Tools\Request;

Verdict::capability(
    Capability::usingPolicy(
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
        ->executeUsing(function (AuthorizedAction $action): string {
            app(RefundService::class)->issue($action->target);

            return 'Refund issued.';
        }),
);

$tool = Verdict::bound(
    definition: new RefundOrder,
    capability: 'orders.refund',
    context: fn (Request $request): ActionContext => new ActionContext(auth()->user()),
);
```

The `refund` Laravel policy decides whether the authenticated actor can refund the resolved order. The executor receives the application-selected execution target—not an object supplied by the model.

## Installation

```bash
composer require fissible/verdict:^0.5
php artisan vendor:publish --provider="Fissible\Verdict\VerdictServiceProvider" --tag="verdict-config"
php artisan migrate
```

Verdict requires PHP 8.3+, Laravel 12 or 13, and Laravel AI `^0.10.2`.

See the [architecture guide](docs/architecture.md) for wiring tools into an agent and the [security model](docs/security-model.md) before protecting production-changing operations.

## Basic usage

Each protected operation is a named capability. A capability begins with two application-owned decisions:

1. Resolve the requested resource from trusted application data.
2. Authorize the actor with a Laravel policy or gate ability.

For a `BoundTool`, also select an `ExecutionTargetPolicy`. `refresh()` re-loads the resource before execution, which is usually the safer choice for mutable records. The policy can then add safeguards appropriate to this particular action:

```php
$capability = Capability::usingPolicy(
    name: 'orders.refund',
    ability: 'refund',
    resolveTarget: $resolveOrder,
)
    ->executionTarget($currentOrder)
    ->requiresConfirmation($approvalBinding, reason: 'Refund an order')
    ->atMostOnce($refundClaim)
    ->rateLimit($refundLimit)
    ->executeUsing($issueRefund);
```

Register it with `Verdict::capability($capability)`, then use `Verdict::bound(...)` instead of exposing the underlying Laravel AI tool directly. The [architecture guide](docs/architecture.md) explains the lifecycle and extension points.

## Core security checklist

These are independent, configurable policies—not one opaque allow/deny decision. Pick the safeguards that fit each capability and its risk.

| Question | Verdict feature |
| --- | --- |
| Is this actor allowed to perform this capability? | Laravel authorization through `Capability::usingPolicy()` |
| Which resource may actually be changed? | `ExecutionTargetPolicy` and a trusted target resolver |
| Does a person need to approve it? | `requiresConfirmation()` with an application-defined binding |
| Has this exact action already been admitted? | `atMostOnce()` and an execution-claim policy |
| Has the actor exceeded a meaningful safety limit? | `rateLimit()` and a semantic rate-limit policy |
| What information may enter the model context? | Context-release and evidence policies |

Authorization and target binding establish the protected capability. Confirmation, duplicate-action prevention, limits, and evidence are selected per operation; a read-only lookup does not need the same controls as a refund.

## Features

### Secure tool execution

`BoundTool` connects a Laravel AI tool to a capability whose executor runs only after Verdict’s checks pass. It is the preferred integration for new work.

### Making sure the AI acts on the right resource

An `ExecutionTargetPolicy` captures a stable identity for a trusted target and can refresh that target immediately before the executor runs. This reduces stale-object mistakes; it does not replace database transactions or locking.

### Human approval for consequential actions

Capabilities can require approval bound to application-defined, canonical facts about the action. A later action with different relevant facts does not reuse that approval.

### Preventing duplicate actions

For operations that must not be admitted twice, an execution-claim policy provides strict at-most-once admission for the configured claim fingerprint.

<details>
<summary>How duplicate-action prevention works internally</summary>

Verdict derives a fingerprint from the claim policy’s canonical inputs and uses an atomic state transition in its independent security state. The exact semantics, retention trade-offs, and failure behavior are documented in [ADR 0002](docs/adr/0002-strict-at-most-once-admission.md) and [ADR 0009](docs/adr/0009-execution-claim-retention.md).

The [security-state concurrency benchmarks](docs/benchmarks.md) record measured SQLite and MySQL characteristics.

</details>

### Limiting what AI can do

Semantic rate limits count application-defined action semantics—such as refunds per actor or high-value changes—not model tokens. This lets limits express the operation you actually need to control.

Measured SQLite and MySQL contention characteristics are in the [security-state concurrency benchmarks](docs/benchmarks.md).

### Controlling what information the AI sees

Context-release policies and layered evidence help you decide what may be disclosed to a model and what audit evidence is retained. The package follows a fingerprint-first approach for its security evidence rather than recording raw prompts or tool arguments by default.

`AttestEvidenceRecorder` (opt-in, requires `fissible/attest-laravel`) upgrades decisions and context releases from an ordinary mutable audit store to a signed, hash-chained one — see [limitations](docs/limitations.md#tamper-evident-evidence-is-opt-in-partial-and-bounded-by-key-custody) for exactly what it does and does not cover.

### Testing safeguards before production

Verdict includes deterministic evaluation primitives and an opt-in repeated-trial live evaluation runner, so applications can test security and utility thresholds without making a specific model provider part of the package contract.

For capability integration tests, the framework-agnostic [capability security test kit](docs/testing.md)
drives a hand-written capability through Verdict's protected execution path and checks the common
authorization, freshness, approval, claim, rate-limit, and failure invariants.

For common structural composition, see [capability starter patterns](docs/capability-starter-patterns.md).
They require your application-owned lookup, identity, and operation-binding callbacks; they never
register capabilities or supply policy, tenancy, or side-effect behavior.

### Generate a fail-closed capability skeleton

`verdict:make-capability` creates a capability module and test skeleton, but never edits a policy,
route, or service provider. Every target lookup, execution target, executor, and selected-control
binding is an explicit throwing TODO until the application supplies its own security facts.

```bash
php artisan verdict:make-capability orders.refund \
  --model=Order \
  --ability=refund \
  --target-argument=order_id \
  --confirmation \
  --claim \
  --rate-limit
```

The interactive prompts have these exact flag equivalents. Review the printed policy fragment and
registration snippet, replace all TODOs, register the capability yourself, then run
`php artisan verdict:validate`.

The generated test is intentionally incomplete until your application supplies the fixtures and
observations. It already calls the [capability security test kit](docs/testing.md) through the
same registered capability and protected path Verdict uses at runtime:

```php
namespace Tests\Feature\Capabilities\Orders;

use Fissible\Verdict\Testing\CapabilitySecurityTestKit;
use Fissible\Verdict\VerdictManager;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RefundCapabilityTest extends TestCase
{
    #[Test]
    public function denies_capability_without_executing(): void
    {
        $this->markTestIncomplete('TODO: register RefundCapability::make(), provide a denied envelope, and assert policy observation plus no side effects.');

        CapabilitySecurityTestKit::for(app(VerdictManager::class), 'orders.refund')
            ->assertPolicyDenial($deniedEnvelope, $assertPolicyWasApplied, $assertNoSideEffects);
    }

    #[Test]
    public function uses_a_refreshed_capability_target(): void
    {
        $this->markTestIncomplete('TODO: provide proposal and refreshed target fixtures plus an executor side-effect assertion.');

        CapabilitySecurityTestKit::for(app(VerdictManager::class), 'orders.refund')
            ->assertRefreshedTargetSubstitution($permittedEnvelope, $assertRefreshedSideEffects);
    }
}
```

When selected, `--confirmation`, `--claim`, and `--rate-limit` add the corresponding approval,
duplicate-admission, and rate-limit kit assertions to that skeleton.

## Guarantees

For actions that are registered as capabilities and executed through Verdict’s protected path, Verdict provides these package-level guarantees:

- The configured Laravel authorization decision is made before the capability executor runs.
- A `BoundTool` uses the capability’s trusted target resolver and execution-target policy; it does not execute the model’s arbitrary object reference.
- A configured approval is bound to canonical, application-defined facts and is consumed before execution.
- A configured execution claim is atomically admitted at most once for its fingerprint.
- Configured semantic limits are evaluated before execution.
- Evidence is designed around fingerprints and structured security facts rather than raw prompts or credentials.

Those guarantees are scoped to the protected path and the policies you configure. Read the [security model](docs/security-model.md) and [limitations](docs/limitations.md) before treating any of them as a complete application security program.

## Limitations

Verdict is a security boundary, not a replacement for the rest of your application’s controls. In particular, it does not:

- eliminate time-of-check/time-of-use races in mutable application data;
- replace Laravel Policies, transactions, locking, idempotency, or downstream service controls;
- protect tools or side effects that bypass Verdict;
- inspect provider internals or infer whether arbitrary content contains PII; or
- guarantee the outcome of a downstream side effect after an executor starts.

The complete, deliberately specific list is in [limitations](docs/limitations.md).

## Deeper documentation

- [Security model and threat model](docs/security-model.md)
- [Pilot readiness and production-adoption guide](docs/adoption-guide.md)
- [Evaluation harness and attack packs](docs/evaluation.md)
- [Architecture and Laravel AI integration](docs/architecture.md)
- [Laravel AI dependency surface and compatibility](docs/laravel-ai-compatibility.md)
- [Limitations and application responsibilities](docs/limitations.md)
- [Glossary](docs/glossary.md)
- [Architecture decision records](docs/adr/)
- [Release policy](RELEASES.md)

## Status

Verdict is a pre-1.0 developer preview. Its public surface is evolving; pin a compatible version and review release notes before upgrading a production integration.
