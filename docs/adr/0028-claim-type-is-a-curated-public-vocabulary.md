# ADR 0028: `claimType` is a curated, per-stage-keyed, additive-only public vocabulary

Status: Accepted

## Related issues

- [#223](https://github.com/fissible/verdict/issues/223) is the work this settles.
- [ADR 0007](0007-evidence-layering.md) — evidence is a record about a decision, never the
  decision's authority. A claim type does not make it one.
- [ADR 0008](0008-evidence-privacy-model.md) — the label is derived from `stage`, `disposition`, and
  two existing metadata fields, and introduces no new data.
- [#225](https://github.com/fissible/verdict/issues/225) depends on these labels being stable: a
  cross-system reference cites a claim type to say what the referenced artifact asserts.
- `docs/evidence-record-identity.md` holds the vocabulary table itself. This ADR fixes the rules the
  table must obey; it is not the table.

## Context

`DecisionEvidence.claimType` states what a record asserts, as one namespaced string. It exists so an
external system citing a Verdict record learns what it substantiates without reading Verdict's
documentation — and, more importantly, cannot mistake an authorization decision for an execution or
a resulting state.

That makes the strings a **public contract**, consumed by systems Verdict cannot see or update. Two
pressures act against that, and both are cheap mistakes to make:

1. **Generating the label mechanically.** `verdict.<stage>.<disposition>` is obvious, self-
   maintaining, and wrong. It publishes Verdict's internal enum names as an external contract, so an
   internal rename silently breaks every reference. And it mints `verdict.execution.permit`, a
   string that reads as "the execution was permitted, therefore it happened" — the overclaim the
   field exists to prevent.
2. **Keying the map on `stage`+`disposition` uniformly.** This was specified, looked obviously
   sufficient, and is not. `execution_claim` + `permit` is emitted **twice** by
   `ExecutionClaimManager`: once when a claim is *admitted*, before the executor is called, and
   again when it *completes*. `approval` + `permit` is emitted at **three** phases, one of which
   spends a single-use receipt. A uniform key would have labelled admission rows `claim-completed`.

Both mistakes have the same shape: a label that describes less than the event, in the direction of
claiming more than Verdict observed.

## Decision

### 1. The map is curated, never mechanically derived

Each label is a judgment about what kind of claim the record makes. Outcomes fold onto one label
where they are the same kind of claim, with the outcome left in `disposition` — every `proposal`
disposition is `verdict.authorization.decision`. They split where they are different kinds — a
rate-limit *consumption* and a rate-limit *refusal* are not one claim.

No code may generate a label from enum values.

### 2. Each stage is keyed on its own discriminating fields

The key is whatever distinguishes that stage's distinct events, not a uniform tuple:

| Stage | Key |
| --- | --- |
| `proposal`, `execution`, `target_refresh`, `rate_limit` | `stage` + `disposition` |
| `approval` | `stage` + `disposition` + `approval_phase` |
| `execution_claim` | `stage` + `disposition` + `execution_claim_status` |

A stage that later records several distinct events behind one key must gain a discriminator, not a
label that covers both.

### 3. A label never implies execution, a downstream receipt, or a resulting state

Verdict observes an authorization decision and an admission. It does not observe the executor's
invocation, its return value, or any downstream effect.

The strongest execution-adjacent label is `verdict.execution.claim-completed`, and its definition
states what it is: Verdict marking *its own* claim complete around a successful return — an
admission-side belief, never a receipt from the executor, carrying no result.

Where the record genuinely cannot distinguish two paths, the label must not pretend to.
`verdict.execution.claim-indeterminate` covers both "this attempt threw after admission" and "a
duplicate was refused against an unresolved claim", because the record carries the claim's *status*,
not the transition outcome that produced it.

### 4. The vocabulary is additive-only

Cases are added. They are not renamed, repurposed, or removed, and a label's meaning is not
narrowed or widened after publication. An external reference to a claim type must not change meaning
under the citing system.

A genuinely different claim gets a new label. If a published label is found to be wrong, the
correction is a new label plus a documented deprecation — not a redefinition.

### 5. Exhaustiveness is enforced by a test, and unreachability is declared from the code

`ClaimTypeVocabularyTest` walks every tuple the evaluation state machine can present and fails
unless each is either mapped or explicitly declared unreachable, and never both. A new stage,
disposition, approval phase, or claim status cannot ship until someone decides what it asserts.

Unreachable tuples are declared from a walk of the code that emits each stage, not from what the
enums permit. `proposal` and `execution` record an application-supplied `CapabilityAuthorizer`
decision, so every disposition is reachable there; the other four stages are minted by Verdict's own
managers and are bounded by what those emit. An "unreachable" claim about application-supplied code
would be a guess.

## Consequences

**A contributor cannot regenerate the map.** Adding an `EvaluationStage` case, a `Disposition` case,
an `ApprovalEvidencePhase`, or an `ExecutionClaimStatus` fails CI until the vocabulary is updated
deliberately. That is the intended cost.

**The vocabulary is larger than a formula would produce**, and grows as stages gain discriminators.
Fifteen labels is not a burden worth trading for a mechanical scheme that would have shipped an
overclaim.

**Six approval labels, not four.** The three validation/consumption *failures* stay distinct rather
than folding into one "confirmation required." `verdict.approval.consumption-failed` is not a
request for a human — it is the signal that a consumed single-use receipt was replayed. Burying a
replay-detection event behind a benign label is the same collapse this ADR exists to prevent, and
the symmetry with the three success labels costs nothing.

**Internal renames stay internal.** `stage` and `disposition` may be renamed without breaking an
external reference, which is the point of decoupling the strings.

## Alternatives rejected

**Mechanical `verdict.<stage>.<disposition>`.** Self-maintaining, and it bakes both failure modes in
at once: internal names in a public contract, and `verdict.execution.permit`.

**One label per stage, with the specifics left to other columns.** Considered for `execution_claim`
as `verdict.execution.claim-transition`. It never overclaims, but it is under-informative at exactly
the admitted-versus-completed line a cross-system chain most needs, forcing every external consumer
back to `execution_claim_status` to answer the question the label exists to answer.

**Folding the label into `recordDigest`.** Rejected: the label is derived from fields already in the
digest, so it is redundant there — and a correction to the vocabulary would change the identity of
records whose content never changed.
