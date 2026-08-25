# Reconstructing an incident from the evidence tables

An agent did something it should not have. This document walks one incident from the alert to a written
conclusion, using only tables Verdict ships and queries you can paste into a SQL client.

It is deliberately a *reconstruction*, not a report. Each step states what the evidence establishes and
what it does not, because most of the value in an audit trail is knowing where it stops. The
[limitations](limitations.md) are the authority on that boundary; this document restates the ones an
incident actually runs into, in the order it runs into them.

Everything here is read-only. Nothing in this walkthrough changes application state.

## Before you start

Three preconditions, in the order they bite:

1. **A durable recorder must have been configured before the incident.** `verdict.evidence.recorder`
   defaults to `NullEvidenceRecorder`, which records nothing. If it was the default when the action ran,
   this document has nothing to work with and no query below will return a row. Verdict dispatches
   `ConsequentialActionUnrecorded` once per process in that situation and `verdict:validate` reports it —
   but neither blocks, because evidence is not a gate.
2. **Table names are configurable.** The defaults are used throughout: `verdict_evidence`,
   `verdict_provenance_derivations`, `verdict_capability_configurations`, `verdict_approval_receipts`.
   Substitute your own if you changed them.
3. **Evidence may live on its own connection.** If `verdict.evidence.connection` is set, run these against
   that connection, not the application's default.

## The alert

> A customer contacts support: an order they never asked to cancel was cancelled. The agent-facing
> storefront assistant is the only system with that capability. The customer's own account was used.

This is the hard shape of the problem, not the easy one. Nothing was *unauthorized* — the actor held
authority over the order. The question is not "who broke in" but "what caused the assistant to propose
this action," and that is a provenance question rather than an authorization one.

Two facts to start from: the capability name (`orders.cancel`) and an approximate time window.

## Step 1 — Find the decision

Decision rows are `record_type = 'decision'`. One protected action produces several of them, one per
evaluation stage.

```sql
SELECT id, stage, disposition, reason, capability, invocation_id, correlation_id,
       argument_fingerprint, actor_fingerprint, subject_fingerprint,
       configuration_fingerprint, target_source, target_identity_matched,
       approval_phase, approval_outcome, recorded_at
FROM verdict_evidence
WHERE record_type = 'decision'
  AND capability = 'orders.cancel'
  AND recorded_at BETWEEN '2026-08-14 00:00:00' AND '2026-08-14 23:59:59'
ORDER BY recorded_at, id;
```

`stage` is one of `proposal`, `approval`, `target_refresh`, `execution`, `rate_limit`, `execution_claim`.
`disposition` is one of `permit`, `deny`, `require_confirmation`, `require_review`, `throttle`. An action
that actually ran ends with a `permit` at the `execution` stage; anything else is a denial you are
confirming rather than an incident you are investigating.

`claim_type` states what each row asserts in one label, and is the faster read: selecting
`claim_type = 'verdict.execution.claim-completed'` finds completions without knowing which
stage/disposition/status tuple produces one, and `verdict.approval.consumption-failed` names a replayed
single-use receipt directly. Two labels are worth knowing before you rely on them — `claim-admitted` means
the action was handed to its executor and **nothing has run**, and `claim-completed` is Verdict marking its
own claim complete around a successful return, never a receipt from the executor. The full table is in
[evidence record identity](evidence-record-identity.md).

**What this establishes.** That Verdict evaluated this capability, at these stages, and reached these
dispositions, at these times.

