# ADR 0007: Operational state, evidence, and attestation are three distinct layers

Status: Accepted

## Related issues

- [#1](https://github.com/fissible/verdict/issues/1) (closed; delivered) implemented the provenance ledger and evidence records described by this layering model.
- [#11](https://github.com/fissible/verdict/issues/11) (open) proposes the optional tamper-evident evidence recorder.

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
   persistence failures propagate as application faults (the recorder throws), but evidence writes
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

## Consequences

- Future ADRs and issues that touch evidence (schema, privacy, chain topology — see issue #11) should
  cite this ADR for why they don't also touch operational-state guarantees, and vice versa.
- A contributor proposing to make evidence writes participate in ADR 0004's transaction guard should
  read this ADR first: that guard exists because rolling back operational state can let an
  unauthorized operation execute; rolling back an evidence write cannot, because the write already
  happened after the operational decision was made.
- This ADR does not implement anything. It formalizes a boundary the code already has.

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
