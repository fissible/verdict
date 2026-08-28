# Security-state concurrency benchmarks

This document records a point-in-time contention benchmark for Verdict's durable security-state
stores. It is not a capacity guarantee and recommends no optimization. Its purpose is to make the
cost of the locking and unique-key paths in the at-most-once, semantic-rate-limit, and approval
flows visible.

## Scope and method

The benchmark exercised the production `DatabaseExecutionClaimStore::claim()`,
`DatabaseRateLimitStore::consume()`, and `DatabaseApprovalReceiptStore::issue()` methods. It used
the checked-in table migrations and the ready/release process barrier in
`tests/Support/ConcurrencyHarness.php`:

- each contender was a separate PHP CLI process with its own PDO connection;
- all contenders forced their connection, signalled readiness, and were released together;
- a *shared-key* batch gave every contender one claim fingerprint, rate-limit bucket, or approval
  receipt binding; a *distinct-key* batch gave each contender a distinct one;
- writer counts were 1, 5, and 20; each cell repeated five batches, for 5, 25, and 100 operations
  respectively; and
- p50/p95/p99 are individual store-call latency in milliseconds, measured after the barrier. Batch
  throughput includes process creation, connection setup, and barrier coordination, so it is a
  conservative end-to-end harness figure rather than a steady-state in-process throughput claim.

The checked-in [`scripts/benchmark-security-state.php`](../scripts/benchmark-security-state.php)
driver and its child script produced these rows. Every response was parsed as the child process's
JSON result. `err` means a terminal child failure after the store's bounded retry policy; it does
not mean a retryable database conflict did not occur. Callers must still handle a failure that
survives the retry budget. Rate-limit batches used a limit of 100, so all successful calls were
admissions; the result is a lock/insert benchmark, not a denial-path benchmark.

Shared-key rate-limit batches exercise contention on the bucket. Shared-key execution-claim and
approval-receipt batches instead primarily measure their duplicate-result paths after one writer
creates the row; do not interpret them as pure lock-contention throughput.

## Environment

Measured 2026-08-12 UTC from commit `6f996fa`, on macOS 14.5 (Apple M2 Pro, 10 CPU cores), PHP 8.4.24.
SQLite used a fresh file-backed database in `/private/tmp`. MySQL was MySQL 8.4.11 in the checked-in
`docker-compose.spike.yml` service (`mysql-repeatable-read`, port 3307) at its reported
`REPEATABLE-READ` isolation. The benchmark used fresh random keys in every batch and recreated the
three Verdict tables before the run.

To reproduce the engine setup, use the same compose file, wait for health checks, and run an
equivalent separate-process batch against the production stores and current migration stubs:

```sh
docker compose -f docker-compose.spike.yml up -d --wait mysql-repeatable-read
docker compose -f docker-compose.spike.yml ps
php scripts/benchmark-security-state.php sqlite > sqlite-results.json
php scripts/benchmark-security-state.php mysql > mysql-results.json
```

Use a file-backed SQLite database (not `:memory:`) for the SQLite arm: each process must connect to
the same file. Keep the barrier and response protocol from `ConcurrencyHarness`; sequential calls,
or calls sharing one connection, do not measure the locking path described here.

## Results

`ops/s` is batch throughput. `err` is the count of terminally failed child results. Latencies are
milliseconds. The 1-writer cells have only five samples and the 5-writer cells only 25, so their
p95/p99 values are order-statistic maxima, not tail-latency estimates.

### SQLite

