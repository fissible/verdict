# ADR 0016: One contended row per logical constraint

Status: Accepted

## Related issues

- [#20](https://github.com/fissible/verdict/issues/20) (open) adds genuine concurrency tests. Invariant
  C1 below is what those tests exist to assert; without it they are a collection of unrelated scenarios.
- [#16](https://github.com/fissible/verdict/issues/16) (open) benchmarks concurrency for the same three
  stores.
- [#37](https://github.com/fissible/verdict/issues/37) (implemented) measured the isolation-level
  question left open in Decision §6. See [ADR 0018](0018-repeatable-read-and-serializable-require-a-conflict-retry.md).

## Context

Three of Verdict's security-state stores enforce mutual exclusion, and all three do it the same way:

| Store | Contended row identity | Unique constraint | Lock | Insert-race retry |
|---|---|---|---|---|
| Rate limits | `(bucket_fingerprint, window_starts_at)` | `create_verdict_rate_limit_buckets_table.php.stub:20-23` | `src/RateLimits/DatabaseRateLimitStore.php:57` | `:32` |
| Execution claims | `binding_fingerprint` | `create_verdict_execution_claims_table.php.stub:17` | `src/ExecutionClaims/DatabaseExecutionClaimStore.php:198`, `:208` | `:38` |
| Approval receipts | `(tool_call_id, capability, binding_fingerprint)` | `create_verdict_approval_receipts_table.php.stub:28` | `src/Approvals/DatabaseApprovalReceiptStore.php:242`, `:255`, `:291` | `:42` |

The pattern is consistent, it is correct, and it is written down nowhere. It is also not obvious: the
retry looks like defensive error handling rather than a load-bearing part of the guarantee.

The failure it protects against is **write skew** — Berenson et al.'s anomaly A5B. A transaction reads
a set of rows, decides from an aggregate over that set, and writes a row that is not in the set it
read. Nothing is contended, so nothing serializes.

Concretely, a plausible future feature — "at most three active claims per tenant" — implemented as
`count(*) < 3` followed by an insert, has none of the three properties above. There is no single row to
lock. `lockForUpdate()` over the result set locks the rows that exist, which is not the row about to be
created. Two concurrent transactions both read two active claims, both decide they may proceed, and
both insert. Under snapshot isolation this raises no serialization failure. There is no unique
constraint to violate, so the retry never fires.

It would pass every single-threaded test in the suite. It would also pass a "concurrency" test that
shares one connection, which is why issue #20 specifies genuine process-level concurrency.

This is the same objection ADR 0013 records from Lamport: correctness that lives in the shape of a
query is a behavioral argument. The property needs to be stated over states.

## Decision

### Invariant C1

*Every security-state constraint Verdict enforces must be reducible to a single row whose identity is
guaranteed unique by a database constraint, and every transaction that could violate the constraint
must take a row lock on that row before deciding.*

### Corollaries

**1. Aggregate predicates may not admit.** A decision may not be derived from `count`, `sum`, `exists`,
or any other aggregate over a set of rows. A counter is a single row; a count over rows is not. If a
constraint is naturally expressed as an aggregate, it must first be materialized into one row — the
rate limiter is exactly this transformation, and it is why a rate limit is a bucket row rather than a
query over attempt rows.

**2. A row lock does not cover the insert race.** `lockForUpdate()` on a row that does not yet exist
locks nothing. The insert race is covered by the unique index, which converts the second writer's
insert into a `UniqueConstraintViolationException` — that exception is a *synchronization primitive*,
not an error. This is why all three stores catch it and re-execute against the now-existing row.

**3. The retry is bounded to one re-execution.** After the retry the row is guaranteed to exist, so a
loop is unnecessary; an unbounded loop under contention would trade a bounded wait for a livelock.

**4. A constraint that cannot be reduced to one row is not implementable under the current store
contracts.** It requires a new ADR, not a cleverer query. Naming this in advance is the point: the
tempting version of such a feature is a one-line query change with no visible risk.

**5. This applies to every implementation of the store contracts**, not only the shipped database ones
(`src/Contracts/RateLimitStore.php`, `ExecutionClaimStore.php`, `ApprovalReceiptStore.php`). A store
backed by Redis, DynamoDB, or anything else satisfies C1 or it is not a conforming store.

### 6. Isolation level: correctness must not require more than READ COMMITTED

The pattern is intended to be correct at READ COMMITTED and to remain correct at stricter levels
without depending on them. That is an intent, not yet a measurement:

- `SELECT ... FOR UPDATE` semantics differ between InnoDB under REPEATABLE READ and PostgreSQL under
  READ COMMITTED, particularly in what a locking read observes after a concurrent commit.
- Under PostgreSQL `SERIALIZABLE`, a conflict surfaces as SQLSTATE 40001 — an exception rather than a
  denial. Verdict catches `UniqueConstraintViolationException` and nothing else, so an operator who
  configures a dedicated connection at `SERIALIZABLE` (which ADR 0004 makes plausible) would see
  contention as a 500 rather than as a correct denial.

Both claims are about real database behavior and must be measured rather than argued, per the
project's rule on IO and timing claims. That measurement is tracked as a separate issue and is expected
to produce a follow-on ADR fixing the supported isolation levels and the required exception handling.
Until then, this ADR states the intent and marks the question open rather than asserting a guarantee.

**Update:** #37 measured this. READ COMMITTED is confirmed safe on PostgreSQL and MySQL. REPEATABLE
READ (MySQL/MariaDB's *default*) and SERIALIZABLE are not — both raise SQLSTATE 40001 under genuine
concurrent contention, uncaught by the current implementation. See
[ADR 0018](0018-repeatable-read-and-serializable-require-a-conflict-retry.md) for the confirmed
isolation-level guarantees, the required exception handling, and `docs/limitations.md` for the
operator-facing statement of the current risk.

## Non-goals

- **No `src/` change.** The invariant describes code that already exists.
- **No new store contract.**
- **No decision on isolation levels.** Decision §6 states the intent and the open question; it does not
  settle it.
- **This does not extend to application data.** C1 governs Verdict's security state. Domain-level
  concurrency remains the application's, as `docs/limitations.md` already says.

## Consequences

- Reviewers get a property to check a store change against, in one sentence, without reconstructing the
  concurrency argument from three files.
- Issue #20 gains its assertion target: a constraint enforced without a contended row must be observable
  as a test failure under real process-level concurrency.
- The unique-index-plus-retry pattern is documented as load-bearing, so a future cleanup that "removes
  the redundant catch" is recognizable as a security regression.
- New store implementations, including third-party ones, have a conformance criterion.

## Alternatives rejected

### Rely on `SERIALIZABLE` isolation instead of explicit locks

Rejected on three grounds. It converts policy denials into infrastructure exceptions (SQLSTATE 40001),
which is the wrong shape — a rate-limited request should be denied, not thrown. It imposes a global
cost on whatever connection Verdict shares unless a dedicated one is configured, which ADR 0004 makes
optional rather than required. And its behavior differs materially across the drivers Verdict supports,
so it would trade an explicit, portable mechanism for an implicit, non-portable one.

### Use advisory locks or an external lock service

Rejected. It introduces a second source of truth for mutual exclusion, so a lock-service outage becomes
a security failure rather than a denial, and the unique index would still be required for durability.
Adding a component that must be available for correctness to hold is strictly worse than a constraint
the database already enforces.

### Optimistic concurrency with a version column and a retry loop

Rejected. It provides an equivalent guarantee at higher complexity, and it does not address the case
that actually matters — the row does not exist yet, so there is no version to compare. The insert race
would still need the unique index, at which point the version column is redundant.

### Leave the pattern implicit because all three stores already follow it

Rejected. The pattern's fragility is not in the existing code; it is in the next constraint someone
adds. The failure mode is silent, passes tests, and looks like ordinary application code. That is
precisely the category of thing an ADR is for.

## Sources

- Berenson, H., Bernstein, P., Gray, J., Melton, J., O'Neil, E. and O'Neil, P. "A Critique of ANSI SQL
  Isolation Levels." SIGMOD '95 — anomaly A5B, write skew.
- Fekete, A., Liarokapis, D., O'Neil, E., O'Neil, P. and Shasha, D. "Making Snapshot Isolation
  Serializable." *ACM TODS* 30(2), 2005.
- Ports, D. R. K. and Grittner, K. "Serializable Snapshot Isolation in PostgreSQL." VLDB 2012 —
  SQLSTATE 40001 as the conflict signal.
- Lamport, L. Author's annotation to "On-the-fly Garbage Collection: An Exercise in Cooperation"
  (1978) — state-based rather than behavioral reasoning for concurrent algorithms.
