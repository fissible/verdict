# Evidence record identity

A decision-evidence record carries two derived fields that give it an identity of its own:
`claimType` (what it asserts) and `recordDigest` (which exact record it is). Both are computed by
Verdict, with no dependency on `fissible/attest`.

Before these existed, a record's only cryptographic identity was Attest's hash chain. That coupled
*"can another system reference this specific decision"* to *"did this deployment adopt Attest."*
Identity (semantic, Verdict's) and integrity (cryptographic, Attest's) are now separate concerns:
Verdict mints the identity, and Attest — when enabled — protects it.

## `recordDigest`

```
canonicaljson-sha256:9f2b…
```

`sha256` over [`CanonicalJson`](../src/Evidence/CanonicalJson.php) of the record's stable fields,
stored **scheme-tagged**. The field list is
[`RecordDigest::stableFields()`](../src/Evidence/RecordDigest.php); it is public because
reproducibility is the point. A third party holding that list and the canonicalization rule
re-derives the digest offline, without Attest and without Verdict's recorder.

**What it composes.** Existing fingerprints, enums, and scalars only. The digest introduces no raw
or sensitive value. It is exactly as guessable as its inputs — correlation, not anonymization, the
same property [ADR 0008](adr/0008-evidence-privacy-model.md) states for every fingerprint.

**Two deliberate exclusions.**

- **`reason`** is operator-facing and application-controllable, the record's one free-text field.
  Folding it in would let an application change a record's identity by rewording a message.
- **`claimType`** is derived from fields already in the list. Including it would be redundant, and
  would make a correction to the vocabulary change the identity of records whose content never
  changed.

**The idempotency key enters as its fingerprint, never raw** — that is what the row persists, so a
digest over the raw value could not be re-derived from a stored record.

**`recordedAt` enters as UTC at second precision.** The evidence table stores it as a `timestamp`,
whose sub-second precision differs across supported databases; a digest over microseconds could not
be re-derived from the row Verdict itself wrote. The consequence is deliberate: two records
identical in every stable field, written within the same second, share a digest. They are the same
claim. The digest is a *content* identity — the table's UUID primary key remains what distinguishes
rows.

**Why the scheme tag.** A consumer re-deriving a digest cannot silently apply the wrong rule, and a
future canonicalization would be additive (`jcs-sha256:…`) rather than a breaking re-identity of
every record already published. A change to the canonicalization *or* to the stable field set
requires a new scheme.

### What Attest does, and does not, do

`AttestEvidenceRecorder` places `record_digest` in the payload it hands Attest, so Attest's
signature **covers** the digest.

It does not, and cannot, sign that value directly. Attest hashes its own envelope — `v`, `id`,
`chain`, `seq`, `ts`, `type`, `payload`, `prev_hash`, `key_id`, `sig_alg` — over its own RFC 8785
encoder. Verdict never computes those bytes, so no Verdict-side digest can equal them in any
canonicalization. Attest protects the identity; it does not define it.

This is also why Verdict does not adopt JCS for the digest. `fissible/attest` is `require-dev` and
is never called from core, so JCS would mean a second canonicalization scheme in Verdict core
permanently, with its own tests and a standing question about which boundary uses which — for a
byte-equality that is unreachable anyway.

## `claimType`

A stable, namespaced label saying what the record asserts, so an external reader cannot mistake an
authorization decision for an execution or a resulting state.

The rules this vocabulary obeys — curated never mechanical, keyed per stage, additive-only, and never
implying execution — are fixed in
[ADR 0028](adr/0028-claim-type-is-a-curated-public-vocabulary.md).

**This is a public, versioned, additive-only vocabulary.** The strings are decoupled from the
internal `stage`/`disposition` names on purpose: an internal rename must not silently break an
external reference. Cases are added; they are not repurposed or removed.

**The ceiling.** No label claims an operation happened, that a downstream system committed, or what
the resulting state was — Verdict observes none of those. The strongest execution-adjacent label is
`verdict.execution.claim-completed`, and it records Verdict marking *its own* claim complete around
a successful return: an admission-side belief, never a receipt from the executor, carrying no
result.

