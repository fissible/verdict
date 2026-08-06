# ADR 0004: Require independently committed security-state mutations

- Status: Accepted
- Date: 2026-08-01

## Related issues

- [#16](https://github.com/fissible/verdict/issues/16) (open) benchmarks the stores whose transactions must commit independently.
- [#19](https://github.com/fissible/verdict/issues/19) (open) consolidates the accepted gate ordering for readers.
- [#20](https://github.com/fissible/verdict/issues/20) (open) adds genuine concurrent-access coverage for the transaction guard and stores.

## Context

Verdict's database approval, semantic-rate-limit, and execution-claim stores use transactions and
row locks to make state transitions atomic. Laravel implements a transaction started inside an
already-active transaction on the same connection as a nested transaction or savepoint. Committing
that inner transaction does not make its state independently durable; a later outer rollback can
still erase it.

That creates a security-relevant ambiguity when application code wraps an entire
`BoundTool::handle()` or `VerdictManager::runBound()` invocation in a transaction on a Verdict
store's connection. The executor may complete while a later outer rollback restores an approval
receipt, erases a consumed rate-limit unit, or erases an execution claim.

An executor that starts its own transaction *after* Verdict has admitted the operation is not this
case. By then, each configured security-state gate has committed its own transition.

## Decision

Every mutating operation on the database approval-receipt, rate-limit, and execution-claim stores
checks `ConnectionInterface::transactionLevel()` before starting its own transaction. If the level
is not zero, the operation throws `UnsafeOuterTransaction` before changing state.

Applications that need an outer transaction around a Verdict invocation must configure the durable
Verdict stores on separately committed database connections. A separately named connection may
point to the same database, but it must not share the active Laravel connection instance and
transaction scope.

The guard applies to:

- issuing, approving, rejecting, and consuming approval receipts;
- consuming semantic rate-limit units; and
- claiming, completing, marking indeterminate, and reconciling execution claims.

Read-only lookups do not require the guard. Expired-bucket pruning does not participate in runtime
admission and may be wrapped in an operator-selected transaction. `DatabaseEvidenceRecorder` is
also excluded: evidence persistence and transaction ownership are application policy, and evidence
is not itself an authorization gate. Applications that require independently durable evidence
should configure its connection accordingly.

There is no fail-open switch. A future coordinated transactional-execution strategy may define an
explicit alternative protocol, but ordinary nested transactions are not treated as independently
durable.

## Consequences

- An unsafe transaction topology fails before the protected executor is admitted.
- Applications using Laravel's `DatabaseTransactions` test trait around Verdict database stores
  must use separate store connections or test through in-memory stores.
- Store exceptions remain operational faults rather than ordinary model-visible denials.
- The package can accurately claim that configured database security-state transitions do not
  silently depend on an unknown outer commit.

## Alternatives rejected

### Document the hazard without enforcing it

The failure is difficult to observe and can invalidate replay or rate-limit guarantees after the
application has already reported success. Documentation alone is insufficient for a fail-closed
security boundary.

### Commit the application's outer transaction

Verdict does not own that transaction and cannot safely commit or roll it back.

### Permit nested transactions when savepoints are available

A savepoint provides local rollback structure, not an independently durable commit. The outer
transaction can still erase the security-state transition.

### Guard the evidence recorder and pruning command too

Those writes do not admit protected execution. Their atomicity and retention belong to application
operations policy rather than this runtime gate.
