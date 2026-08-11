# ADR 0003: Explicit execution-target freshness

Status: Accepted

## Related issues

- [#19](https://github.com/fissible/verdict/issues/19) (implemented) documents the accepted security-state ordering and execution-mode compatibility.

## Context

`BoundTool` currently resolves a target once during proposal evaluation and reuses that same
in-memory value for execution-stage authorization and the target-bound executor. A second
`Gate::inspect()` call can observe mutation of that exact PHP object, but it does not observe a
database row or external resource changed by another request or process.

Calling the original resolver a second time is not sufficient as a generic design:

- A resolver may be nondeterministic, perform I/O, or select a resource from untrusted proposal
  arguments.
- Verdict targets are deliberately `mixed`; object equality cannot identify arbitrary models,
  DTOs, arrays, or composites.
- A fresh read narrows the stale-authorization window but does not close the race between the read,
  authorization, and execution.
- A database lock is useful only while the protected write remains in the same transaction. Holding
  that lock across payment, email, carrier, or other network calls creates different correctness
  and availability hazards.

The package therefore needs an explicit target-at-execution contract before it adds transactions
or locking.

## Goals

- Make reuse of a proposal-stage snapshot an explicit application choice rather than an invisible
  default for `BoundTool`.
- Let an application obtain current target state immediately before execution without assuming
  Eloquent or a database.
- Prove that proposal and execution targets represent the same logical resource using an
  application-defined canonical identity.
- Authorize and execute against the refreshed target.
- Preserve approval, rate-limit, and execution-claim semantics against that same refreshed target.
- Record useful evidence without storing raw target identities.

## Non-goals

This ADR does not provide serializable execution, a generic transaction wrapper, row locking,
exactly-once side effects, an outbox, or automatic retries. Those require a separate execution
strategy design.

## API decision

A target-bound capability must select one `ExecutionTargetPolicy` before it can execute through
`BoundTool`.

The preferred strategy refreshes from trusted identity carried by the proposal-stage target:

```php
use Fissible\Verdict\Targets\ExecutionTargetPolicy;

Capability::usingPolicy(
    name: 'orders.cancel',
    ability: 'cancel',
    resolveTarget: fn (ActionEnvelope $envelope): Order => Order::query()
        ->where('tenant_id', $envelope->context->metadata['tenant_id'])
        ->find($envelope->proposal->arguments['order_id'])
        ?? throw TargetNotResolvable::make(),
)->executionTarget(ExecutionTargetPolicy::refresh(
    name: 'order-primary-key',
    identityUsing: fn (ActionEnvelope $envelope, Order $order): array => [
        'tenant_id' => $order->tenant_id,
        'resource_type' => 'order',
        'resource_id' => $order->getKey(),
    ],
    refreshUsing: fn (ActionEnvelope $envelope, Order $proposalTarget): Order => Order::query()
        ->where('tenant_id', $proposalTarget->tenant_id)
        ->find($proposalTarget->getKey())
        ?? throw TargetNotResolvable::make(),
))->executeUsing(/* ... */);
```

The refresh callback receives both the envelope and the already-resolved proposal target, but it
should derive resource identity from trusted context and that target rather than reparsing the
model's arguments. This keeps model-controlled selectors out of the second resolution boundary.

Some targets are immutable values, request-local objects, or snapshots for which there is no
meaningful fresh read. Reuse remains possible, but the lack of an external freshness check must be
acknowledged at the call site:

```php
->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
    name: 'immutable-tax-table-version',
    identityUsing: fn (ActionEnvelope $envelope, TaxTable $table): array => [
        'table_version' => $table->version,
    ],
))
```

`acceptStaleSnapshot` retains today's behavior and evidence must say so. The deliberately cautionary
name makes the missing freshness guarantee visible in capability definitions and autocomplete; it
does not assert that the application value is actually stale. The explicit policy requirement
applies to `BoundTool`; `GuardedTool` cannot establish that its independent handler uses either
resolved target and therefore does not participate in this feature.

### Canonical identity

Both strategies require `identityUsing`. It returns an associative array containing only arrays,
scalar values, and `null`, matching Verdict's other canonical binding APIs. Verdict fingerprints
the capability, target-policy name, and identity before refresh, then fingerprints the execution
target using the same function. For `acceptStaleSnapshot`, both fingerprints describe the explicitly
reused value; their equality does not imply that external state is fresh.

Identity describes the stable logical resource, not all mutable state. For example, an order
identity normally contains tenant, type, and primary key, but not `updated_at`, status, balance, or
an authorization version. Mutable state belongs in the Laravel Policy, approval binding, semantic
rate-limit binding, or execution-claim binding as appropriate.

Policy names should be stable, versioned identifiers when their semantics change. They namespace
identity evidence and should also become part of a confirmation receipt fingerprint so a pending
approval cannot silently cross a target-resolution policy change.

Verdict must calculate the proposal identity fingerprint **before** calling `refreshUsing`. A
refresh callback that mutates and returns the same object must not be able to rewrite the value
against which identity is compared.

## Required execution order

For `BoundTool`, the required flow is:

```text
1. Resolve the proposal-stage target.
2. Inspect the proposal-stage Laravel Policy.
3. Require an executable capability and an explicit execution-target policy.
4. If confirmation is required, non-mutatingly validate the receipt against the proposal target.
   Stop here when no matching approved receipt exists.
5. Fingerprint the proposal target's canonical identity.
6. Refresh the target, or explicitly reuse the configured snapshot.
7. Fingerprint the execution target and deny an identity mismatch.
8. Inspect the Laravel Policy against the execution target.
9. Non-mutatingly validate confirmation again against the execution target, if required.
10. Consume the semantic rate-limit unit against the execution target, if configured.
11. Atomically consume confirmation against the execution target, if required.
12. Atomically claim the logical operation against the execution target, if configured.
13. Pass the execution target to `AuthorizedAction` and enter the executor.
14. Finalize the execution claim as completed or indeterminate.
```

Nothing after step 8 may use the proposal-stage target. Rate-limit and execution-claim bindings
must be derived from the execution evaluation so that mutable versions or canonical state included
by those policies are current as of the refresh.

## Gate ordering and confirmation

Verdict should order gates by two related principles:

1. Non-mutating rejection checks run before scarce state is consumed.
2. Stateful gates run from more recoverable to less recoverable: a fixed-window rate-limit unit
   precedes a human approval receipt, and the strict execution claim remains the final gate.

This decision therefore requires a non-consuming approval validation operation in addition to the
existing atomic `consume()`. Validation checks Laravel's explicit approval context, the exact tool
call and binding fingerprint, Approved status, and expiry without changing receipt state. The
database implementation does not need to lock for this preflight read because atomic consumption
remains the authority at time of use.

Slice 1 should add these operations without changing the existing consumption contract:

```php
ApprovalManager::validate(Evaluation $evaluation): ApprovalTransition;

ApprovalReceiptStore::validate(
    string $toolCallId,
    string $bindingFingerprint,
    DateTimeImmutable $at,
): ApprovalTransition;
```

A successful validation returns the existing Approved receipt and `ApprovalOutcome::Approved`; it
does not introduce a new receipt state. Expected missing, mismatched, expired, and invalid-state
outcomes retain their current meanings. `consume()` remains the only method that locks and performs
the Approved-to-Consumed transition, so callers must never treat successful validation alone as
authority to execute.

The first validation occurs against the proposal evaluation. It preserves today's useful
short-circuit: a repeated model proposal while approval is still pending does not perform a second
target refresh and execution-stage `Gate::inspect()`. After refresh and execution authorization,
Verdict validates again against the execution evaluation so mutable approval bindings are current.

The approval receipt must not be consumed before target refresh and execution-stage authorization.
It also must not be consumed before a configured temporary rate-limit gate. Final consumption
calculates its binding fingerprint using the execution evaluation and its refreshed target. This
produces three important outcomes:

- If current policy denies the action, the approved receipt remains unused.
- If an approval binding includes mutable state such as `order_version`, a change between proposal
  approval and execution produces a receipt mismatch and requires a new confirmation rather than
  consuming or applying the stale approval.
- If a rate-limit bucket is full, the approved receipt remains available after the window resets.

There is an unavoidable race between non-consuming validation and atomic receipt consumption. A
concurrent request may consume or expire the receipt after validation. In that case the rate-limit
unit has already been consumed, final receipt consumption fails, and execution remains blocked.
Wasting a time-recoverable rate unit is preferable to consuming a human approval on a throttled
request. Ordinary invalid, mismatched, pending, expired, or replayed receipts fail preflight and do
not consume a rate-limit unit.

This ordering amends ADR 0001's original requirement that approval consumption precede
rate-limit consumption. Evidence order for a successful confirmed capability becomes proposal,
approval preflight, target refresh, execution authorization, execution approval validation, rate
limit, approval consumption, execution-claim admission, and execution-claim finalization.

## Failure semantics

| Condition | Result | Receipt consumed by this attempt? | Rate unit consumed? | Claim created? |
|---|---|---:|---:|---:|
| No execution-target policy on a `BoundTool` capability | Recorded denial | No | No | No |
| `TargetNotResolvable` during refresh | Recorded target-refresh denial | No | No | No |
| Unexpected refresh exception | Application fault; execution stops | No | No | No |
| Invalid canonical identity | Application fault; execution stops | No | No | No |
| Proposal/execution identity mismatch | Recorded target-refresh denial | No | No | No |
| Execution-stage Policy denial | Recorded execution denial | No | No | No |
| Approval preflight mismatch, pending state, expiry, or replay | Confirmation remains required | No | No | No |
| Refreshed approval-binding mismatch | New confirmation required | No | No | No |
| Rate-limit throttle | Recorded throttle | No | No new unit; bucket is full | No |
| Receipt consumed concurrently after validation | Confirmation remains required | No | Yes, if configured | No |
| Duplicate execution claim | Recorded claim denial | Yes, if configured | Yes, if configured | No new claim |

Expected target disappearance is an ordinary fail-closed outcome only when the application signals
it with `TargetNotResolvable`. Other exceptions remain operational faults and are not relabeled as
policy decisions.

The execution claim remains after both rate-limit and approval consumption, preserving ADR 0002.
A successfully consumed confirmation can still be followed by a duplicate-claim denial, but not by
a rate-limit throttle.

## Evidence

Add a `target_refresh` evaluation stage. Its evidence should include:

- target-policy name;
- strategy (`refresh` or `accept_stale_snapshot`);
- proposal target identity fingerprint;
- execution target identity fingerprint;
- whether the fingerprints matched;
- disposition and an internal reason.

Raw identity values and serialized targets must not be stored. For a successful refresh, the two
fingerprints will be equal. For a mismatch, both opaque fingerprints support investigation without
storing the resource identifier directly. An unkeyed fingerprint is pseudonymous, not anonymous:
low-entropy identifiers may be enumerable when an observer knows the input format. Evidence access,
retention, and any future keyed-fingerprint adapter remain part of the application's security
posture.

The execution-stage Policy evidence then describes authorization of the execution target. Approval,
rate-limit, and execution-claim evidence retain their separate stages.

### Approval evidence phases

Approval validation and consumption continue to use `EvaluationStage::Approval`; they do not add
three stage enum cases. Add an `ApprovalEvidencePhase` string-backed enum with
`ProposalValidation`, `ExecutionValidation`, and `Consumption` cases. Add two nullable fields to
`DecisionEvidence` and the database adapter:

- `approval_phase`: the applicable `ApprovalEvidencePhase` value;
- `approval_outcome`: the applicable `ApprovalOutcome` value.

The decision metadata keys use those same snake-case names. The database migration adds nullable
`approval_phase` and `approval_outcome` strings with a length of 32 so existing and non-approval
records remain valid.

Every attempted approval validation or consumption records an approval-stage evaluation, including
failures. Successful validation records a permit with outcome `approved`; failed validation records
the corresponding confirmation-required decision and outcome. Consumption records outcome
`consumed` only after the atomic Approved-to-Consumed transition succeeds; a failed consumption
records its actual failure outcome and a confirmation-required decision. The existing opaque receipt
fingerprint is included whenever a receipt is known. Raw receipt IDs and bindings remain excluded.

For a successful confirmed capability with rate limiting and execution claims enabled, the complete
trail contains up to nine decision records: proposal, proposal approval validation, target refresh,
execution authorization, execution approval validation, rate limit, approval consumption, claim
admission, and claim completion. An execution failure replaces claim completion with an
indeterminate transition. The comparable current flow records up to six rows, so Slice 1 increases
worst-case decision-record volume by three rows per operation.

The default recorder remains a no-op. Applications opting into `DatabaseEvidenceRecorder` must
account for the additional volume in their retention and deletion policy. Verdict still provides no
evidence-pruning command; this slice must not imply otherwise.

## Transaction and locking boundary

Fresh resolution narrows stale-state exposure but leaves a race after execution-stage authorization.
The next design must treat local database writes and external side effects differently.

### Local transactional execution

For a database-only capability, an opt-in strategy may eventually run fresh resolution with a row
lock, Policy inspection, and the local executor write inside one application-selected transaction.
The lock has meaning only if the protected write occurs before that transaction commits.

### External side effects

Verdict should not hold an application row lock open across arbitrary network I/O. A capability
that calls a payment provider, carrier, email service, or webhook should generally commit a durable
intent or outbox record locally, then perform the external call after commit with downstream
idempotency and reconciliation.

### Security-state transactions

Rate-limit counters and execution claims currently use their own store transactions, but those
transactions are not independently durable when Verdict itself is invoked inside an already-active
transaction on the same Laravel connection. In that live configuration, Laravel nests the store
transaction. A later outer rollback can erase a claim that already returned `Claimed` or
`Completed`, allowing the logical operation to be admitted again and violating ADR 0002.

An executor that starts and completes its own `DB::transaction()` **inside** `executeUsing` is not
the problematic pattern: the claim transaction has already committed before Verdict enters that
executor. The hazard exists when application code, middleware, a test wrapper, or another package
opens the transaction around the entire `BoundTool::handle()` or `VerdictManager::runBound()` call
using the execution-claim store's connection.

This is a current ADR 0002 constraint, not merely a Slice 2 concern. Documentation should recommend
a distinct `execution_claims.connection` whenever an application may wrap the whole Verdict
invocation in a transaction. [ADR 0004](0004-independent-security-state-transactions.md) implements
the hardening boundary: mutating durable security-state stores fail closed when their connection is
already inside an outer transaction.

The same outer-transaction caveat applies to rate-limit and approval state: rollback can erase a
consumed limit unit or restore a consumed approval receipt. Applications that wrap the whole
Verdict invocation should place all durable Verdict stores on a separately committed security-state
connection, not only execution claims. The ADR 0004 guard applies to every mutating database
security-state store operation. Any transactional-execution design must define
connection boundaries and failure semantics explicitly. It may require a separately committed
security-state connection, a staged intent protocol, or a different orchestration model. Nested
transactions or savepoints must not be assumed to provide independent durability.

## Alternatives rejected

### Always call the original resolver twice

This silently assumes the resolver is repeatable and that two arbitrary return values represent the
same resource. It also encourages reparsing model-controlled identifiers at execution time.

### Call `fresh()` or `refresh()` on Eloquent models automatically

Verdict targets are not guaranteed to be Eloquent models. Eloquent-specific behavior also cannot
identify composites, choose a connection, define missing-row behavior, or express external stores.

### Compare targets with `===` or `==`

Object identity only detects reuse of the same PHP instance, while loose equality has type-specific
and recursive behavior unsuitable for a security boundary.

### Automatically wrap every executor in a database transaction

Verdict cannot choose the connection, lock scope, isolation level, or correct placement of external
side effects. A generic transaction would overstate the protection and can create lock contention
or rollback inconsistencies.

### Keep silent snapshot reuse as the default

That preserves compatibility but leaves the package's most important bound execution path with an
implicit stale-state assumption. Verdict has no tagged stable release, so the safer default should
be established before one exists.

## Implementation slices

### Slice 1: explicit target-at-execution policy

- Add `ExecutionTargetPolicy` with `refresh(...)` and `acceptStaleSnapshot(...)` strategies.
- Require a policy for executable `BoundTool` capabilities.
- Add canonical identity validation and fingerprinting for refresh policies.
- Add the `target_refresh` evidence stage and fields.
- Refresh, compare identity, authorize, and execute using one execution target.
- Add non-consuming approval validation, including an early proposal-stage preflight and a second
  check bound to the execution target.
- Place rate-limit consumption after successful approval validation but before atomic approval
  consumption, then keep execution-claim admission last.
- Namespace confirmation fingerprints with the execution-target policy name so deliberately
  versioned policy changes invalidate pending receipts.
- Update built-in examples and workbench capabilities to select their strategy explicitly.
- Test fresh external state, identity mismatch, missing target, unexpected faults, approval-version
  mismatch, gate denial, missing-policy denial before approval preflight, pending-approval
  short-circuit, rate-limit preservation of an approved receipt, validation/consumption races, claim
  ordering, and stale-snapshot acknowledgement.
- Test a successful refresh whose stable identity is unchanged but whose mutable version is new,
  asserting that approval, rate-limit, execution-claim, and executor inputs all use the execution
  target rather than the proposal target.
- Test a refresh callback that mutates and returns the proposal target instance, proving that the
  proposal identity fingerprint was captured before the callback ran and an identity change cannot
  compare equal to itself.

### Slice 2: transactional execution strategies

- Design separate local-transactional and durable-intent/external-side-effect modes.
- Define connection ownership, lock acquisition, claim durability, rollback, and post-commit
  behavior.
- Do not begin implementation until those semantics survive a separate adversarial review.

## Implementation review focus

The API-level choices are accepted. Slice 1 implementation must still verify:

1. That two-phase, non-consuming approval validation behaves correctly through Laravel AI approval
   resumption as well as deterministic direct invocation.
2. That no existing approval, rate-limit, or execution-claim path receives the proposal target after
   the required reorder.
3. That database evidence schema changes and recorder tests cover every approval phase without
   persisting raw receipt identifiers or bindings.
4. Whether the future transaction boundary can preserve independently durable execution claims on
   common Laravel database configurations; this remains deferred to Slice 2.