**What it does not.** That the executor's side effect actually occurred. Verdict records that it *admitted*
the execution; whether the downstream call succeeded, partially applied, or timed out is the application's
to know. See [no guarantee of downstream side effects](limitations.md#no-guarantee-of-downstream-side-effects).

## Step 2 — Read the correlation columns correctly

This is the step that most often produces a wrong reconstruction, so it comes before widening.

`verdict_evidence` carries two identifier columns and they do not mean the same thing on every row:

| `record_type` | `correlation_id` holds | `invocation_id` holds |
|---|---|---|
| `decision` | the **action envelope id** — one protected action | the agent invocation id, or `NULL` |
| `provenance` | the **invocation id** (same value as `invocation_id`) | the invocation id |
| `context_release` | always `NULL` | the invocation id, or `NULL` |

So a decision row and the provenance rows for the same agent turn share an `invocation_id` and do **not**
share a `correlation_id`. Joining decisions to provenance on `correlation_id` returns nothing, silently.
The one column that spans record types is `invocation_id`.

`invocation_id` is nullable on decision rows. It is populated when Verdict observed the action inside a
Laravel AI invocation; a capability driven directly from application code has no invocation and reconstruction
stops at Step 1 plus Steps 5–7. That is a real limit, not a defect: there was no agent turn to widen into.

## Step 3 — Widen to the invocation

Take the `invocation_id` from Step 1 and read every fact Verdict recorded during that agent turn.

```sql
SELECT record_type, stage, disposition, capability, channel, component_label,
       source, destination, trust, data_class, content_fingerprint, recorded_at
FROM verdict_evidence
WHERE invocation_id = :invocation_id
ORDER BY recorded_at, id;
```

This returns three kinds of row interleaved in time: the `decision` rows from Step 1, `provenance` rows for
each piece of content the invocation ingested, and `context_release` rows for anything released outward.

On a `provenance` row, `channel` is one of `user_input`, `retrieved_document`, `tool_result`,
`application_context`, and `trust` is `trusted` or `untrusted`. An `untrusted` `retrieved_document`
recorded shortly before the proposal is the shape you are looking for.

**What this establishes.** Containment: these facts were recorded during the same invocation.

**What it does not.** Causality. Two rows sharing an invocation says they co-occurred, nothing more. The
causal claim requires a declared edge, which is Step 4.

## Step 4 — Establish the inputs

Verdict does not infer influence. It reads edges an application declared through
`ProvenanceLedger::declareDerivation()`, stored in `verdict_provenance_derivations`.

The anchor is the decision's `argument_fingerprint`. That column holds
`ArgumentFingerprint::make($proposal->arguments)`, which is the same digest the provenance ledger records for
the same array — both are SHA-256 over `CanonicalJson::encode()`, and `ProposalAnchor::for()` exists to make
that the single supported way to compute it. So the decision's `argument_fingerprint` is directly usable as a
`child_content_fingerprint`.

<!-- @verdict-claim evidence.argument-fingerprint-anchors-provenance tested -->

Direct parents first:

```sql
SELECT parent_content_fingerprint, kind, recorded_at
FROM verdict_provenance_derivations
WHERE correlation_id = :invocation_id
  AND child_content_fingerprint = :argument_fingerprint
ORDER BY recorded_at;
```

`kind` is one of `retrieved`, `summarized`, `transformed`, `tool_result`.

Then the full backward-reachable set. The ledger refuses to write a cycle, so this terminates:

```sql
WITH RECURSIVE upstream (content_fingerprint, depth) AS (
    SELECT CAST(:argument_fingerprint AS char(64)), 0
    UNION
    SELECT d.parent_content_fingerprint, u.depth + 1
      FROM verdict_provenance_derivations d
      JOIN upstream u ON d.child_content_fingerprint = u.content_fingerprint
     WHERE d.correlation_id = :invocation_id
)
SELECT content_fingerprint, depth FROM upstream WHERE depth > 0;
```

The cast on the anchor term is load-bearing, not decoration. The fingerprint columns are `char(64)`, and
PostgreSQL rejects a recursive CTE whose non-recursive term is an untyped parameter with
`recursive query "upstream" column 1 has type text in non-recursive term but type bpchar overall`. The form
above is verified on PostgreSQL 16, MySQL 8, MariaDB 11, and SQLite.

Fingerprints alone are not an answer. Join them back to the provenance rows to learn what each one *was*:

```sql
SELECT content_fingerprint, channel, trust, data_class, source, component_label, recorded_at
FROM verdict_evidence
WHERE record_type = 'provenance'
  AND correlation_id = :invocation_id
  AND content_fingerprint IN (/* the fingerprints from the CTE */)
ORDER BY recorded_at;
```

An `untrusted` `retrieved_document` in that set, declared `retrieved` upstream of the cancelled order's
arguments, is the finding: content the application marked untrusted contributed to the proposal.

`ProvenanceLedger::backwardReachableContentFingerprints()` is the supported API for this and applies the same
scoping. The SQL above is for ad-hoc forensics against a replica, not a query surface to build on.

**What this establishes.** That an application declared these derivation edges during this invocation.

**What it does not.** Three things, each of which has been mistaken for the opposite:

- **An absent edge means "not observed or not declared", never "no influence occurred."** Verdict sees only
  what the application declares. An injection path nobody instrumented leaves no edge and no trace. See
  [provenance derivation is deliberately incomplete](limitations.md#provenance-derivation-is-deliberately-incomplete).
- **Content is fingerprinted, not stored.** You get SHA-256 digests. Recovering the document text means
  finding it in your own systems by its digest; Verdict does not retain it.
- **Edges are scoped to one invocation at every hop.** A lineage declared at RAG-ingestion time, days
  earlier, is not reachable from this invocation's anchor even though the bytes are identical. This
  under-reports rather than over-reports, deliberately — see
  [lineage declared in another invocation](limitations.md#lineage-declared-in-another-invocation-does-not-reach-an-approver) and
  [#201](https://github.com/fissible/verdict/issues/201).

## Step 5 — Establish identity

`actor_fingerprint` and `subject_fingerprint` are SHA-256 over the string the application returned from
`ProvidesVerdictIdentity::verdictIdentity()`. Verdict does not know what that string means.

There is no join to your users table, and that is intentional. To resolve one, recompute the digest for a
candidate and compare:

```php
hash('sha256', $candidate->verdictIdentity()) === $row->actor_fingerprint;
```

A `NULL` in either column means the actor or subject did not implement `ProvidesVerdictIdentity` — the
identity was not declared, rather than being declared as absent.

**What this establishes.** That two decision rows carrying the same fingerprint concern the same declared
identity, and that rows carrying different fingerprints do not.

**What it does not.** Who that identity is. Resolving a fingerprint to a person is entirely the
application's job, and it is only as reliable as the canonical string's stability. An identity string built
from a mutable attribute — an email address, say — silently stops matching its own history the day that
attribute changes.

## Step 6 — Establish the policy in force

`configuration_fingerprint` on the decision row expands through the capability configuration registry to the
configuration that was actually in effect when the decision was made, not the one in today's codebase.

```sql
SELECT capability, configuration, first_seen_at
FROM verdict_capability_configurations
WHERE configuration_fingerprint = :configuration_fingerprint;
```

`configuration` is JSON: the canonical form of the capability's registered configuration. Comparing it to the
current registration answers "was this capability configured differently at the time?" — which is often the
whole incident.

```sql
SELECT configuration_fingerprint, COUNT(*) AS decisions,
       MIN(recorded_at) AS first_seen, MAX(recorded_at) AS last_seen
FROM verdict_evidence
WHERE record_type = 'decision' AND capability = 'orders.cancel'
GROUP BY configuration_fingerprint
ORDER BY first_seen;
```

More than one fingerprint means the capability's configuration changed over the window. The boundary between
them is when.

**What it does not establish.** That the configuration was *correct*, or that the policy code it names
behaved as intended. The registry records the configuration's identity, not the logic of the closures and
policy classes it points at. A fingerprint proves which configuration was in force, not that it was sound —
see [a configuration fingerprint does not cover resolver or executor
logic](limitations.md#a-configuration-fingerprint-does-not-cover-resolver-or-executor-logic).

## Step 7 — If the action was approval-gated

Two things to know before querying, both of which surprise people.

**Approval receipts are not evidence.** Per [ADR 0007](adr/0007-evidence-layering.md),
`verdict_approval_receipts` is *operational state* — an authoritative store that gates execution. No
`EvidenceRecorder` writes it, and no recorder chains it, including the tamper-evident one.

**There is no direct join.** `verdict_evidence.approval_receipt_fingerprint` holds
`hash('sha256', $receipt->id)`, not the id. The same is true of `execution_claim_fingerprint`
(SHA-256 of the claim id) and `idempotency_key_fingerprint` (SHA-256 of the key). To connect a decision row to
a receipt, hash the candidate id and compare — or, for a small table, compute the digest column-side with your
engine's SHA-256 function.

```sql
SELECT id, tool_call_id, capability, binding_fingerprint, status,
       approved_by, approved_at, consumed_at, expires_at, provenance
FROM verdict_approval_receipts
WHERE capability = 'orders.cancel'
  AND consumed_at BETWEEN '2026-08-14 00:00:00' AND '2026-08-14 23:59:59';
```

The `provenance` column holds the disclosure payload the approver was shown, materialised at receipt
issuance. It has three distinct empty-ish states and conflating them produces a wrong conclusion:

- **`NULL`** — the receipt predates the column. A storage era, not a disclosure state.
- **a recorded `unknown` disclosure** — the ledger had nothing declared upstream of the proposal.
- **a recorded `unreleased` disclosure** — the application registered no approver release policy, so
  Verdict had something to say and no sanctioned channel to say it on.

See [ADR 0026](adr/0026-what-an-approver-is-shown.md) for what an approver is shown and why the distinction is
load-bearing.

**What this establishes.** That a human approved this binding, when, and under which application identifier.

**What it does not.** That the human understood what they approved, or that the identifier in `approved_by`
corresponds to a person who was actually present. Verdict stores an opaque application-supplied string; the
application owns reviewer authentication.

## Step 8 — What tamper-evidence proves, and what verification proves

If the incident might involve someone with database access, the reconstruction above is only as trustworthy as
the store it read.

With the default `DatabaseEvidenceRecorder`, every table in this document is an ordinary mutable audit store.
Rows can be edited or deleted without detection. Nothing above should be described as cryptographic proof.

With `AttestEvidenceRecorder` configured, decisions and context releases are signed and hash-chained — and
these remain true:

- **Provenance is not chained by default.** Provenance entries and derivations go through the ordinary
  fallback recorder unless `verdict.evidence.attest.chain_provenance` is enabled. Step 4, the causal heart of
  this walkthrough, is the part least likely to be covered.
- **Approval receipts are never chained**, per Step 7.
- **The chain proves nothing until someone verifies it.** A chain is tamper-evident only in retrospect; the
  control is `php artisan verdict:evidence:verify` running on a schedule, not the chain's existence. A passing
  verification establishes that the retained chain verifies against its recorded head and signing key. It
  does not identify a change or an actor.
- **Truncation verifies clean.** An attacker who controls the store can truncate the chain to a chosen point
  and re-link it. Detection needs anchoring, out-of-band published heads, or monotonic sequence numbers.
- **Key custody bounds all of it.** Anyone holding `ATTEST_SIGNING_KEY_SEED` can rewrite history and re-sign
  it, and verification will pass.

The full statement is in
[limitations](limitations.md#tamper-evident-evidence-is-opt-in-partial-and-bounded-by-key-custody) and
[verification is the control](limitations.md#verification-is-the-control-not-the-chain-alone). Run the
verification *before* trusting the reconstruction, and record which of the five `attest` verification levels
it reached — they are not equivalent.

## Step 9 — Writing the conclusion

A defensible conclusion from this walkthrough reads roughly:

> At 14:32 UTC on 2026-08-14, invocation `inv_…` proposed `orders.cancel` with arguments fingerprinting to
> `a1b2…`. Verdict permitted it at the execution stage against configuration `c3d4…`, for a declared actor
> identity fingerprinting to `e5f6…`, which the application resolved to the account holder. The application
> declared a `retrieved` derivation from an untrusted retrieved document, fingerprint `9a8b…`, to those
> arguments during the same invocation. Authorization was correct: the actor held authority over the order.
> The proposal's content is declared to derive from untrusted retrieved content.

Note what that paragraph does not say. It does not say the document caused the cancellation — it says an edge
was declared. It does not say no other input contributed — undeclared influence leaves no trace. It does not
say the cancellation reached the payment processor. Each of those would be a stronger claim than the evidence
supports, and each is the claim a reader will assume you made unless you write the limit down.

The four limits worth stating explicitly in any write-up:

1. Absent provenance means not observed or not declared, never no influence.
2. Sharing an invocation is containment, not causality; only a declared edge is causal, and only as a
   declaration.
3. An identity fingerprint proves identity *continuity*, not who someone is.
4. Unless a verified tamper-evident chain covered the rows you read, the reconstruction assumes the store was
   not edited.

## Quick reference

Joins that work:

| From | To | On |
|---|---|---|
| decision row | provenance rows, context releases | `invocation_id` |
| decision row | derivation edges | `argument_fingerprint` = `child_content_fingerprint` (+ `correlation_id` = invocation id) |
| derivation edge | provenance row | `parent_content_fingerprint` = `content_fingerprint` (+ `correlation_id` = invocation id) |
| decision row | capability configuration | `configuration_fingerprint` |

Joins that do not work:

| Attempt | Why |
|---|---|
| decision `correlation_id` → provenance `correlation_id` | different meanings: envelope id vs invocation id |
| `approval_receipt_fingerprint` → `verdict_approval_receipts.id` | the column is SHA-256 of the id |
| `execution_claim_fingerprint` → `verdict_execution_claims.id` | the column is SHA-256 of the id |
| `actor_fingerprint` → your users table | SHA-256 of an application-declared string; recompute to compare |
| provenance across invocations | edges are invocation-scoped at every hop |

## Related

- [Security model](security-model.md) — what the boundary enforces.
- [Limitations](limitations.md) — the authoritative statement of what Verdict does not guarantee.
- [Architecture](architecture.md) — where in the lifecycle each record is written.
- [ADR 0007](adr/0007-evidence-layering.md) — why receipts are operational state and not evidence.
- [ADR 0026](adr/0026-what-an-approver-is-shown.md) — what an approver is shown, and why absence is rendered.
- [Evidence record identity](evidence-record-identity.md) — the `claim_type` vocabulary and the
  `record_digest` you cite a specific row by.
- [ADR 0028](adr/0028-claim-type-is-a-curated-public-vocabulary.md) — why a claim type never implies that
  an execution happened.
