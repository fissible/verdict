# ADR 0005: GuardedTool is a bounded migration bridge, not a security primitive

Status: Accepted

## Related issues

- [#15](https://github.com/fissible/verdict/issues/15) (open) makes `GuardedTool` use visible in evidence so applications can audit migration debt.

## Context

`GuardedTool` (`src/LaravelAi/GuardedTool.php`) wraps an application's existing Laravel AI `Tool`
and runs it through `VerdictManager::run()` after evaluation, but the wrapped tool's `handle()` is
an independent closure. Verdict authorizes a resolved target and then calls that closure; it cannot
prove the closure acts on the same target it authorized. `BoundTool` closes that gap by deriving the
executor's `AuthorizedAction` directly from the refreshed execution target (ADR 0003).

The documentation already records this as an explicit, intentional limitation
([architecture: GuardedTool migration bridge](../architecture.md#guardedtool-migration-bridge)):
`GuardedTool` exists to migrate pre-Verdict tools onto the authorization
boundary without rewriting them, it should not be used for new security-sensitive capabilities,
and `BoundTool` is the primitive for new work. `GuardedTool` also cannot support verified
confirmation or an execution-target policy (ADR 0003's explicit-policy requirement
applies only to `BoundTool`) for the same reason: Verdict cannot bind an independent handler to a
specific target.

The architecture-review backlog raised three questions about this design: can a consumer accidentally
authorize one target while executing a different one, could a runtime assertion detect misuse, and
would a stronger name discourage inappropriate production use.

## Decision

`GuardedTool` remains a deliberately narrower primitive than `BoundTool`, and that gap is not a
defect to be engineered away:

- Verdict cannot make `GuardedTool`'s independent handler prove it operated on the authorized
  target without requiring the same target-binding contract `BoundTool` already provides — at which
  point it would simply be `BoundTool`. There is no assertion that closes this gap while preserving
  `GuardedTool`'s actual purpose (wrapping a handler Verdict does not control).
- The name is not changed. `GuardedTool` is already paired with `BoundTool` and an explicit
  `[!WARNING]` block at its first mention; a longer or more alarming name does not add information
  a reader hasn't already been given at the point of use, and every existing README example already
  presents `BoundTool` first as the preferred primitive.
- Misuse is not currently observable at runtime: `AbstractVerdictTool::handle()` (shared by both
  `GuardedTool` and `BoundTool`) records ordinary `DecisionEvidence` through `VerdictManager::record()`
  either way, with no signal distinguishing a `GuardedTool` execution from a `BoundTool` execution in
  evidence. A follow-up issue should add that signal (see the linked issue below) so an application
  can audit its own migration debt and flag `GuardedTool` usage that never got migrated, rather than
  Verdict trying to prevent the pattern outright.

## Consequences

- `GuardedTool` continues to authorize-then-delegate; applications remain responsible for verifying
  that a wrapped handler only acts on the resolved target, exactly as they would for any
  Verdict-unaware Laravel AI tool.
- New capabilities should use `BoundTool`. Documentation, not runtime enforcement, is the mechanism
  that steers new work there.
- A follow-up issue adds an evidence-visible signal (e.g. a metadata flag or log line) so
  `GuardedTool` usage is auditable in aggregate without Verdict refusing to run it.

## Alternatives rejected

### Add a runtime assertion that denies execution if the handler's output looks target-mismatched

Verdict has no reliable way to infer what resource an arbitrary closure acted on from its return
value. A heuristic check would be either trivially bypassable or would reject legitimate handlers,
and either failure mode is worse than the current explicit, documented gap.

### Rename `GuardedTool` to something more alarming (e.g. `UnsafeLegacyTool`)

The class is already flanked by an explicit warning at every point a developer encounters it in the
README, and Verdict has no tagged 1.0 release yet to make a rename a compatibility concern. A scarier
name does not change what the class can enforce; it only risks reading as a value judgment about a
legitimate, temporary migration path.

### Require `BoundTool`-style execution-target policies on `GuardedTool` too

This would require `GuardedTool` to bind its independent handler to a specific target, which is
exactly the guarantee it cannot make. Requiring the policy without being able to enforce it would
be misleading, not safer.
