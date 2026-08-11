# Isolation-level concurrency spike (#37)

Throwaway-by-design, hand-run scripts answering ADR 0016 Decision §6's open question. Not part of
`composer test` — do not add these to `phpunit.xml.dist`.

## Running

`set -o pipefail` is required before the `| tee` commands below: `tee` itself always exits 0
regardless of the left side of the pipe, so without `pipefail` the shell reports success even when
a spike's own assertions fail. Both scripts exit nonzero on a failed invariant (`spike-a.php` on any
driver mismatch, `spike-b.php` only on a genuinely wrong answer among non-throwing responses — an
observed `SQLSTATE 40001` itself is expected data, not a script failure).

    set -o pipefail
    docker compose -f docker-compose.spike.yml up -d
    # wait for all four services to report healthy: docker compose -f docker-compose.spike.yml ps
    php spikes/0037-isolation-level-concurrency/bootstrap.php
    php spikes/0037-isolation-level-concurrency/spike-a.php | tee spikes/0037-isolation-level-concurrency/results/spike-a-$(date +%Y%m%d).txt
    php spikes/0037-isolation-level-concurrency/spike-b.php | tee spikes/0037-isolation-level-concurrency/results/spike-b-$(date +%Y%m%d).txt
    docker compose -f docker-compose.spike.yml down -v

## What each script does

- `bootstrap.php` — creates the three Verdict security-state tables on all four running databases.
- `spike-a.php` — driver equivalence: N concurrent processes racing `DatabaseRateLimitStore::consume()`
  against one bucket, `DatabaseExecutionClaimStore::claim()` against one binding, and
  `DatabaseApprovalReceiptStore::issue()` against one `(tool_call_id, capability, binding_fingerprint)`
  triple — all three security-state stores ADR 0016 names, on each of the four driver configs. Every
  batch of concurrent children is held at a shared wall-clock start barrier (see
  `START_BARRIER_BUFFER_SECONDS`) so they reach the store call as close to simultaneously as process
  scheduling allows, rather than spreading out across each process's independent boot/connect latency.
  Asserts the invariant holds (exactly `limit` admissions, exactly one claim winner, exactly one
  approval issuer) on every driver; exits nonzero if any driver fails.
- `spike-b.php` — SERIALIZABLE behavior: the same three races, but only against PostgreSQL with the
  transaction isolation level forced to `SERIALIZABLE`, recording whether SQLSTATE 40001 is raised, in
  which store, at what contention level, and whether it is currently handled or surfaces as an
  unhandled exception. Also uses the same start barrier. Does not grade pass/fail on 40001 itself
  (expected, recorded data), but exits nonzero if the underlying invariant is ever violated among
  responses that *don't* throw — that would mean a wrong answer, not just an exception.

Results are `results/*.txt` (gitignored — paste into the GitHub issue and the follow-on ADR, don't
commit loose transcripts) plus a permanent summary in `docs/adr/0018-*.md` once written.
