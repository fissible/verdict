# ADR 0010: Future semantic limit meters — design proposal, not implemented

Status: Proposed

## Context

ADR 0001 implements exactly one meter: authorized execution attempts per fixed window, consumed
"after execution-stage authorization and successful non-mutating approval validation." The pre-v0.2
README listed further meters as directional roadmap items without a design:

```text
Proposal attempts per principal and capability.
Denied cross-resource attempts.
Successful executions per resource.
Tool calls or planner calls per conversation.
Token or cost budgets per tenant.
Cumulative risk across individually permitted operations.
```

The architecture-review backlog asked Verdict to distinguish authorized attempts, successful
executions, provider tokens, monetary spend, and cumulative risk as design questions, explicitly
without implementing any of them. This ADR is that design proposal. It is deliberately **Proposed**,
not **Accepted** — it does not authorize implementation work, and the "Alternatives rejected" section
below identifies decisions this proposal defers rather than makes.

## Design proposal

The six roadmap bullets are not one meter type with different names; they are at least three
different kinds of counter with different consumption points and different failure semantics, and a
future implementation should not force them through ADR 0001's single `RateLimitPolicy` shape:

1. **Attempt meters** (proposal attempts, denied cross-resource attempts). These count *evaluations*,
   not authorized executions — the opposite of ADR 0001's current attempt meter, which deliberately
   excludes policy denials and pending confirmations from consumption. An attempt meter that also
   counts denials exists to catch enumeration/probing behavior (repeated denied attempts against
   different resource IDs), not to bound legitimate load. It should consume at `EvaluationStage::
   Proposal` regardless of disposition, which is a different hook point than ADR 0001's post-
   authorization consumption.

2. **Outcome meters** (successful executions per resource, tool/planner calls per conversation).
   These count *what actually happened*, which ADR 0001 explicitly does not: "A consumed unit
   represents an execution attempt, not a guaranteed successful side effect." An outcome meter needs
   a consumption point *after* the executor returns — most naturally alongside
   `ExecutionClaimManager::complete()` (`src/ExecutionClaims/ExecutionClaimManager.php:62-77`) for
   capabilities that opt into execution claims, and via a new post-execution hook in
   `VerdictManager::executeAfterRateLimit()` for capabilities that don't.

3. **Cumulative/value meters** (token or cost budgets, cumulative risk). These are not fixed-window
   counters at all — they need an accumulating balance, not a reset-on-window count. This is the
   category where the "provider token telemetry" rejection matters (see
   [ADR 0012](0012-rejected-verdict-does-not-own-token-telemetry.md)): Verdict should not become the
   system of record for token usage or dollar cost. If Laravel AI or the provider adapter already
   reports usage/cost on a response object, a cumulative meter can be a policy that *consumes a
   reported value* the same way ADR 0001's meter consumes a fixed unit — but Verdict is consuming a
   number it was told, not measuring or billing anything itself. Cumulative risk is the least defined
   of the six bullets and should not be scheduled for implementation until a concrete risk model
   exists to consume from (this is adjacent to the post-v0.2 exploratory communications-risk track,
   which already treats risk scoring as a separately-scoped concern).

A future implementation should therefore add at most one new policy type per meter category above
(attempt, outcome, cumulative), not six independent meters, and each should state its own consumption
point the way ADR 0001 and ADR 0002 each state theirs precisely.

## Open questions this proposal does not resolve

- Whether attempt and outcome meters share `RateLimitPolicy`'s storage/binding shape or need their
  own contract.
- Whether an outcome meter's post-execution consumption point should be a new `EvaluationStage` case
  (evidence-visible, consistent with every other consumption point) or an out-of-band counter.
- Where a cumulative/value meter's balance resets, if ever, and what "budget exceeded" should do to
  an in-flight multi-step agent conversation.
- Whether cumulative risk needs any implementation before the communications-risk track defines what
  a `RiskAssessment` actually contains.

## Consequences

- No code changes result from this ADR.
- A future issue implementing any one meter category should reference this ADR's category split
  rather than re-deriving it, and should keep the categories in separate implementation slices the
  way ADR 0003 explicitly separated Slice 1 (target freshness) from Slice 2 (transactional execution).

## Alternatives rejected

### One generic `SemanticLimitPolicy` covering all six bullets with a configurable consumption point

Rejected because the three categories above have genuinely different failure semantics (an attempt
meter should count denials; ADR 0001's attempt meter explicitly must not). A single configurable
policy would let a misconfiguration silently turn an outcome meter into an attempt meter or vice
versa, which is a security-relevant mistake, not a cosmetic one.

### Implement token/cost budgets now, since Laravel AI already reports usage data

Rejected for this ADR's scope: the backlog item explicitly asked for a design proposal, not
implementation, and the ownership question in ADR 0012 (Verdict consumes reported values; it does not
collect or own telemetry) needs to be settled first so an implementation doesn't quietly grow into a
telemetry system.