| Store | Keys | Writers | Ops | err | ops/s | p50 | p95 | p99 |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Rate limit | shared | 1 | 5 | 0 | 13.5 | 4.41 | 7.69 | 7.69 |
| Rate limit | shared | 5 | 25 | 0 | 41.4 | 24.86 | 50.27 | 56.73 |
| Rate limit | shared | 20 | 100 | 0 | 61.8 | 39.84 | 105.77 | 121.66 |
| Rate limit | distinct | 1 | 5 | 0 | 14.1 | 4.37 | 4.59 | 4.59 |
| Rate limit | distinct | 5 | 25 | 0 | 33.8 | 31.12 | 61.85 | 94.08 |
| Rate limit | distinct | 20 | 100 | 0 | 63.5 | 32.11 | 95.03 | 130.74 |
| Execution claim | shared | 1 | 5 | 0 | 14.1 | 4.59 | 5.67 | 5.67 |
| Execution claim | shared | 5 | 25 | 0 | 42.7 | 31.96 | 49.60 | 54.59 |
| Execution claim | shared | 20 | 100 | 0 | 81.4 | 12.64 | 25.94 | 41.78 |
| Execution claim | distinct | 1 | 5 | 0 | 14.1 | 4.70 | 4.84 | 4.84 |
| Execution claim | distinct | 5 | 25 | 0 | 38.4 | 22.71 | 53.17 | 77.14 |
| Execution claim | distinct | 20 | 100 | 0 | 61.2 | 36.42 | 102.99 | 128.19 |
| Approval receipt | shared | 1 | 5 | 0 | 13.8 | 5.09 | 5.78 | 5.78 |
| Approval receipt | shared | 5 | 25 | 0 | 42.0 | 23.83 | 50.78 | 57.87 |
| Approval receipt | shared | 20 | 100 | 0 | 76.1 | 13.56 | 23.03 | 56.64 |
| Approval receipt | distinct | 1 | 5 | 0 | 14.1 | 4.82 | 5.04 | 5.04 |
| Approval receipt | distinct | 5 | 25 | 0 | 39.0 | 35.40 | 57.01 | 62.85 |
| Approval receipt | distinct | 20 | 100 | 0 | 65.0 | 37.30 | 83.76 | 124.95 |

### MySQL 8.4 / InnoDB REPEATABLE READ

| Store | Keys | Writers | Ops | err | ops/s | p50 | p95 | p99 |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Rate limit | shared | 1 | 5 | 0 | 11.8 | 14.69 | 20.53 | 20.53 |
| Rate limit | shared | 5 | 25 | 0 | 35.5 | 45.93 | 65.18 | 67.04 |
| Rate limit | shared | 20 | 100 | 0 | 65.7 | 60.35 | 86.14 | 94.91 |
| Rate limit | distinct | 1 | 5 | 0 | 12.6 | 8.18 | 11.59 | 11.59 |
| Rate limit | distinct | 5 | 25 | 0 | 42.7 | 14.87 | 60.22 | 69.26 |
| Rate limit | distinct | 20 | 100 | 0 | 64.1 | 33.02 | 68.59 | 84.52 |
| Execution claim | shared | 1 | 5 | 0 | 13.2 | 8.21 | 11.68 | 11.68 |
| Execution claim | shared | 5 | 25 | 0 | 35.5 | 48.07 | 70.66 | 71.93 |
| Execution claim | shared | 20 | 100 | 0 | 66.5 | 62.67 | 82.40 | 85.78 |
| Execution claim | distinct | 1 | 5 | 0 | 13.0 | 9.31 | 12.39 | 12.39 |
| Execution claim | distinct | 5 | 25 | 0 | 43.9 | 13.48 | 59.70 | 69.33 |
| Execution claim | distinct | 20 | 100 | 0 | 65.7 | 27.59 | 70.67 | 87.20 |
| Approval receipt | shared | 1 | 5 | 0 | 12.9 | 9.04 | 11.52 | 11.52 |
| Approval receipt | shared | 5 | 25 | 0 | 36.0 | 37.83 | 61.17 | 63.45 |
| Approval receipt | shared | 20 | 100 | 0 | 67.2 | 58.14 | 79.44 | 86.45 |
| Approval receipt | distinct | 1 | 5 | 0 | 13.2 | 8.37 | 8.95 | 8.95 |
| Approval receipt | distinct | 5 | 25 | 0 | 48.5 | 13.27 | 40.40 | 43.59 |
| Approval receipt | distinct | 20 | 100 | 0 | 67.3 | 32.33 | 69.36 | 76.37 |

## Interpretation and limits

This run observed no terminally failed child operation on either engine; that does not establish the
absence of retried conflicts. At 20 writers, the shared-key MySQL rate-limit path has a higher
median latency than its distinct-key counterpart, as expected from row locking and unique-key
races. The shared execution-claim and approval-receipt rows are duplicate-path measurements, not
the same comparison. File-backed SQLite also serializes writers at database scope, so none of its
figures are evidence of row-level concurrency. At one writer, both engines' `ops/s` figures are
dominated by PHP process launch and are not a meaningful cross-engine comparison. The numbers
include a local Docker database and process-launch overhead; network distance, server sizing,
connection pooling, schema size, and surrounding application work will change them.

If a deployment needs a throughput or tail-latency commitment, repeat this method with a larger
sample in that deployment-like environment and record the exact hardware, database
version/configuration, and workload. `docker-compose.spike.yml` currently names the floating
`mysql:8` image tag, so record the server version from each run (this run was 8.4.11). Any
optimization motivated by such a result belongs in a separate issue.