### The vocabulary

| `claimType` | Emitted for | Asserts |
| --- | --- | --- |
| `verdict.authorization.decision` | `proposal` + any disposition | An authorizer decided about a proposed action. The outcome is in `disposition`. |
| `verdict.execution.admission` | `execution` + any disposition | An action was admitted to, or refused admission to, its executor. Not that it ran. |
| `verdict.approval.proposal-validated` | `approval` + `permit` + `proposal_validation` | A receipt satisfied validation at the proposal gate. |
| `verdict.approval.execution-validated` | `approval` + `permit` + `execution_validation` | A receipt satisfied re-validation at the execution gate. |
| `verdict.approval.receipt-consumed` | `approval` + `permit` + `consumption` | A single-use receipt was spent and can authorize nothing further. |
| `verdict.approval.proposal-validation-failed` | `approval` + `require_confirmation` + `proposal_validation` | Proposal-gate validation did not accept the receipt; a human confirmation is required. |
| `verdict.approval.execution-validation-failed` | `approval` + `require_confirmation` + `execution_validation` | Execution-gate re-validation did not accept the receipt. |
| `verdict.approval.consumption-failed` | `approval` + `require_confirmation` + `consumption` | A receipt could not be spent — the signal a replay of a consumed receipt produces. |
| `verdict.target.refresh` | `target_refresh` + `permit`/`deny` | The execution-time target identity was compared against the proposal target. `disposition` carries whether they matched. |
| `verdict.rate-limit.consumption` | `rate_limit` + `permit` | A semantic rate-limit budget was consumed by this attempt. |
| `verdict.rate-limit.refusal` | `rate_limit` + `throttle` | A semantic rate limit refused this attempt. |
| `verdict.execution.claim-admitted` | `execution_claim` + `permit` + `claimed` | An at-most-once claim was admitted and the action handed to its executor. **Nothing has run.** |
| `verdict.execution.claim-completed` | `execution_claim` + `permit` + `completed` | Verdict marked its own claim complete around a successful return. An admission-side belief, not a receipt. |
| `verdict.execution.claim-refused` | `execution_claim` + `deny` (non-indeterminate) | An at-most-once claim refused a duplicate logical action. |
| `verdict.execution.claim-indeterminate` | `execution_claim` + `deny` + `indeterminate` | A claim is unresolved and needs reconciliation. |

### Why the map is curated, and keyed per stage

A mechanical `verdict.<stage>.<disposition>` was rejected twice over. It leaks internal names into a
public contract, and it mints `verdict.execution.permit` — a string that reads as "execution
happened," which is the overclaim `claimType` exists to prevent.

It is also **not keyed on `stage`+`disposition` uniformly**, because two stages record several
distinct events behind one pair:

- **`execution_claim` + `permit`** is emitted when a claim is *admitted*, before the executor is
  called, **and** when it *completes* afterwards. Keying on the pair alone would stamp
  `claim-completed` on admission rows. The disambiguator is `execution_claim_status`.
- **`approval` + `permit`** is emitted at three phases. A *consumption* spends a single-use receipt;
  calling it "validated" describes a different claim, and a consumption *failure* is a replay signal
  the other two are not. The disambiguator is `approval_phase`.

`verdict.execution.claim-indeterminate` is the one label that deliberately covers two paths: this
attempt's executor threw after admission, or a duplicate was refused because an earlier attempt is
unresolved. The record carries the claim's *status*, not the transition outcome that produced it, so
it cannot distinguish them — and the label does not pretend to.

### The test that keeps it honest

`ClaimTypeVocabularyTest` walks every tuple the evaluation state machine can present — each stage
crossed with every disposition and every value of that stage's discriminator — and fails unless each
one is either mapped or explicitly declared unreachable, and never both. A new stage, disposition,
approval phase, or claim status cannot ship until someone deliberately decides what it asserts.

Unreachable tuples are declared from a walk of the code that emits each stage, not from what the
enums permit. The `proposal` and `execution` stages record an application-supplied
`CapabilityAuthorizer` decision, so every disposition is reachable there; the other four stages are
minted by Verdict's own managers and are bounded by what those emit.
