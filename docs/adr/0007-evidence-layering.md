# ADR 0007: Operational state, evidence, and attestation are three distinct layers

Status: Accepted

## Related issues

- [#1](https://github.com/fissible/verdict/issues/1) (closed; delivered) implemented the provenance ledger and evidence records described by this layering model.
- [#11](https://github.com/fissible/verdict/issues/11) (open) proposes the optional tamper-evident evidence recorder.
- [#149](https://github.com/fissible/verdict/issues/149) (open) found that this ADR's decision point 2
  states two rules that collide on the post-execution path; see the Update below for the resolution.

## Context

Verdict currently persists data across three conceptually different stores, and nothing states the
boundary between them as a single design decision:

- **Operational state** — `approval_receipts`, `rate_limits`, `execution_claims`. These are
  authoritative security-state stores that gate execution itself. ADR 0004 requires their mutating
  operations to fail closed on an unsafe outer transaction, because a rollback erasing this state
  would let a duplicate or unapproved operation execute.
- **Evidence** — `DecisionEvidence`, `ContextReleaseEvidence`, `ProvenanceEntry`, written through the
  `EvidenceRecorder` contract (`src/Contracts/EvidenceRecorder.php`). This is a record *about* a
  decision, not the decision's authority. ADR 0004 explicitly excludes `DatabaseEvidenceRecorder`
  from its transaction guard: "evidence persistence and transaction ownership are application
  policy, and evidence is not itself an authorization gate."
- **Attestation** — implemented as `AttestEvidenceRecorder`, an opt-in recorder delivered by issue #11
  and backed by `fissible/attest`.
  [Limitations: tamper-evident evidence is opt-in, partial, and bounded by key custody](../limitations.md#tamper-evident-evidence-is-opt-in-partial-and-bounded-by-key-custody)
  states that `DatabaseEvidenceRecorder` remains "an ordinary mutable audit store... not append-only,
  immutable, signed, or tamper-evident," and records what the attested alternative does and does not
  cover.

These layers already have different guarantees and different owners in the code, but a reader has to
assemble that from ADR 0004's exclusion clause, the README's evidence caveats, and issue #11 rather
than from one place.

## Decision

Verdict treats these as three layers with different failure semantics, and new work must not
conflate them:

1. **Operational state is authoritative and gates execution.** Losing or corrupting it changes what
   Verdict permits. It is protected by ADR 0004's transaction guard and by each store's own atomic
   consume/claim semantics (ADR 0001, 0002).
2. **Evidence is a record about a decision, not the decision itself.** Losing evidence does not
   change what already executed; it changes what can later be investigated or audited. Evidence
   persistence failures propagate as application faults (the recorder throws) — **scoped by the
   Update (#149) below, which excludes the post-execution path where propagating would violate this
   bullet's own first sentence** — but evidence writes
   are not wrapped in the same fail-closed transaction guard as operational state, and evidence
   recorder selection, retention, and encryption remain application policy
   ([limitations: tamper-evident evidence is opt-in, partial, and bounded by key custody](../limitations.md#tamper-evident-evidence-is-opt-in-partial-and-bounded-by-key-custody)).
3. **Attestation is a property evidence can optionally gain, not a replacement for either layer
   above.** An attested evidence record is still evidence — it answers "was this evidence tampered
   with after the fact," not "was this operation authorized." Issue #11's `AttestEvidenceRecorder`
   is an `EvidenceRecorder` implementation, not a new operational-state store or a new authorization
   gate. A chain-verification failure in `attest` should not be conflated with a `Decision::deny(...)`
   from `VerdictManager`.

A corollary: an attested evidence recorder does not retroactively make operational-state stores
tamper-evident. If an application needs tamper-evidence over *why* an operation was permitted (not
just that it was authoritatively permitted), that requirement lives in the evidence layer via issue
#11, not by asking the approval/rate-limit/execution-claim stores to grow signing behavior of their
own.

## Update (#149): the propagation rule is scoped, because after a successful executor it contradicts the layer it belongs to

Decision point 2 states two rules. Both are correct in isolation, and they collide on exactly one path.

- *"Losing evidence does not change what already executed."*
- *"Evidence persistence failures propagate as application faults (the recorder throws)."*

The collision is in `VerdictManager::executeAfterRateLimit()`. After the executor has returned
successfully, Verdict finalizes the execution claim and records that finalization as evidence. At that
point the only channel back to the caller is an exception — and an exception raised by an evidence write
is indistinguishable from one raised by the executor. A caller who receives it concludes the side effect
did not happen. It did.

So on that path, applying the second rule produces precisely the outcome the first rule forbids: the loss
of an evidence write changes what the caller believes about an execution that already completed.

**Resolution.** The propagation rule is scoped rather than abandoned. It holds wherever the evidence write
precedes or accompanies the decision it records, which is every call site except one: an evidence-write
failure occurring **after a successful executor** must not propagate to the caller. It is surfaced as a
failure event instead, in the shape `Fissible\Verdict\Evidence\Events\ChainWriteFailed` already
establishes.

**Why the principle wins and the mechanism yields.** "Failures propagate" was a statement about how
evidence failures surface — that they are not silently swallowed — not a claim that they may misrepresent
execution. Layer 2 exists to say that evidence is a record *about* a decision and not the decision itself.
A record's failure cannot be permitted to rewrite the caller's understanding of the thing being recorded;
that would make evidence authoritative over execution, which is layer 1's role and explicitly not
evidence's. Scoping the mechanism preserves both sentences. Preserving the mechanism unscoped would
require deleting the first sentence, and with it the reason this layer is separate at all.

**What does not change.**

- Operational-state failures on the same path still propagate, and must. A claim transition that fails
  means security state and reality have diverged, which is layer 1 behaving exactly as decision point 1
  requires. #149 introduces a dedicated exception for that case carrying the executor's output, so the
  caller can reconcile.
- Every evidence call site other than post-execution finalization keeps the original propagation
  behavior.
- Nothing here brings evidence writes inside ADR 0004's transaction guard. The rejection in
  "Alternatives rejected" below stands unchanged.

**Where the hazard does not apply, and why that is now enforced.** `ContextReleaseManager::release()` also
writes evidence next to a completed transformation, and looks like the same shape. It is not, for a reason
worth stating because it is contingent: the projected and redacted payload leaves that method only through
the `ContextReleaseResult` it returns, which is an inert value object, and the method has no dispatch,
callback, or other emission. So a throw from `recordRelease()` genuinely *prevents* the release rather than
misreporting one that already happened — the caller never receives the payload. `permitted: true` likewise
stays accurate, because it records that policy permitted the release, not that a caller received it.

That property is what clears this path, and nothing in the code declared it. A future change adding a
notification or callback to `release()` would silently convert it into an instance of the hazard, and the
reasoning that cleared it would not be anywhere a reviewer would look.
`tests/Unit/ContextReleaseSideChannelArchitectureTest.php` now asserts it in both directions: that the
release path emits nothing, and that `ContextReleaseResult` stays inert.

**How this went unnoticed.** The asymmetry was visible in the code before it was visible in the ADR: the
executor-failure path already wrapped finalization failures in `ExecutionClaimFinalizationFailed` so a
caller could distinguish them, while the success path — the more dangerous of the two to get wrong — had
no equivalent. The ADR's two sentences were read separately for long enough that the one path where they
disagree was never tested against either.

## Consequences

- Future ADRs and issues that touch evidence (schema, privacy, chain topology — see issue #11) should
  cite this ADR for why they don't also touch operational-state guarantees, and vice versa.
- A contributor proposing to make evidence writes participate in ADR 0004's transaction guard should
  read this ADR first: that guard exists because rolling back operational state can let an
  unauthorized operation execute; rolling back an evidence write cannot, because the write already
  happened after the operational decision was made.
- This ADR does not implement anything. It formalizes a boundary the code already has. The Update (#149)
  is the one place where it now states behavior the code does not yet have; #149 implements it.
- A change that surfaces a failure to a caller should state which layer the failure belongs to. Where an
  operational-state failure and an evidence failure can occur in the same block, they need distinguishable
  outcomes — indistinguishable ones let the weaker layer speak for the stronger.

## Alternatives rejected

### Fold evidence into the same transactional guarantee as operational state

Considered and effectively already rejected by ADR 0004 ("Guard the evidence recorder and pruning
command too" → rejected: "those writes do not admit protected execution"). This ADR restates that
rejection at the architecture level so it isn't only visible inside ADR 0004's alternatives section.

### Treat attestation as a fourth, separate store rather than an `EvidenceRecorder` implementation

A separate attestation store would duplicate every decision Verdict already records through
`EvidenceRecorder` and create two evidence trails that could disagree with each other. Issue #11's
adapter pattern keeps evidence single-sourced: the recorder implementation changes, not the shape or
call sites of what gets recorded.
