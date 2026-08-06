# ADR 0009: Execution claims are retained indefinitely by default; no automatic pruning

Status: Accepted

## Related issues

- [#16](https://github.com/fissible/verdict/issues/16) (open) measures execution-claim behavior under contention.
- [#20](https://github.com/fissible/verdict/issues/20) (open) adds genuine concurrent-access coverage for execution claims.

## Context

`ExecutionClaimStore` (`src/Contracts/ExecutionClaimStore.php`) durably records every claimed logical
operation so ADR 0002's at-most-once guarantee holds "within the retained claim history." README:617
already states the consequence plainly: "Claim rows are part of the guarantee horizon, so Verdict
provides no automatic pruning command." This is unlike the rate-limit store, which *does* need
pruning because expired buckets are unbounded state with no ongoing meaning (ADR 0001: "Expired
database buckets require pruning. Verdict provides a pruning command; applications choose its
schedule.").

The architecture-review backlog asked about archival, pruning, retention, and verification-after-
pruning for execution claims. This ADR makes explicit why claims are not treated like rate-limit
buckets, and what an application must do if it needs to bound their storage anyway.

## Decision

Execution claim rows are retained indefinitely by default, and Verdict ships no pruning command for
them, because deleting a claim row changes the guarantee it exists to provide:

- A claim's entire purpose is to be found by a *later* attempt at the same logical operation
  (ADR 0002: "Only the first caller to claim a logical operation may enter the executor. Concurrent
  or later duplicates are denied whether the original claim is active, completed, or indeterminate.").
  Deleting a completed claim does not free storage safely — it reopens the exact admission window
  ADR 0002 exists to close. A duplicate delivery arriving after that row is pruned would be admitted
  as if it were the first attempt.
- Unlike a rate-limit bucket, a claim's usefulness does not expire on a schedule. A rate-limit window
  resets by design; nothing about a logical operation's identity "expires" the same way. Transport
  redelivery (the exact case ADR 0002 defends against) can arrive arbitrarily late.

An application that needs to bound execution-claim storage growth is making a retention-policy
tradeoff Verdict cannot make on its behalf: it must decide how long a duplicate delivery is plausible
for its own transport and downstream systems, and accept that pruning a claim before that window
closes reopens the admission gap for exactly the deliveries the guarantee was meant to catch. If an
application accepts that tradeoff:

1. Archive (not delete) claims older than the application's chosen window to cold storage before
   removing them from the live table, preserving the ability to investigate a very late duplicate
   manually even after pruning.
2. Never prune a claim in `Claimed` or `Indeterminate` status — only `Completed` or `Released` claims
   are candidates, and only after operator investigation per the existing
   `verdict:resolve-execution-claim` workflow (README:605-617).
3. Document the chosen window in the application's own operational runbook; Verdict has no way to
   validate that an application-chosen window is sound for that application's transport.

Verdict does not provide tooling for step 1 or a "verify after pruning" command, because both require
knowing what an application's downstream duplicate-delivery window actually is — this ADR states the
constraint; it does not implement archival tooling.

## Consequences

- No pruning command is added to `src/Console/Commands/` for execution claims.
- Applications that need bounded claim storage own the archival decision described above; this is a
  README documentation addition, not a code change.
- This ADR does not change `ExecutionClaimManager` or either store implementation.

## Alternatives rejected

### Add a time-based pruning command analogous to rate-limit bucket pruning

Rejected because a claim's validity does not expire on a schedule the way a rate-limit window does;
pruning by age alone would silently reopen ADR 0002's admission guarantee for any duplicate delivered
after the chosen age, with no way for Verdict to know whether that age is safe for a given
application's transport.

### Add a TTL/lease to claims so they expire and are pruned automatically

ADR 0002 already rejected leases explicitly: "No lease, timeout, automatic retry, or cached raw
result is provided." A lease reintroduces exactly the ambiguity ADR 0002 was written to avoid — an
expired lease does not tell Verdict whether the original execution succeeded, failed, or is still in
flight.

### Default to deleting `Completed` claims after a fixed short window (e.g. 24 hours)

Rejected as a Verdict default because "short" is a downstream-system property Verdict cannot know.
A payment provider's webhook retry window, a carrier's delivery-confirmation delay, and a queue's
dead-letter retry policy are all different per application and per capability; a single built-in
default would be wrong for some applications while appearing safe for all of them.
