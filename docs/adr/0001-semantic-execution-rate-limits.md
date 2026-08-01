# ADR 0001: Semantic execution rate limits

Status: Accepted

## Context

Laravel already provides request and queue throttling. Verdict needs a narrower security boundary:
an application may authorize an individual AI-proposed action while still needing to limit the
aggregate number of times a principal, tenant, or resource may perform that capability.

This first slice does not attempt to implement general abuse analytics, token or cost budgets,
conversation limits, or execution idempotency.

## Decision

- A capability may opt into one named, fixed-window `RateLimitPolicy`.
- The application derives a canonical bucket binding from the trusted `ActionEnvelope` and the
  server-resolved target. Verdict hashes the capability name, policy name, window configuration,
  and binding before storage.
- The limiter consumes an **authorized execution attempt** after execution-stage authorization and
  approval consumption, immediately before the target-bound executor runs.
- Policy denials, pending confirmations, approval mismatches, and missing executors do not consume
  a unit.
- Consumption is atomic and durable. The initial adapter uses a database transaction and row lock;
  concurrent first inserts retry the consume operation after a unique-key race.
- A permitted or throttled rate-limit evaluation is recorded as its own evidence stage. Evidence
  contains only the opaque bucket fingerprint plus the policy name, limit, remaining count, and
  reset time.
- Store failures propagate as application faults. Execution does not continue, and infrastructure
  failures are not mislabeled as ordinary throttles.
- Expired database buckets require pruning. Verdict provides a pruning command; applications choose
  its schedule.

## Retry and idempotency boundary

Approval resumption does not double-consume because an approval receipt is single-use and the
limiter runs only after that receipt is consumed successfully. Transport-level redelivery of an
unconfirmed action may consume another unit because it may also execute the action again. Verdict
does not disguise that larger execution-idempotency gap by deduplicating only the limiter.

## Consequences

- A consumed unit represents an execution attempt, not a guaranteed successful side effect. A
  process crash or ambiguous external-service failure does not refund the unit.
- Changing a policy name or window duration starts a new bucket namespace. Changing only the limit
  applies the new limit to the current bucket.
- Denied-target enumeration and proposal flooding are not covered by this slice. They require
  separate enforcement points and will be designed separately.

