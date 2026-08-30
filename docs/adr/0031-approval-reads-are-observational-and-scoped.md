# ADR 0031: Approval reads are observational and scoped

Status: Accepted

## Related issues

- [#298](https://github.com/fissible/verdict/issues/298) is the work this settles; the design round
  on that issue is this document's source.
- [#106](https://github.com/fissible/verdict/issues/106) (closed) decided "application-owned joins
  only" for pending-review listing, deliberately without an ADR. This document is the first durable
  record of that rejection — and of the one bounded exception that has since become possible.
- [#305](https://github.com/fissible/verdict/issues/305) / PR #316 added `approval_context` to
  receipts: the application-owned scope column whose absence was #106's deciding ground.
- [#90](https://github.com/fissible/verdict/issues/90) (closed) split the evidence write and query
  contracts; this ADR follows that pattern in a different layer, not that seam.
- [#297](https://github.com/fissible/verdict/issues/297) (open) is the `RequireReview` substrate;
  its records are reserved to ride this contract when they exist (§6).
- [#299](https://github.com/fissible/verdict/issues/299) (open) is gated on this ADR: a
  transition-event stream is only meaningful against a defined status-read contract.
- [ADR 0004](0004-independent-security-state-transactions.md) and
  [ADR 0007](0007-evidence-layering.md) place receipts in the operational layer; that placement is
  why this contract is not an evidence query seam.
- [ADR 0008](0008-evidence-privacy-model.md) governs the opaque-identifier form of every field the
  read surface exposes; this ADR widens no release path.
- [ADR 0029](0029-approval-challenge-issuance-is-the-measured-fact.md) §1: expiry has no transition
  moment. §5 below inherits that.
- fissible/verdict-console
  [ADR 0001](https://github.com/fissible/verdict-console/blob/main/docs/adr/0001-approval-surface-contract.md)
  (§3, §8) is the first consumer designed against this contract's stated shape: DTOs never rows,
  freshness stated as poll-consistency, a per-receipt status read.

## Context

Three consumers read approval state three different ways. The live-evaluation harness reads
challenge/receipt state to score `awaiting_approval`. Incident-response procedure queries the
receipt table directly. verdict-console needs a pending-approval inbox and a per-row status read —
and, today, gets only `ApprovalManager::challengeForToolCall()`, which is pending-only and
collapses "expired" with "already decided" into one null.

No published read contract means each consumer couples to table shape and to whatever consistency
the store happens to give. The write/transition side is stable and deliberately narrow:
`ApprovalReceiptStore` is `issue()`, `findForToolCall()`, `find()`, `approve()`, `reject()`,
`validate()`, `consume()`. #106 examined widening it with a pending-review listing and rejected
that: the schema had no tenant, actor, subject, or conversation column, so a package-level query
could not scope one, and an unscoped listing is a cross-tenant footgun. Its stated reopen
condition: *a reviewer-queue requirement that a `tool_call_id` join genuinely cannot satisfy.*

Two facts have changed since #106 closed:

- **Receipts now carry `approval_context`** (#305): a canonical scalar map
  (`array<string, string|int>`) of application-owned binding identifiers, captured verbatim at
  `issue()`. `null` means never captured (pre-migration); `[]` means captured empty. The scope
  column #106 lacked exists — for adopters who supply it.
- **The `RequireReview` lane (#297) meets the reopen condition exactly.** Nothing pauses, so there
  is no event for a consumer to ingest and no application-side row to join on. Enumeration through
  the package is the only honest source for that lane.

A read contract is a 1.0 surface: once consumers depend on how status is read, changing it is a
break. Better to state it before three consumers harden three different couplings.

## Decision

### 1. The read seam is its own contract, in the operational layer

Approval reads get a dedicated, narrow, container-resolved, DTO-returning read contract —
`Fissible\Verdict\Contracts\ApprovalStatusReader` — separate from `ApprovalReceiptStore`. It
follows the pattern #90 established for evidence (`LiveEvidenceReader` is the precedent), but it
is not an extension of the evidence query seams: receipts are operational state that gates
execution, not evidence (ADR 0007), and the two layers stay separate.

The reader is observational by construction. It returns DTOs, never rows and never live models;
it exposes no verbs; nothing in Verdict's runtime depends on it.

### 2. The surface

```php
interface ApprovalStatusReader
{
    public function statusFor(string $receiptId): ?ApprovalStatusView;

    public function statusForToolCall(string $toolCallId): ?ApprovalStatusView;

    /** @param non-empty-array<string, string|int> $scope */
    public function pendingWithin(array $scope): array; // list<ApprovalStatusView>
}
```

`ApprovalStatusView` carries `receiptId`, `toolCallId`, `capability`, `status`, `reason`,
`expiresAt`, `approvedBy`/`approvedAt`, `rejectedBy`/`rejectedAt`, `consumedAt`, `createdAt`, and
`approvalContext`. Every field is a fingerprint, an opaque identifier (ADR 0008), a timestamp, or
an application-supplied scalar the application chose to bind — #106's Q2 analysis, which ruled all
of them safe and none of them sufficient to render a reviewer screen, stands unchanged.
`bindingFingerprint` is deliberately omitted: safe but consumer-less. It joins the DTO as a
trailing optional addition when a consumer demonstrates a concrete need.

**Store pairing.** `statusFor()` and `statusForToolCall()` are implementable over
`ApprovalReceiptStore::find()` and `findForToolCall()`, so a store-backed default serves them
against **any** store implementation with zero new store methods. `statusForToolCall()` inherits
`findForToolCall()`'s documented ambiguity — null never proves absence — and consumers that hold a
`receiptId` should prefer `statusFor()`. `pendingWithin()` cannot ride the store: it has no
enumeration method, by #106's design, and this ADR does not add one. The shipped readers are
therefore store-paired — the database reader queries the configured table, the in-memory reader
its array — and a custom-store owner who wants enumeration implements the reader contract too.
`ApprovalReceiptStore` is not widened by this ADR, and will not be widened for reads.

### 3. Enumeration is scoped or it is refused

`pendingWithin()` requires a non-empty scope; an empty scope throws. The unscoped global pending
list #106 rejected stays rejected — the throw is that rejection made mechanical.

A receipt matches a scope iff **every requested key exists on the receipt's `approval_context`
with the same typed canonical value**. No coercion — an integer `1` does not match a string `'1'`.
No nested-structure matching: both sides are canonical scalar maps. Receipts whose context is
`null` or `[]` never enumerate; applications that do not capture approval context keep #106's
application-owned join path, which remains documented and legal. How each backend implements typed
containment over its JSON column (SQLite, MySQL, PostgreSQL) is an implementation concern; the
semantics are fixed here.

Results are returned in deterministic order: `createdAt` ascending, `receiptId` as tiebreak. No
pagination in v1 — a scoped pending list is structurally small (it is bounded by the scope's live,
unexpired proposals), and keeping scopes bounded is an application responsibility.

`pendingWithin()` returns only receipts whose **persisted** lifecycle status is `Pending`. A
lapsed-but-undecided receipt is still returned, with its `expiresAt` (§5).

### 4. Freshness: poll-consistency, and reads carry no authority

The guarantee, stated: **a read reflects every transition committed before the read began; nothing
pushes.** A receipt resolved elsewhere may read stale until the consumer's next poll; #299 exists
to shrink that window, and is only meaningful against this statement.

Reads carry no authority. `approve()`, `reject()`, and `consume()` each re-validate status and
expiry inside their locked transaction (#106 Q4), so a stale read can never cause a wrong
transition — it can only render a row as actionable one interval longer than it was. That is an
ergonomic cost, not a security cost, and it is the reason this contract can be observational.

### 5. Expiry is computed, never reported

The reader has no "expired" status, because expiry has no transition moment (ADR 0029 §1): a TTL
passes silently, and Verdict observes it only at decision or consumption time. The reader reports
persisted status plus `expiresAt`, and the consumer compares clocks. A read API that synthesized
an `Expired` status — or worse, wrote one — would be a transition engine wearing a read contract's
name, and would put a decision nobody made into derived state.

This is what un-collapses `challengeForToolCall()`'s null for consumers:
`Approved`/`Rejected`/`Consumed` is "already decided"; `Pending` with `expiresAt` in the past is
"lapsed, undecided". The two render differently, and until this contract ships no consumer can
tell them apart.

### 6. Review-request reads ride this contract

When #297's `ReviewRequest` records exist, their reads arrive through this same contract — the
same DTO discipline, the same poll-consistency statement, the same scope rule — rather than
through a second, parallel read surface. This is a reservation, not a design of #297: the shape of
those records, their refusal payload, and their transitions live on that issue. Designed as of [ADR 0035](0035-the-asynchronous-review-lane.md), which realizes "this same contract" as the same read *discipline* through a separate typed `ReviewStatusReader` — a review's DTO and per-item key (the request id) differ from a receipt's.

## Alternatives rejected

### Widen `ApprovalReceiptStore` with listing or status methods

Breaks every custom store, and re-opens the door #106 closed: a store-level `listPending()` is one
default parameter away from an unscoped queue. The store stays the write/transition authority with
its per-receipt lookups; reads get their own seam.

### An unscoped pending list

Rejected by #106, re-rejected here, now mechanically: the scope parameter is non-empty by
contract. Cross-tenant enumeration of pending approvals is the footgun, and no ergonomic argument
reaches it.

### Riding the evidence query seam from #90

Wrong layer. Evidence is the append-only record of what happened; a receipt is live operational
state that gates what happens next (ADR 0007). A consumer asking "what is pending" is not asking
an evidence question, and blurring the two invites reading operational state with evidence
semantics — or the reverse.

### Reporting or writing an `Expired` status

ADR 0029 §1 decided that a decision nobody made must not be written into durable security state; a
derived `Expired` in a DTO is the same decision made in a weaker place, and a sweeper that writes
one is worse. Consumers get the persisted status and the deadline, and own the clock comparison.

### Naming the contract `ApprovalReadModel`

"Read model" imports CQRS vocabulary this codebase does not use, and overstates what this is: one
narrow reader, not a projection pipeline. `ApprovalStatusReader` matches the existing
`LiveEvidenceReader` precedent and says what it does.

## Consequences

- The three existing consumers are expressible against the contract without reaching past it into
  table shape: the harness through `statusForToolCall()` (or the unchanged
  `challengeForToolCall()`), incident response through `pendingWithin()` + `statusFor()`, the
  console through pause-time ingestion plus `statusFor()` refresh now and `pendingWithin()` for
  the asynchronous lane when #297 lands. Acceptance criterion 2 of #298 is met by inspection.
- verdict-console's three workarounds acquire their deletion path: VC-10's cut durable retry
  (decision read-back now exists), VC-43's both-halves defence for a null challenge, and VC-45's
  "expired or already decided" copy, which §5 splits.
- #299's event stream now has the status-read contract it is defined against, and inherits §4's
  framing: events shrink the stale window; they do not change the consistency statement.
- Implementation is a follow-up issue, S-sized: the contract, the DTO, a store-backed default for
  the status reads, the paired database and in-memory readers, and the typed-JSON-containment
  portability question named in §3. Until it ships, `findForToolCall()` remains the entire read
  surface and the adoption guide's join guidance stands.
- The adoption guide keeps documenting the application-owned join path: it is the enumeration
  story for applications that do not capture `approval_context`, and #106's decision comment
  remains its record.
