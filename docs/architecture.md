# Verdict architecture

This guide explains how Verdict fits into a Laravel AI application after you understand the [security model](security-model.md).

## Secure tool execution

The preferred integration is `BoundTool`. It adapts a Laravel AI tool definition to a Verdict capability and lets the capability own the executor.

```text
Laravel AI tool request
        |
        v
BoundTool
        |
        v
trusted target resolver -> Laravel authorization -> optional policies
        |                                           (approval, claim, limit)
        v
execution-target policy
        |
        v
capability executor
```

The tool definition describes the model-facing contract. The capability defines application authority: the Laravel authorization ability, trusted target resolution, optional safeguards, and the code that performs the side effect.

```php
Verdict::capability(
    Capability::usingPolicy('orders.refund', 'refund', $resolveOrder)
        ->executionTarget($currentOrder)
        ->requiresConfirmation($refundApprovalBinding)
        ->executeUsing($issueRefund),
);

return Verdict::bound(
    definition: new RefundOrder,
    capability: 'orders.refund',
    context: fn (Request $request): ActionContext => new ActionContext(auth()->user()),
);
```

The executor receives an `AuthorizedAction`, including the application-selected target. Keep business-side validation in the executor as well; Verdict is not a substitute for domain invariants.

## Capability lifecycle

1. Laravel AI invokes a `BoundTool` with model-proposed arguments.
2. Verdict creates an action envelope with the capability and trusted `ActionContext`.
3. The capability resolves a target from application data.
4. Laravel authorization evaluates the actor, ability, and target.
5. If configured, Verdict evaluates confirmation, an execution claim, and semantic limits.
6. The execution-target policy returns the target passed to the executor.
7. The capability executor performs the application side effect or returns a result.

Each step is application-configurable, but the model does not receive a direct path to the executor once the underlying tool has been replaced with `BoundTool`.

## Target strategies

`ExecutionTargetPolicy::refresh()` records canonical identity and re-fetches the target at execution time. Prefer it for mutable records. `acceptStaleSnapshot()` deliberately uses the original resolved object and should be used only when stale-snapshot behavior is acceptable.

Neither strategy establishes transaction isolation. Put database locking, version checks, and idempotency where the business operation requires them. See [ADR 0003](adr/0003-execution-target-freshness.md).

## Extension points

Capabilities compose their safeguards fluently:

```php
Capability::usingPolicy($name, $ability, $resolveTarget)
    ->executionTarget($targetPolicy)
    ->requiresConfirmation($binding, $reason, $ttlSeconds)
    ->atMostOnce($claimPolicy)
    ->rateLimit($rateLimitPolicy)
    ->executeUsing($executor);
```

You provide the target resolver, approval binding, policy objects, executor, and the actor-bearing `ActionContext`. Those are deliberate application boundaries; Verdict cannot infer your tenancy rules, business identity, or material approval facts.

## GuardedTool migration bridge

`GuardedTool` and `Verdict::guard(...)` exist as a bounded migration bridge, to move an application’s existing pre-Verdict Laravel AI tools onto Verdict’s authorization boundary without rewriting them. They authorize a resolved target and then delegate to an independent handler, so Verdict cannot establish that the handler acts on the same target.

Do not use them for new security-sensitive capabilities. `GuardedTool` also does not support verified confirmation, because its independent handler cannot be bound to the authorized target. Use `BoundTool` for new capabilities and migrate security-sensitive actions to it. The rationale and boundary are documented in [ADR 0005](adr/0005-guardedtool-is-a-bounded-migration-bridge.md).

Every `DecisionEvidence` row carries a `tool_kind` field (`guarded` or `bound`) identifying which primitive produced it, so an application can query its own migration debt without grepping source — see ADR 0005's evidence section.

## Laravel AI lifecycle integration

Verdict also integrates with Laravel AI lifecycle events to record prompt and tool-result provenance using fingerprints rather than raw content. This supports audit evidence; it does not inspect provider internals or make raw provider output safe to store.

### Resolving an approval

When Laravel AI returns a `PendingApproval`, resolve its Verdict challenge inside an endpoint that has already authorized access to the conversation and pending call:

```php
use Laravel\Ai\Approvals\Decision as LaravelApprovalDecision;
use Laravel\Ai\Approvals\Decisions;

$challenge = Verdict::approvals()->challengeForToolCall($pendingApproval->id);

abort_if($challenge === null, 409);

$transition = Verdict::approvals()->approve(
    receiptId: $challenge->receiptId,
    toolCallId: $challenge->toolCallId,
    approvedBy: 'customer:'.auth()->id(),
);

abort_unless($transition->succeeded(), 409);

$response = $agent->prompt(Decisions::from([
    $pendingApproval->id => LaravelApprovalDecision::approve(),
]));
```

Approval scope is deliberately per-request: the endpoint authenticates the decision maker, approves one receipt bound to one tool call, and resumes the agent. Use an opaque application identifier such as `customer:72` for `approvedBy`, not an email address or other unnecessary PII — Verdict does not authenticate this string.

This is an early synchronous integration. Streaming approval resumption is not yet supported, because agent middleware returns before a stream is consumed; protected execution fails closed without the scoped approval context. See [ADR 0006](adr/0006-streaming-approval-resumption-deferred.md).

For evaluations, Verdict provides deterministic harness primitives and an opt-in live runner. The live runner is provider-neutral at the package boundary; the application supplies the closure that invokes its chosen provider.

## Relationship to Laravel AI

Verdict does not duplicate what Laravel AI already owns. It adds an authorization boundary in front of it and leaves transport, generation, and conversation mechanics where they are.

| Laravel AI owns | Verdict adds | The application still owns |
| --- | --- | --- |
| Agents and prompts | Capability envelopes | Authentication and tenancy |
| Provider and model selection | Context-release decisions | Laravel Policies and business rules |
| Tool schemas and invocation | Mandatory authorization phase | Domain services and commands |
| Structured output | Provenance and sensitivity labels | Credentials and provider agreements |
| Conversations and streaming | Security evidence and signals | Retention and compliance decisions |
| Human approval mechanics | Argument-bound approval policy | Operator and customer experience |
| Generation limits and events | Security suites and regressions | Incident-response program |

Verdict builds on Laravel AI’s public extension points rather than replacing them. Where a concern is already Laravel AI’s — streamed response transport, token and cost telemetry, generation limits — Verdict does not grow a second, disagreeing source of truth. See [ADR 0011](adr/0011-rejected-verdict-does-not-buffer-streamed-output.md) and [ADR 0012](adr/0012-rejected-verdict-does-not-own-token-telemetry.md).

## Configuration and migrations

Publish Verdict’s configuration, then run migrations before using database-backed approvals, execution claims, rate limits, or evidence:

```bash
php artisan vendor:publish --provider="Fissible\Verdict\VerdictServiceProvider" --tag="verdict-config"
php artisan migrate
```

Use the generated configuration to review retention and evaluation controls for your environment. See [limitations](limitations.md) for the controls that remain outside the package.
