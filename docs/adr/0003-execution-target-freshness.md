# ADR 0003: Explicit execution-target freshness

Status: Proposed

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

## Proposed API

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
meaningful fresh read. Reuse remains possible, but must be acknowledged at the call site:

```php
->executionTarget(ExecutionTargetPolicy::reuseSnapshot(
    name: 'immutable-tax-table-version',
    identityUsing: fn (ActionEnvelope $envelope, TaxTable $table): array => [
        'table_version' => $table->version,
    ],
))
```

`reuseSnapshot` retains today's behavior and evidence must say so. It is not a freshness guarantee.
The explicit policy requirement applies to `BoundTool`; `GuardedTool` cannot establish that its
independent handler uses either resolved target and therefore does not participate in this feature.

### Canonical identity

Both strategies require `identityUsing`. It returns an associative array containing only arrays,
scalar values, and `null`, matching Verdict's other canonical binding APIs. Verdict fingerprints
the capability, target-policy name, and identity before refresh, then fingerprints the execution
target using the same function. For `reuseSnapshot`, both fingerprints describe the explicitly
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

For `BoundTool`, the proposed flow is:

```text
1. Resolve the proposal-stage target.
2. Inspect the proposal-stage Laravel Policy.
3. If confirmation is required, remember that requirement but do not consume its receipt yet.
4. Require an executable capability and an explicit execution-target policy.
5. Fingerprint the proposal target's canonical identity.
6. Refresh the target, or explicitly reuse the configured snapshot.
7. Fingerprint the execution target and deny an identity mismatch.
8. Inspect the Laravel Policy against the execution target.
9. Validate and atomically consume confirmation against the execution target, if required.
10. Consume the semantic rate-limit unit against the execution target, if configured.
11. Atomically claim the logical operation against the execution target, if configured.
12. Pass the execution target to `AuthorizedAction` and enter the executor.
13. Finalize the execution claim as completed or indeterminate.
```

Nothing after step 8 may use the proposal-stage target. Rate-limit and execution-claim bindings
must be derived from the execution evaluation so that mutable versions or canonical state included
by those policies are current as of the refresh.

## Confirmation ordering

The approval receipt must not be consumed before target refresh and execution-stage authorization.
Consumption should calculate its binding fingerprint using the execution evaluation and its
refreshed target. This produces two important outcomes:

- If current policy denies the action, the approved receipt remains unused.
- If an approval binding includes mutable state such as `order_version`, a change between proposal
  approval and execution produces a receipt mismatch and requires a new confirmation rather than
  consuming or applying the stale approval.

It is acceptable to perform target refresh and `Gate::inspect()` before atomically consuming the
receipt because neither operation performs the protected side effect. An invalid or missing receipt
still prevents rate-limit consumption, execution-claim admission, and executor entry.

This changes evidence order for confirmed capabilities to proposal, target refresh, execution
authorization, approval consumption, rate limit, and execution claim. Evidence stages describe
security decisions, not the start of the side effect.

## Failure semantics

| Condition | Result | Receipt consumed? | Rate unit consumed? | Claim created? |
|---|---|---:|---:|---:|
| No execution-target policy on a `BoundTool` capability | Recorded denial | No | No | No |
| `TargetNotResolvable` during refresh | Recorded target-refresh denial | No | No | No |
| Unexpected refresh exception | Application fault; execution stops | No | No | No |
| Invalid canonical identity | Application fault; execution stops | No | No | No |
| Proposal/execution identity mismatch | Recorded target-refresh denial | No | No | No |
| Execution-stage Policy denial | Recorded execution denial | No | No | No |
| Approval mismatch, expiry, or replay | Confirmation remains required | No | No | No |
| Rate-limit throttle | Recorded throttle | Yes, when confirmation applied | No new unit; bucket is full | No |
| Duplicate execution claim | Recorded claim denial | Yes, when confirmation applied | Yes, if configured | No new claim |

Expected target disappearance is an ordinary fail-closed outcome only when the application signals
it with `TargetNotResolvable`. Other exceptions remain operational faults and are not relabeled as
policy decisions.

The table preserves the existing rate-limit and at-most-once ordering. A successfully consumed
confirmation can be followed by a throttle or duplicate claim. Reordering those gates would create
different replay and accounting semantics and is outside this ADR.

## Evidence

Add a `target_refresh` evaluation stage. Its evidence should include:

- target-policy name;
- strategy (`refresh` or `reuse_snapshot`);
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

Rate-limit counters and execution claims currently use their own store transactions. A future outer
application transaction must not accidentally make those commits provisional. In particular, if an
execution claim shares the same database connection and is rolled back with a failed executor, the
logical operation could be admitted again, violating ADR 0002's strict-admission guarantee.

Any transactional-execution design must therefore define connection boundaries and failure
semantics explicitly. It may require a separately committed security-state connection, a staged
intent protocol, or a different orchestration model. Nested transactions or savepoints must not be
assumed to provide independent durability.

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

## Proposed implementation slices

### Slice 1: explicit target-at-execution policy

- Add `ExecutionTargetPolicy` with `refresh(...)` and `reuseSnapshot(...)` strategies.
- Require a policy for executable `BoundTool` capabilities.
- Add canonical identity validation and fingerprinting for refresh policies.
- Add the `target_refresh` evidence stage and fields.
- Refresh, compare identity, authorize, and execute using one execution target.
- Move confirmation consumption after refresh and execution authorization and bind it to the
  execution target.
- Namespace confirmation fingerprints with the execution-target policy name so deliberately
  versioned policy changes invalidate pending receipts.
- Update built-in examples and workbench capabilities to select their strategy explicitly.
- Test fresh external state, identity mismatch, missing target, unexpected faults, approval-version
  mismatch, gate denial, gate ordering, rate-limit ordering, claim ordering, and snapshot reuse.

### Slice 2: transactional execution strategies

- Design separate local-transactional and durable-intent/external-side-effect modes.
- Define connection ownership, lock acquisition, claim durability, rollback, and post-commit
  behavior.
- Do not begin implementation until those semantics survive a separate adversarial review.

## Review focus

Before Slice 1 is accepted, review should challenge:

1. Whether requiring an explicit policy for every executable `BoundTool` is the right pre-1.0
   default, rather than making refresh opt-in.
2. Whether delayed confirmation consumption has any incompatibility with Laravel AI approval
   resumption not represented in the current deterministic tests.
3. Whether `reuseSnapshot` is sufficiently cautionary naming for an explicit non-fresh target.
4. Whether any existing rate-limit or execution-claim path still receives the proposal target after
   the proposed reorder.
5. Whether the future transaction boundary can preserve independently durable execution claims on
   common Laravel database configurations.
