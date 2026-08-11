# ADR 0018: REPEATABLE READ and SERIALIZABLE require a conflict retry, not just a unique-violation retry

Status: Accepted

## Related issues

- [#37](https://github.com/fissible/verdict/issues/37) (implemented) measured this. Raw transcripts are
  in that issue's comments and in `spikes/0037-isolation-level-concurrency/results/` on the branch that
  landed this ADR.
- [#86](https://github.com/fissible/verdict/issues/86) (implemented) carried out the retry fix this ADR
  originally deferred, and measured its actual effect — see the Update below.
- [#92](https://github.com/fissible/verdict/issues/92) implements the jittered retry that resolved the
  MySQL 8 and MariaDB 11 residual found after #86; see the later Update for the original finding and
  its replacement.
- [#97](https://github.com/fissible/verdict/issues/97) investigates the remaining PostgreSQL
  SERIALIZABLE rate-limit availability gap.
- [#20](https://github.com/fissible/verdict/issues/20) adds the durable concurrency test suite; a first
  version of it landed directly in #86 (`tests/Feature/SecurityStateConcurrencyRetryTest.php`), since #86
  needed real evidence its own fix worked, not just a plan for one.
- [#16](https://github.com/fissible/verdict/issues/16) benchmarks the same three stores; the retry this
  ADR requires adds a latency cost under contention that #16 should measure.

## Context

ADR 0016 Decision §6 stated an intent — the lock-then-insert-then-retry pattern shared by
`DatabaseRateLimitStore::consume()`, `DatabaseExecutionClaimStore::claim()`, and
`DatabaseApprovalReceiptStore::issue()` is *intended* to be correct at READ COMMITTED and to remain
correct at stricter levels without depending on them — and marked it unmeasured. #37 measured it: genuine
process-level concurrency (separate OS processes via `proc_open`, separate connections — not sequential
calls or transactions sharing one connection), racing 2–20 concurrent callers against one contended row
in **all three** stores, across PostgreSQL READ COMMITTED, PostgreSQL SERIALIZABLE, MySQL/InnoDB
REPEATABLE READ, MySQL/InnoDB READ COMMITTED, and MariaDB (which defaults to REPEATABLE READ, same as
MySQL).

**A first pass without a synchronized start understated the problem, and was corrected before this
ADR was finalized.** `proc_open` alone launches separate processes but does not make them reach the
store call at the same instant — process boot, autoload, and the initial DB handshake are the dominant
source of latency variance (empirically ~52–175ms per process under 20-way parallel startup), not the
query itself, so without forcing genuine overlap, "zero errors" from a batch that never actually
collided proves nothing about the safe cases, and even the failure counts for the unsafe cases were an
undercount. The harness was corrected to hold every child at a shared wall-clock start barrier — a
`start_at` deadline computed once per batch with a 1-second buffer (~6× the measured p95 startup
latency) and passed identically to every child, which then busy-waits until that instant before calling
the store — so the batch reaches the contended operation as close to simultaneously as process
scheduling allows. All results below are from the barrier-corrected harness; see
`spikes/0037-isolation-level-concurrency/results/` for both the earlier, weaker transcripts and the
corrected ones, kept side by side rather than overwritten, so the correction itself is part of the
record.

**READ COMMITTED is confirmed safe on both PostgreSQL and MySQL, under genuine forced overlap.** Three
runs, 20 concurrent processes per race, all three stores, zero errors every time: rate-limit admitted
exactly the configured limit, execution-claim admitted exactly one winner, approval-receipt issued
exactly one receipt.

**REPEATABLE READ is not, and the corrected numbers are far worse than the first pass suggested.**
MySQL and MariaDB under InnoDB REPEATABLE READ — which is InnoDB's *default*, not an opt-in stricter
setting an operator has to choose — failed **19 of 20 concurrent attempts** (rate-limit and
approval-receipt) or all 20 (execution-claim, in one run), consistently across three runs, for **all
three stores**, always with the same signature:

```
SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting
transaction (... insert into `verdict_execution_claims` (...) values (...))
```

raised from the plain `insert` inside the store's own transaction, and **uncaught** — the stores only
catch `Illuminate\Database\UniqueConstraintViolationException`. This is InnoDB's documented gap-lock
behavior under REPEATABLE READ: concurrent `INSERT`s that would land in the same index gap take
conflicting gap locks, and one is chosen as the deadlock victim. It is not driven by seizing the same
existing row (`lockForUpdate()` finding no row is exactly the case being raced), it is driven by the
*insert* itself, which is why the existing unique-violation catch does not cover it — a genuinely
different SQLSTATE, from a genuinely different cause, that the code was never written to expect. Under
genuine forced contention, this is not an edge case — it is closer to the common case.

**SERIALIZABLE is unsafe for rate limits and execution claims, and this part was anticipated
correctly.** Both stores, every contention level tested (2, 5, 20 concurrent callers), every run:
`SQLSTATE 40001`, uncaught. At contention level 5, exactly 1 of 5 callers got a clean response and the
other 4 threw — identically across two independent runs.

**SERIALIZABLE did not raise 40001 for approval receipts, in this exact test shape, reproducibly
across two runs.** All 20 concurrent `issue()` calls succeeded cleanly at every contention level (2, 5,
20), with exactly one `Issued` and the rest correctly `Existing`. This is a genuine, reproduced
observation, not a fluke — but it is not proof that `DatabaseApprovalReceiptStore` is safe under
SERIALIZABLE in general. PostgreSQL's serializable snapshot isolation uses predicate-lock heuristics
that can depend on the exact index shape and query pattern; the approval-receipt unique constraint is a
three-column composite (`tool_call_id`, `capability`, `binding_fingerprint`) versus the other two
stores' one- and two-column constraints, and a real approval flow reads and writes more state within
the transaction (expiry checks, status transitions) than this spike's minimal race does. Absence of an
observed conflict in one specific harness is not the same claim as REPEATABLE READ's confirmed
vulnerability, and the Decision below does not treat it as such.

**In every case, the invariant held among the responses that did not throw.** No test observed a wrong
answer — more than the rate limit admitted, more than one claim winner, or more than one approval
issuer. The failure mode is availability, not correctness: a caller that should receive a clean,
policy-shaped answer (admitted, denied, or duplicate) instead receives an unhandled
`Illuminate\Database\QueryException`, which — absent handling at a higher layer — surfaces as a 500.

## Decision

### Supported isolation levels, stated as a fact about current code, not an aspiration

- **READ COMMITTED (PostgreSQL or MySQL/MariaDB explicit) is supported today.** No further change is
  required for this level; the existing unique-violation catch is sufficient because the only race this
  level admits is the one that catch was written for.
- **REPEATABLE READ and SERIALIZABLE were not supported by the implementation at the time this ADR was
  first written.** #86 has since implemented and measured the fix — see the Update section below for
  current, per-engine status. That Update supersedes this bullet; it is left here as the historical
  starting point, not edited to read as if it were always true. See `docs/limitations.md` for the
  current, operator-facing statement of what remains unresolved.

### The required fix

The fix, confirmed by #37's evidence rather than merely proposed (and, as of #86, actually implemented
and measured — see the Update below):

1. **Retry on `40001`, scoped to Verdict's own transaction, not the application's, and kept separate
   from the existing `UniqueConstraintViolationException` catch** — not by widening that catch's type,
   since the two exceptions mean different things and the existing catch's *insert already succeeded,
   read what's there* recovery is specific to a unique-key collision, not a deadlock/serialization abort
   where the caller's insert did not happen at all and the same `mayInsert: true` attempt should simply
   be retried.
2. **Bound to one retry**, matching the existing unique-violation precedent (ADR 0016 Corollary 3) and
   the reasoning in #37's own proposal: a serialization failure means "your transaction would have
   produced a non-serializable outcome; re-execute against the now-committed state," which converts the
   infrastructure exception into the correct policy outcome exactly the way the existing retry already
   does for the insert race. An unbounded loop under contention trades a bounded wait for a livelock.
3. **The retry must be scoped to Verdict's own transaction, not the application's.** ADR 0004's
   independent-transaction guard (`IndependentTransactionGuard::assertNoOuterTransaction`) already
   ensures every mutating store call runs in a transaction Verdict opened itself
   (`$this->connection->transaction(...)`), never nested inside an application transaction — so a 40001
   caught inside that `transaction()` closure is, by construction, a conflict *within Verdict's own
   write*, not an application-level serialization failure that must be allowed to propagate. This is
   what makes retrying safe: it is not swallowing an exception that belongs to code outside Verdict's
   boundary.

This applies to all three stores sharing the pattern (rate limits, execution claims, approval receipts —
ADR 0016's table). #37 raced all three directly, not just two: `DatabaseApprovalReceiptStore` is
confirmed equally exposed under REPEATABLE READ (19/20 failures, matching the other two stores) and
should get the identical fix regardless of SERIALIZABLE's non-reproduction there — that observation
bears on SERIALIZABLE specifically, not on REPEATABLE READ, and not on whether the fix is warranted.

**Implementation note (added by #86):** the fix does not need a hand-rolled `catch` block at all.
`Illuminate\Database\Connection::transaction(Closure $callback, int $attempts = 1)` already retries the
whole callback when `Illuminate\Database\ConcurrencyErrorDetector::causedByConcurrencyError()` matches —
which explicitly checks `SQLSTATE 40001` — and it already refuses to retry (throwing `DeadlockException`
instead) when called from inside a nested transaction, which independently reproduces requirement 3
above without any code in these stores needing to know about it. The actual change in each store is
passing `2` as the second argument to the existing `$this->connection->transaction(...)` calls (both the
insert attempt and, where present, the unique-violation-triggered re-read), not a new `catch` clause.
This was not obvious going in — the original plan for this ADR assumed a manual catch/retry, matching
the shape of the *existing* unique-violation handling — and is recorded here because it materially
simplifies both the change and its risk surface: no new exception-handling code path, reusing a
mechanism Laravel's own framework tests already cover.

### What the durable test suite asserts (superseded by measurement — see Update below)

At the time this ADR was first written, the expectation was: READ COMMITTED asserted strictly
everywhere; REPEATABLE READ and SERIALIZABLE asserted strictly once the retry fix landed, uniformly
across drivers. **That turned out to be wrong — first found on MariaDB, then, after a further harness
correction, confirmed on MySQL 8 as well** — see the Update section immediately below for what
`tests/Feature/SecurityStateConcurrencyRetryTest.php` actually asserts today, and why.

## Update (#86): the fix landed, and measurement found more than expected

#86 implemented the retry described above and measured it with the same genuine, barrier-synchronized
process-level concurrency #37 used — not assumed to work because the reasoning was sound, tested
directly, per this repo's standing rule on IO/timing/concurrency claims.

**This section was corrected twice during #93's review, in two separate rounds, after two separate
measurement-methodology gaps were found in the harness itself — not in the retry fix. Both
corrections are recorded here rather than silently overwritten, because the earlier, wrong numbers
were already committed to this ADR and to `docs/limitations.md` once, and this repo's practice is to
correct documentation honestly when a finding changes, not pretend it was always known.**

**Round 1 finding — the connection wasn't forced before the barrier.** #86's original measurement
found the durable test's child processes never actually forced their PDO connection before the
shared start barrier (`Connection::$pdo` starts as a lazy `Closure`, resolved only on first real
query) — so "concurrent" runs could still be serialized by each child independently paying its own
connection-handshake cost after the barrier released them. Re-measured with the connection genuinely
forced (`$connection->getPdo()`, applied to every child script including the #37 spike's), the initial
correction concluded: MySQL 8 execution claims and approval receipts clean across 14+ runs; MySQL 8
rate limits showing one severe failure in 14 runs; MariaDB severely and inconsistently affected across
all three stores. That correction turned out to still be incomplete — see Round 2.

**Round 2 finding — the start barrier itself was a probabilistic guess, not a synchronization
guarantee.** The barrier was a fixed wall-clock timestamp, computed once before spawning any
children and sized from measured p95 boot/connect latency. That is a statistical bound, not a
guarantee: any child slower than the estimate would still miss the barrier, start its store call
late, and silently reduce measured contention without the test being able to tell — a false-clean
result, the exact class of problem Round 1 had already found once in a different place.
`ConcurrencyHarness` was corrected to a real ready/release handshake instead: every child signals
readiness (over a dedicated pipe) only after forcing its connection, and the harness releases the
whole batch only once every child has signaled (see `ConcurrencyHarness::releaseWhenAllReady()`).
Re-measuring with this guarantee — not an estimate — changed the picture substantially:

**MySQL 8 and MariaDB 11 (both Laravel's `mysql` driver) show the same bimodal, inconsistent failure
pattern, on all three stores, differing only in how often it triggers.** Most 20-way-contention runs
are fully clean; a substantial minority fail severely (commonly 12-19 of 20 attempts throwing
`SQLSTATE 40001` in the same run). Measured across repeated runs at 20-way contention:

| Store | MySQL 8 (severe-failure rate) | MariaDB 11 (severe-failure rate) |
|---|---|---|
| Rate limits | 6 of 20 runs (30%) | 6 of 10 runs (60%) |
| Execution claims | 3 of 20 runs (15%) | 7 of 10 runs (70%) |
| Approval receipts | 7 of 20 runs (35%) | 6 of 10 runs (60%) |

Raising the retry count made no measurable difference on either engine in earlier measurement rounds.
**The Round 1 conclusion that MySQL 8 was "fully and reliably resolved" for claims/approvals, and
that MariaDB's severity was a mysterious divergence from MySQL, was itself substantially a
measurement artifact of the probabilistic barrier** — not eliminated by this correction, but
reframed: MySQL 8 and MariaDB 11 appear to hit the *same* underlying race, just at different
frequencies (MariaDB roughly 2x MySQL's rate here), not a different one. Why the frequency differs
between the two engines is still not understood and remains tracked as
[#92](https://github.com/fissible/verdict/issues/92), reframed to reflect this.

**PostgreSQL: unaffected by either correction, numbers unchanged.** Natural READ COMMITTED: zero
errors across every run tested (all three stores). SERIALIZABLE: execution claims and approval
receipts fully resolved (zero errors at 20-way contention, confirmed reproducibly); rate limits
retain a real, consistent (not bimodal — every run affected to some degree) residual: ~15-18 of 20
across repeated runs at contention level 20, fully clean at contention level 2. Raising the retry
count from 2 to 3 barely moved this (18→17 errors) — Laravel's built-in retry has no backoff or
jitter, so simultaneous retriers tend to collide again immediately, and this is a case of genuinely
diminishing returns, not an undersized bound. Postgres's SERIALIZABLE `force_serializable` payload
path already ran a real statement before the barrier in every prior version of this suite, which is
why neither correction changed its numbers — it was never actually affected by the harness bugs Round
1 and Round 2 found and fixed elsewhere.

**The durable test suite (`tests/Feature/SecurityStateConcurrencyRetryTest.php`, added directly in #86
rather than waiting for #20) reflects the corrected picture, not the original optimistic one:** real
PostgreSQL at natural READ COMMITTED (all three stores) and at SERIALIZABLE for execution claims and
approval receipts is asserted strictly — zero errors, exact expected admission count. Real MySQL and
MariaDB (both report as Laravel's `mysql` driver; no runtime distinction between them is drawn
anymore, since both show the same pattern) and PostgreSQL SERIALIZABLE rate limits specifically are
asserted the way `concurrencyTestIsKnownFlaky()` describes: the invariant must hold among non-throwing
responses, and every exception must be the expected, bounded SQLSTATE 40001 — never a hard zero-error
assertion, because it would not reliably be true.

**In every measured case, across both correction rounds, the invariant held among responses that
didn't throw.** No test at any point observed a wrong answer — more than the configured limit
admitted, more than one claim winner, more than one approval issued. Every residual gap found is an
availability gap (an unhandled exception where a clean answer was expected), never a correctness one.

## Update (#92): jittered retry resolves the MySQL/MariaDB residual

#86 delegated a second attempt to Laravel's `Connection::transaction($callback, 2)`, which retries
immediately. #92 instead makes one bounded retry outside that transaction call after a randomized
10–50 ms delay. The store methods retain their independent-transaction guard, so Verdict still never
retries an application-owned outer transaction.

Using the corrected ready/release harness and isolated databases, five repeated 20-way runs on each
of MySQL 8 and MariaDB 11 completed cleanly for rate limits, execution claims, and approval receipts.
The durable test now asserts those natural-isolation results strictly. PostgreSQL READ COMMITTED and
the PostgreSQL SERIALIZABLE claim/receipt results remain clean; the measured PostgreSQL SERIALIZABLE
rate-limit availability gap remains, is still disclosed in `docs/limitations.md`, and is tracked in
[#97](https://github.com/fissible/verdict/issues/97).

## Consequences

- The operational risk is real and current, not hypothetical: it is documented in
  `docs/limitations.md` rather than left implicit, per this repo's practice of naming a known gap rather
  than letting a contributor discover it and mistake it for an oversight (the same reasoning ADR 0006
  used for the streaming approval gap before #22 fixed it).
- [#86](https://github.com/fissible/verdict/issues/86) implemented and measured the retry fix (see
  Update above), at higher priority than #37's own "M, no urgency" framing, because #37 changed the
  finding from "unmeasured, assumed fine" to "measured, confirmed unsafe on the isolation level most
  MySQL/MariaDB deployments run by default."
- CI adds PostgreSQL to every PR alongside SQLite (`postgres` job in `tests.yml`), and a full driver
  matrix — PostgreSQL, MySQL, MariaDB — on tag push and weekly schedule
  (`concurrency-matrix.yml`). `tests/Feature/SecurityStateConcurrencyRetryTest.php` (added in #86)
  self-skips against SQLite and runs for real whenever any of those jobs execute, so the every-PR
  PostgreSQL job exercises the SERIALIZABLE-class findings on every PR, while MySQL and MariaDB
  natural-isolation coverage runs on tag/weekly — matching #37's original Part 3 cost/benefit
  tradeoff.
- `DatabaseApprovalReceiptStore` is measured, not assumed, across every combination tested: confirmed
  exposed under REPEATABLE READ during #86's initial measurements and resolved by #92's jittered retry;
  it remains fully resolved under PostgreSQL SERIALIZABLE, unlike rate limits' residual gap there.

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
