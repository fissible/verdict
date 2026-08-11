# ADR 0018: REPEATABLE READ and SERIALIZABLE require a conflict retry, not just a unique-violation retry

Status: Accepted

## Related issues

- [#37](https://github.com/fissible/verdict/issues/37) (implemented) measured this. Raw transcripts are
  in that issue's comments and in `spikes/0037-isolation-level-concurrency/results/` on the branch that
  landed this ADR.
- [#86](https://github.com/fissible/verdict/issues/86) implements the required retry fix this ADR states
  but deliberately does not carry out (see ADR 0016's precedent for stating the invariant separately
  from the `src/` change).
- [#20](https://github.com/fissible/verdict/issues/20) adds the durable concurrency test suite; the
  driver-specific assertions it can now write are stated in this ADR's Decision.
- [#16](https://github.com/fissible/verdict/issues/16) benchmarks the same three stores; the retry this
  ADR requires adds a latency cost under contention that #16 should measure.

## Context

ADR 0016 Decision §6 stated an intent — the lock-then-insert-then-retry pattern shared by
`DatabaseRateLimitStore::consume()`, `DatabaseExecutionClaimStore::claim()`, and
`DatabaseApprovalReceiptStore` is *intended* to be correct at READ COMMITTED and to remain correct at
stricter levels without depending on them — and marked it unmeasured. #37 measured it: genuine
process-level concurrency (separate OS processes via `proc_open`, separate connections — not sequential
calls or transactions sharing one connection), racing 2–20 concurrent callers against one contended row,
across PostgreSQL READ COMMITTED, PostgreSQL SERIALIZABLE, MySQL/InnoDB REPEATABLE READ, MySQL/InnoDB
READ COMMITTED, and MariaDB (which defaults to REPEATABLE READ, same as MySQL).

**READ COMMITTED is confirmed safe on both PostgreSQL and MySQL.** Three runs, 20 concurrent processes
per race, zero errors, the invariant held exactly every time: rate-limit admitted exactly the configured
limit, execution-claim admitted exactly one winner.

**REPEATABLE READ is not.** MySQL and MariaDB under InnoDB REPEATABLE READ — which is InnoDB's *default*,
not an opt-in stricter setting an operator has to choose — failed on 2 of 3 runs each, always with the
same signature:

```
SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting
transaction (... insert into `verdict_execution_claims` (...) values (...))
```

raised from the plain `insert` inside `consume()`'s/`claim()`'s own transaction, and **uncaught** — the
stores only catch `Illuminate\Database\UniqueConstraintViolationException`. This is InnoDB's documented
gap-lock behavior under REPEATABLE READ: concurrent `INSERT`s that would land in the same index gap take
conflicting gap locks, and one is chosen as the deadlock victim. It is not driven by seizing the same
existing row (`lockForUpdate()` finding no row is exactly the case being raced), it is driven by the
*insert* itself, which is why the existing unique-violation catch does not cover it — a genuinely
different SQLSTATE, from a genuinely different cause, that the code was never written to expect.

**SERIALIZABLE is not either**, and this part was anticipated correctly. Both stores, both raced
patterns, every contention level tested (2, 5, 20 concurrent callers), every run: `SQLSTATE 40001`,
uncaught. At contention level 5, exactly 1 of 5 callers got a clean response and the other 4 threw —
identically across two independent runs.

**In every case, the invariant held among the responses that did not throw.** No test observed a wrong
answer — more than the rate limit admitted, or more than one claim winner. The failure mode is
availability, not correctness: a caller that should receive a clean, policy-shaped answer (admitted,
denied, or duplicate) instead receives an unhandled `Illuminate\Database\QueryException`, which — absent
handling at a higher layer — surfaces as a 500.

## Decision

### Supported isolation levels, stated as a fact about current code, not an aspiration

- **READ COMMITTED (PostgreSQL or MySQL/MariaDB explicit) is supported today.** No further change is
  required for this level; the existing unique-violation catch is sufficient because the only race this
  level admits is the one that catch was written for.
- **REPEATABLE READ (MySQL/MariaDB default) and SERIALIZABLE (any driver, opt-in) are not supported by
  the current implementation.** An application running Verdict against MySQL or MariaDB with no explicit
  isolation-level configuration — which is to say, most deployments, since REPEATABLE READ is what those
  engines ship with — is exposed to this today: a legitimate concurrent rate-limit consumption or
  execution-claim attempt can surface as an unhandled exception instead of a denial, under real
  contention, right now. See `docs/limitations.md` for the operator-facing statement of this.

### The required fix (next issue, not this ADR)

Per ADR 0016's own precedent (state the invariant, defer the `src/` change to a separate issue) and this
plan's Global Constraints, this ADR does not implement the fix. The fix, confirmed by #37's evidence
rather than merely proposed:

1. **Catch `40001` narrowly, at the store boundary, alongside the existing
   `UniqueConstraintViolationException` catch** — not by widening that catch's type, since the two
   exceptions mean different things and the existing catch's *insert already succeeded, read what's
   there* recovery is specific to a unique-key collision, not a deadlock/serialization abort where the
   caller's insert did not happen at all and the same `mayInsert: true` attempt should simply be retried.
2. **Bound to one retry**, matching the existing unique-violation precedent (ADR 0016 Corollary 3) and
   the reasoning in #37's own proposal: a serialization failure means "your transaction would have
   produced a non-serializable outcome; re-execute against the now-committed state," which converts the
   infrastructure exception into the correct policy outcome exactly the way the existing retry already
   does for the insert race. An unbounded loop under contention trades a bounded wait for a livelock.
3. **The catch must be scoped to Verdict's own transaction, not the application's.** ADR 0004's
   independent-transaction guard (`IndependentTransactionGuard::assertNoOuterTransaction`) already
   ensures every mutating store call runs in a transaction Verdict opened itself
   (`$this->connection->transaction(...)`), never nested inside an application transaction — so a 40001
   caught inside that `transaction()` closure is, by construction, a conflict *within Verdict's own
   write*, not an application-level serialization failure that must be allowed to propagate. This is
   what makes the narrow catch safe: it is not swallowing an exception that belongs to code outside
   Verdict's boundary.

This applies to all three stores sharing the pattern (rate limits, execution claims, approval receipts —
ADR 0016's table), not only the two #37 exercised directly. `DatabaseApprovalReceiptStore` was not raced
in #37 (out of this spike's time budget) but shares the identical lock-then-insert-then-retry shape and
should be assumed equally exposed until it is measured or fixed alongside the other two.

### What #20 can now assert

#20's genuine-concurrency test suite can now write real per-driver assertions instead of guessing at
what "correct" means under contention:

- At READ COMMITTED (any driver): the race must complete cleanly, zero exceptions, invariant exact.
- At REPEATABLE READ (MySQL/MariaDB) and SERIALIZABLE (any driver), **before** the retry fix lands:
  these are expected-red tests, pinning the current defect the way
  `tests/Feature/ToolInvocationCorrelationTest.php` pins a known upstream bug — a test that currently
  fails is not a broken test, it is the recorded proof the gap exists.
- At REPEATABLE READ and SERIALIZABLE, **after** the retry fix lands: the same invariant as READ
  COMMITTED, zero uncaught exceptions, under the same real process-level concurrency this ADR's evidence
  used, not a same-connection simulation.

## Consequences

- The operational risk is real and current, not hypothetical: it is documented in
  `docs/limitations.md` rather than left implicit, per this repo's practice of naming a known gap rather
  than letting a contributor discover it and mistake it for an oversight (the same reasoning ADR 0006
  used for the streaming approval gap before #22 fixed it).
- [#86](https://github.com/fissible/verdict/issues/86) is filed for the retry fix, at higher priority
  than #37's own "M, no urgency" framing, because #37 changed the finding from "unmeasured, assumed
  fine" to "measured, confirmed unsafe on the isolation level most MySQL/MariaDB deployments run by
  default."
- CI (a separate, parallel piece of this same branch — see the PR) adds PostgreSQL to every PR alongside
  SQLite. That does not, by itself, catch a REPEATABLE-READ regression, since PostgreSQL's default is
  READ COMMITTED, not REPEATABLE READ — the engine that actually exercises this ADR's finding is MySQL
  or MariaDB at their own defaults. This is noted directly in the CI task rather than assumed away: the
  every-PR job catches the SERIALIZABLE-class risk once fixed-and-tested, but the REPEATABLE-READ-class
  risk needs a MySQL or MariaDB job specifically, which the current plan defers to the tag/weekly full
  matrix rather than every PR, matching #37's original Part 3 proposal's cost/benefit tradeoff — flagged
  here as a real gap in coverage cadence, not hidden by it.
- `DatabaseApprovalReceiptStore` remains unmeasured by #37 and should not be assumed safe merely because
  it was not raced — the follow-up issue's acceptance criteria should require it be covered too, per
  ADR 0016's Invariant C1 applying identically to all three stores.

## Alternatives rejected

### Recommend READ COMMITTED as the only supported level, and document REPEATABLE READ/SERIALIZABLE as unsupported rather than fixing them

Rejected. MySQL and MariaDB ship REPEATABLE READ as the default; recommending READ COMMITTED as a
prerequisite silently shifts a security-relevant configuration burden onto every operator, most of whom
will never read that requirement before they hit it in production under load. Fixing the retry is a
bounded, well-understood change (identical shape to the retry these stores already have); asking every
deployment to reconfigure their database's default isolation level is not bounded and is not this
package's call to make on an operator's behalf.

### Treat 40001 as equivalent to `UniqueConstraintViolationException` and widen the existing catch

Rejected. The two exceptions have different SQLSTATEs, different causes (a unique-key collision on an
insert that *did* happen and can be read back, versus a serialization abort on a transaction that did
*not* commit at all), and different correct recoveries (read-then-transition, versus re-execute the
original attempt unchanged). Conflating them in one `catch` block would recover the wrong state on
whichever case does not match the retry logic actually written for the other.

## Sources

- InnoDB gap locking and its interaction with concurrent `INSERT` statements under REPEATABLE READ —
  MySQL 8.0 Reference Manual, "InnoDB Locking," §"Gap Locks."
- Ports, D. R. K. and Grittner, K. "Serializable Snapshot Isolation in PostgreSQL." VLDB 2012 — SQLSTATE
  40001 as the conflict signal this ADR's Spike B observed directly.
- Raw transcripts: [#37](https://github.com/fissible/verdict/issues/37) issue comments, and
  `spikes/0037-isolation-level-concurrency/results/` (gitignored locally; the issue comment is the
  durable copy).
