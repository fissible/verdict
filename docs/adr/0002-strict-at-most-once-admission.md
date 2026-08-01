# ADR 0002: Strict at-most-once execution admission

Status: Accepted

## Context

Queue retries, duplicate webhooks, repeated model tool calls, and transport redelivery can present
the same logical action more than once. Rate limiting bounds aggregate attempts but does not prevent
a duplicate side effect. Provider tool-call IDs are transport metadata: they may collide, disappear,
or change when the same logical operation is proposed again.

Exactly-once external side effects cannot be guaranteed generically. A process may fail after a
payment provider, email service, or carrier accepted a request but before local state records
completion.

## Decision

- A capability may opt into one named `ExecutionClaimPolicy` using `atMostOnce(...)`.
- The application derives a canonical logical-operation binding from trusted action context and the
  server-resolved target. Verdict hashes the capability name, claim-policy name, and binding. The
  provider tool-call ID and general argument fingerprint remain evidence and are not claim identity.
- The execution claim is the final gate: execution-stage authorization, confirmation consumption,
  and semantic rate-limit consumption happen first; the atomic claim happens immediately before the
  executor.
- Only the first caller to claim a logical operation may enter the executor. Concurrent or later
  duplicates are denied whether the original claim is active, completed, or indeterminate.
- A successful executor marks the claim completed. A thrown executor marks it indeterminate and the
  original exception continues to propagate. Store or finalization failures remain application
  faults and never authorize another execution.
- No lease, timeout, automatic retry, or cached raw result is provided.
- Operators may inspect unresolved claims. After application-specific investigation they may mark a
  claim completed or explicitly release it for one retry. The operator identity and reason are
  required and persisted.

## Ordering consequence

A duplicate can consume a semantic rate-limit unit before its existing claim blocks execution. This
is intentional: rate limits count authorized attempts, while execution claims prevent duplicate
admission. Claiming before rate limiting would permanently consume a logical operation that was
never allowed to execute because its rate bucket was exhausted.

## Guarantee

Verdict provides strict at-most-once **admission to the configured executor** within the retained
claim history. It does not promise exactly-once effects, successful completion, automatic recovery,
or idempotency for execution paths that bypass Verdict.

For the database store, this guarantee also requires the claim transaction to commit independently.
An application transaction wrapped around the entire Verdict invocation on the same configured
connection can make the inner claim transaction provisional and later roll it back. An executor
that starts and commits its own transaction inside `executeUsing` is not the same pattern: the claim
has already committed before executor entry. Until Verdict adds a fail-closed transaction-level
guard, use a distinct `execution_claims.connection` whenever the whole Verdict invocation may run
inside an application transaction.
