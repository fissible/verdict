# Isolation-level concurrency spike (#37)

Throwaway-by-design, hand-run scripts answering ADR 0016 Decision §6's open question. Not part of
`composer test` — do not add these to `phpunit.xml.dist`.

## Running

    docker compose -f docker-compose.spike.yml up -d
    # wait for all four services to report healthy: docker compose -f docker-compose.spike.yml ps
    php spikes/0037-isolation-level-concurrency/bootstrap.php
    php spikes/0037-isolation-level-concurrency/spike-a.php | tee spikes/0037-isolation-level-concurrency/results/spike-a-$(date +%Y%m%d).txt
    php spikes/0037-isolation-level-concurrency/spike-b.php | tee spikes/0037-isolation-level-concurrency/results/spike-b-$(date +%Y%m%d).txt
    docker compose -f docker-compose.spike.yml down -v

## What each script does

- `bootstrap.php` — creates the three Verdict security-state tables on all four running databases.
- `spike-a.php` — driver equivalence: N concurrent processes racing `DatabaseRateLimitStore::consume()`
  against one bucket, and separately racing `DatabaseExecutionClaimStore::claim()` against one binding,
  on each of the four driver configs. Asserts the invariant holds (exactly `limit` admissions, exactly
  one claim winner) on every driver.
- `spike-b.php` — SERIALIZABLE behavior: the same race, but only against PostgreSQL with the
  transaction isolation level forced to `SERIALIZABLE`, recording whether SQLSTATE 40001 is raised, in
  which store, at what contention level, and whether it is currently handled or surfaces as an
  unhandled exception.

Results are `results/*.txt` (gitignored — paste into the GitHub issue and the follow-on ADR, don't
commit loose transcripts) plus a permanent summary in `docs/adr/0018-*.md` once written.
