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

### Security-state gate ordering

The seven steps above are the orientation-level summary. Gate ordering is fully decided — across [ADR 0001](adr/0001-semantic-execution-rate-limits.md), [ADR 0002](adr/0002-strict-at-most-once-admission.md), [ADR 0003](adr/0003-execution-target-freshness.md), and [ADR 0004](adr/0004-independent-security-state-transactions.md) — and this table is a navigation aid over those decisions, not a restatement that could drift from them. For the exact order, read [ADR 0003 §"Required execution order"](adr/0003-execution-target-freshness.md#required-execution-order); this table exists so a reader does not have to assemble it from four documents.

| # | Step | Owning ADR |
| --- | --- | --- |
| 1 | Resolve the proposal-stage target | [ADR 0003](adr/0003-execution-target-freshness.md) |
| 2 | Inspect the proposal-stage Laravel Policy | [ADR 0003](adr/0003-execution-target-freshness.md) |
| 3 | Require an executable capability and an explicit execution-target policy | [ADR 0003](adr/0003-execution-target-freshness.md) |
| 4 | If confirmation is required, non-mutatingly validate the receipt against the proposal target — short-circuits a repeated proposal while approval is still pending | [ADR 0003](adr/0003-execution-target-freshness.md) |
| 5 | Fingerprint the proposal target's canonical identity | [ADR 0003](adr/0003-execution-target-freshness.md) |
| 6 | Refresh the target, or explicitly reuse the configured snapshot | [ADR 0003](adr/0003-execution-target-freshness.md) |
| 7 | Fingerprint the execution target and deny an identity mismatch | [ADR 0003](adr/0003-execution-target-freshness.md) |
| 8 | Inspect the Laravel Policy against the execution target | [ADR 0003](adr/0003-execution-target-freshness.md) |
| 9 | Non-mutatingly validate confirmation again against the execution target, if required | [ADR 0003](adr/0003-execution-target-freshness.md) |
| 10 | Consume the semantic rate-limit unit against the execution target, if configured | [ADR 0001](adr/0001-semantic-execution-rate-limits.md) (ordering amended by [ADR 0003](adr/0003-execution-target-freshness.md)); transaction independence per [ADR 0004](adr/0004-independent-security-state-transactions.md) |
| 11 | Atomically consume confirmation against the execution target, if required | [ADR 0001](adr/0001-semantic-execution-rate-limits.md) origin, reordered to follow rate limiting by [ADR 0003](adr/0003-execution-target-freshness.md); transaction independence per [ADR 0004](adr/0004-independent-security-state-transactions.md) |
| 12 | Atomically claim the logical operation against the execution target, if configured | [ADR 0002](adr/0002-strict-at-most-once-admission.md); transaction independence per [ADR 0004](adr/0004-independent-security-state-transactions.md) |
| 13 | Pass the execution target to `AuthorizedAction` and enter the executor | — application code, not a Verdict gate |
| 14 | Finalize the execution claim as completed or indeterminate | [ADR 0002](adr/0002-strict-at-most-once-admission.md) |

Two things the table doesn't show on its own: gates are ordered from more recoverable to less recoverable — a rate-limit unit precedes a human approval receipt, and the strict execution claim remains the final gate — and every mutating step from 10–12 additionally requires its store's connection to commit independently of any outer application transaction ([ADR 0004](adr/0004-independent-security-state-transactions.md)), or the operation fails closed with `UnsafeOuterTransaction` before changing state.

A numbered step list is also, by construction, a behavioral argument — correct only as long as nobody reorders the statements. [ADR 0013](adr/0013-authorization-binding-layers.md) states the property this ordering exists to produce as a state-based invariant instead (**Invariant B1**: no execution-stage decision, approval transition, rate-limit consumption, or execution-claim admission may be derived from a target snapshot older than the refresh performed for that envelope) — something a reviewer or a test can check directly, independent of step order. Read the table above for *what happens when*; read ADR 0013 for the property a refactor of this order must still preserve.

## Target strategies

`ExecutionTargetPolicy::refresh()` records canonical identity and re-fetches the target at execution time. Prefer it for mutable records. `acceptStaleSnapshot()` deliberately uses the original resolved object and should be used only when stale-snapshot behavior is acceptable.

Neither strategy establishes transaction isolation. Put database locking, version checks, and idempotency where the business operation requires them. See [ADR 0003](adr/0003-execution-target-freshness.md).

## Wiring audit

Run `php artisan verdict:validate` in CI after application capabilities have been registered. It never resolves targets, authorizes, or executes actions; it reports configuration errors with a non-zero exit code, while warnings (such as an executor-less `GuardedTool` migration capability) do not fail CI. `BoundTool` wiring is not audited here: it already fails immediately at construction for an unknown or non-executable capability.

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

Streaming approval resumption is supported: `VerdictApprovalMiddleware` keeps the scoped approval context alive for the full duration of a streamed response's iteration, not just until the middleware call returns. `VerdictProvenanceMiddleware` does the same for its invocation frame, so decision and context-release evidence emitted by lazy streamed tool execution retains the Laravel AI invocation ID. Synchronous and queued execution remain unchanged. See [ADR 0006](adr/0006-streaming-approval-resumption-deferred.md) for why this was a Verdict-side context-lifetime fix rather than a missing Laravel AI capability.

For evaluations, Verdict provides deterministic harness primitives and an opt-in live runner. The live runner is provider-neutral at the package boundary; the application supplies the closure that invokes its chosen provider.

## Execution-mode compatibility

Laravel AI's `Agent` contract exposes three ways to invoke a prompt: `prompt()` (synchronous), `stream()` (returns a lazy `StreamableAgentResponse`), and `queue()` (dispatches `Laravel\Ai\Jobs\InvokeAgent` to a queue worker). Each cell below is either a verified answer or an explicit "not yet verified" — this table does not fill a cell from what seems likely.

| Feature | Synchronous | Streamed | Queued |
| --- | --- | --- | --- |
| Authorization (proposal/execution stages) | ✅ | Not yet verified¹ | Not yet verified² |
| Confirmation / approval resumption | ✅ | ✅ — [ADR 0006](adr/0006-streaming-approval-resumption-deferred.md), fixed in #22 | Not yet verified² |
| Execution claims (at-most-once) | ✅ | Not yet verified¹ | Not yet verified² |
| Semantic rate limits | ✅ | Not yet verified¹ | Not yet verified² |
| Evidence recording | ✅ | ✅ — prompt provenance and invocation-ID correlation retained | Not yet verified² |
| Context release | ✅ | ✅ — invocation-ID correlation retained | Not yet verified² |

Every "Synchronous" ✅ is exercised directly by the test suite. The other cells:

1. **Authorization, execution claims, and semantic rate limits under streaming are not yet covered by an automated test**, but code tracing gives reasonable confidence they work: ADR 0006 itself establishes that a streamed tool call's execution — not just the approval check — happens later, during iteration, not at the point `$next($prompt)` returns; and none of these three gates read any per-request frame stack the way `ApprovalExecutionContext` did before #22. That reasoning is not the same as a passing test against real streamed Laravel AI execution, so the cells stay marked unverified rather than ✅.
2. **Nothing under `Agent::queue()` is covered by an automated Verdict test.** `Laravel\Ai\Jobs\InvokeAgent::handle()` calls the synchronous `Agent::prompt()` internally, not `stream()`, so the lazy-generator timing this whole table is about should not apply to any queued execution — but that inference has not been confirmed by running a queued job through real Laravel AI execution.
3. **Evidence recording and context release under streaming are verified.** `VerdictProvenanceMiddleware` rewrites a streamed response's generator in place so its `InvocationContext` frame remains active for lazy tool execution, and retains prompt provenance until Laravel AI dispatches `StreamingAgent` during real iteration. The regression tests cover prompt provenance plus `DecisionEvidence` and `ContextReleaseEvidence` invocation-ID correlation, including frame cleanup after iteration. This fixes [#80](https://github.com/fissible/verdict/issues/80) and [#83](https://github.com/fissible/verdict/issues/83).

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
