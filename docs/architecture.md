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
final class RefundCapability implements DefinesCapability
{
    public static function make(): Capability
    {
        return Capability::usingPolicy('orders.refund', 'refund', $resolveOrder)
            ->executionTarget($currentOrder)
            ->requiresConfirmation($refundApprovalBinding)
            ->executeUsing($issueRefund);
    }
}
```

Implementing `DefinesCapability` is what registers it: Verdict discovers such classes under
`verdict.capabilities.discovery.paths` and registers them at boot, through the same path
`Verdict::capability()` uses. A discovered capability and a hand-registered one are the same object
everywhere downstream. See [ADR 0027](adr/0027-a-capability-definition-is-a-declaration.md).

```php
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
| 14 | Finalize the execution claim as completed or indeterminate | [ADR 0002](adr/0002-strict-at-most-once-admission.md); failure reporting per [ADR 0007](adr/0007-evidence-layering.md) Update (#149) |

**What a caller sees when step 14 fails.** The executor has already run by then, so the two failures possible
at that step are reported differently, per [ADR 0007](adr/0007-evidence-layering.md)'s Update (#149). If the
claim transition fails — most often because an operator resolved the claim through
`verdict:resolve-execution-claim` while the executor was still running — Verdict throws
`ExecutionCompletedWithUnfinalizedClaim`, carrying the executor's output and the claim ID so the side effect
is recoverable and the claim can be reconciled with one command. That is distinct from
`ExecutionClaimFinalizationFailed`, which means the executor itself failed. If instead the *evidence* write
for that finalization fails, it does not reach the caller at all: an `EvidenceWriteFailed` event is
dispatched and the successful result is returned, because an exception there would be indistinguishable from
execution failure. Retrying after either outcome fails closed, since the claim is no longer `claimed`.

Two things the table doesn't show on its own: gates are ordered from more recoverable to less recoverable — a rate-limit unit precedes a human approval receipt, and the strict execution claim remains the final gate — and every mutating step from 10–12 additionally requires its store's connection to commit independently of any outer application transaction ([ADR 0004](adr/0004-independent-security-state-transactions.md)), or the operation fails closed with `UnsafeOuterTransaction` before changing state.

A numbered step list is also, by construction, a behavioral argument — correct only as long as nobody reorders the statements. [ADR 0013](adr/0013-authorization-binding-layers.md) states the property this ordering exists to produce as a state-based invariant instead (**Invariant B1**: no execution-stage decision, approval transition, rate-limit consumption, or execution-claim admission may be derived from a target snapshot older than the refresh performed for that envelope) — something a reviewer or a test can check directly, independent of step order. Read the table above for *what happens when*; read ADR 0013 for the property a refactor of this order must still preserve.

## Target strategies

`ExecutionTargetPolicy::refresh()` records canonical identity and re-fetches the target at execution time. Prefer it for mutable records. `acceptStaleSnapshot()` deliberately uses the original resolved object and should be used only when stale-snapshot behavior is acceptable.

Neither strategy establishes transaction isolation. Put database locking, version checks, and idempotency where the business operation requires them. See [ADR 0003](adr/0003-execution-target-freshness.md).

## Wiring audit

Run `php artisan verdict:validate` in CI after application capabilities have been registered. It never resolves targets, authorizes, or executes actions; it reports configuration errors with a non-zero exit code, while warnings (such as an executor-less `GuardedTool` migration capability) do not fail CI. `BoundTool` wiring is not audited here: it already fails immediately at construction for an unknown or non-executable capability.

It also names any capability that declares `requiresConfirmation()` with no execution-target policy. That combination never pauses: `VerdictManager::requestConfirmation()` returns `null` without a target policy, so Verdict never requests approval and the action is denied at execution without a human ever being asked. It still fails closed, which is why this warns rather than errors — see [#230](https://github.com/fissible/verdict/issues/230), where rejecting the combination at registration is posed as a separate design question.

Outside the `local` and `testing` environments it also warns for each non-durable adapter it finds configured — the in-memory evidence recorder and the in-memory approval, rate-limit, execution-claim, and capability-configuration stores — naming the config key that selects each one and the consequence specific to it. A process-local rate limit binds per process, so N workers admit up to N times the configured limit; a process-local claim store degrades at-most-once to at-most-once-per-process. These are **warnings and do not change the exit code**, because Verdict does not decide an application's deployment topology and an ephemeral preview environment may legitimately run one. Pass `--strict` to make CI block on them along with every other advisory finding. The environment test is the framework's own, so an environment named anything other than `local` or `testing` is covered without configuring a list of production names.

**The check compares configuration, not resolved container bindings.** It reads what the deployment declared in `config/verdict.php`. An application that leaves the config durable and rebinds a store contract to a non-durable implementation in a service provider is not seen, in either direction, and neither is a custom store of your own that happens not to be durable — Verdict cannot judge the durability of an implementation it did not write. Treat a clean run as "nothing declared in configuration is non-durable", not as "every store this application resolves is durable".

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

For the public interfaces intended for application adapters and their current
stability commitments, see [extension-contract stability](extension-contract-stability.md).

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

// What the approver should see about where this proposal came from. The payload was assembled when
// the receipt was issued, inside the invocation — this request has no invocation frame, and could
// not have resolved it. `null` means the receipt predates Verdict capturing provenance.
$provenance = $challenge->provenance;

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
| Authorization (proposal/execution stages) | ✅ | ✅¹ — proposal and execution stages | ✅² — proposal permit and execution-stage denial |
| Confirmation / approval resumption | ✅ | ✅⁵ — [ADR 0006](adr/0006-streaming-approval-resumption-deferred.md), fixed in #22 | ✅³ — pause and approved resume across two jobs |
| Execution claims (at-most-once) | ✅ | ✅¹ — duplicate logical actions denied | ✅² — duplicate logical action denied across jobs |
| Semantic rate limits | ✅ | ✅¹ — consumption and enforcement | ✅² — enforced across jobs |
| Evidence recording | ✅ | ✅ — prompt provenance and invocation-ID correlation retained | ✅² — durable decision evidence |
| Context release | ✅ | ✅ — invocation-ID correlation retained | ✅² — durable context-release evidence |

Every "Synchronous" ✅ is exercised directly by the test suite. The notes clarify the verification boundaries:

1. **Authorization, execution claims, and semantic rate limits under streaming are covered by `StreamedExecutionGatesTest`.** The baseline cases construct Laravel AI's real `Agent::stream()` response through `FakeTextGateway`; the two-tool-call cases use a test-only `StepTextGateway` that controls only multi-tool-call provider output while Laravel AI's real provider, response, stream, and tool pipeline run. Every case declares `VerdictProvenanceMiddleware` on the agent and iterates the rewritten response generator lazily. Three substitutions bound the claim: it does not exercise a live provider transport, it binds a stub `CapabilityAuthorizer` instead of resolving a real policy, and the custom gateway supplies the two-call provider response. What these cells assert is therefore gate ordering and lazy-iteration timing under streaming, not policy resolution. The test proves proposal and execution authorization can deny before the executor, a second streamed logical action cannot acquire an execution claim, a streamed execution consumes then enforces its semantic rate limit, and a callable action context resolver runs during iteration rather than when the stream is created. Gate evidence is correlated to the Laravel AI streamed invocation and its frame is released after iteration.
2. **Queued gate coverage uses Laravel AI's actual dispatch and worker path.** `QueuedExecutionGatesTest` configures Laravel's database queue, calls `Agent::queue()`, confirms an `InvokeAgent` payload was written to `jobs`, then runs `queue:work database --once --force` and asserts that the worker removed the job. `--force` prevents a maintenance-mode test application from turning the worker invocation into a successful no-op; it does not configure a Verdict queue default. The test uses Laravel AI's fake provider only to make the provider response deterministic, and a stub `CapabilityAuthorizer` to control permit/deny outcomes; it does not fake the queue, call `prompt()` directly, or resolve an application policy. The worker resolves Verdict's configured database evidence, execution-claim, rate-limit, and capability-configuration stores, and the assertions read their durable rows after handling. These cells verify queued gate ordering and configured-store resolution, not live provider transport or policy resolution.
3. **Queued approval resumption is verified, including completion.** `QueuedApprovalResumptionTest` dispatches a real `InvokeAgent` job onto Laravel's database queue, runs `queue:work database --once --force`, and asserts the worker paused on a confirmation gate leaving one pending receipt and no execution; then approves that receipt in Verdict, dispatches a second job carrying a specific tool-call decision, and asserts the capability executed exactly once with a durable execution-stage permit row behind it. The paused turn crosses the job boundary through the shipped `DatabaseConversationStore`, not through the first job's response — a resume never reads that response. The note that used to sit here said the blocker was that `InvokeAgent` "does not itself retain the initial job's pending tool-call response"; that was wrong, and the gap was only coverage. Three substitutions bound the claim: a test-only `StepTextGateway` supplies the provider's step output (it cannot be `Agent::fake()` — see note 5 — so `generateTextStep` is implemented directly and decides from the incoming messages, since each dispatch is its own generation loop starting at step 0), a stub `CapabilityAuthorizer` stands in for a resolved policy, and worker and dispatcher share one process, so this shows the payload and the durable stores carrying the pause, not two operating-system processes. Two companion cases assert the failure modes are real rather than absent: a resume carrying only `Decision::approveAll()`'s wildcard does not execute, and neither does one whose receipt was never approved in Verdict — each asserting approval-stage evidence exists first, so a resume that never ran cannot pass as a refusal. Closes [#234](https://github.com/fissible/verdict/issues/234).
4. **Evidence recording and context release under streaming are verified.** `VerdictProvenanceMiddleware` rewrites a streamed response's generator in place so its `InvocationContext` frame remains active for lazy tool execution, and registers prompt provenance when Laravel AI dispatches `StreamingAgent` during real iteration. The regression tests cover prompt provenance plus `DecisionEvidence` and `ContextReleaseEvidence` invocation-ID correlation, including frame cleanup after iteration. This fixes [#80](https://github.com/fissible/verdict/issues/80) and [#83](https://github.com/fissible/verdict/issues/83).

5. **Streamed approval resumption is verified, including completion.** `StreamedApprovalResumptionTest` drives a confirmation-gated capability through Laravel AI's real `stream()` pipeline and asserts the run pauses for confirmation, the executor does not run before approval, and an approved resume executes the capability exactly once. The provider substitution is a `StepTextGateway` — not `Agent::fake()`, which cannot express this at all: `ResumesToolApprovals::resumableApprovalFor()` returns `null` when `Ai::hasFakeGatewayFor()` is true, so a faked agent never resumes tools and would report non-execution for a reason unrelated to Verdict. **Completing a resume requires two things an application must do, and getting either wrong produces silent non-execution:** the receipt must be approved in Verdict through the application's own authenticated flow (`ApprovalManager::approve()`), and the resume must carry a *specific* tool-call decision — `Decision::approveAll()` yields a wildcard `'*'` that `ApprovalExecutionContext::push()` deliberately skips, because a blanket approval from the agent loop must not authorize a specific consequential action. A capability declaring `requiresConfirmation()` with no execution-target policy never pauses at all; `verdict:validate` names that combination ([#230](https://github.com/fissible/verdict/issues/230)). Demonstrated against a live provider as well as deterministically — see [#218](https://github.com/fissible/verdict/issues/218).

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

### Evidence extension contracts

Before 1.0, evidence extensions should implement only the capability they own:
`EvidenceWriter` for decision, context-release, provenance, and derivation writes; and
`ProvenanceLedgerStore` for provenance and derivation reads. An application may configure them
independently as `verdict.evidence.writer` and `verdict.evidence.ledger`.

`EvidenceRecorder` remains available as a deprecated pre-1.0 bridge. Existing implementations and
the shipped database, in-memory, null, and Attest adapters continue to implement both narrow
contracts, so unchanged applications retain their current behavior. New adapters must not invent
unsupported reads or writes merely to satisfy the old mixed interface.

Publish Verdict’s configuration, then run migrations before using database-backed approvals, execution claims, rate limits, or evidence:

```bash
php artisan vendor:publish --provider="Fissible\Verdict\VerdictServiceProvider" --tag="verdict-config"
php artisan migrate
```

Every security-state and evidence table name is read from configuration **by the migration stubs as
well as by the stores** — `verdict.approvals.table`, `verdict.evidence.table`,
`verdict.evidence.derivations_table`, `verdict.rate_limits.table`, `verdict.execution_claims.table`,
and `verdict.capability_configurations.table` — so renaming a table is a config change only: set the
name before running `php artisan migrate` and both the schema and the store use it. No migration
file needs editing, and a rename after tables exist is an application-owned data migration, not a
config edit.

Use the generated configuration to review retention and evaluation controls for your environment. See [limitations](limitations.md) for the controls that remain outside the package.
