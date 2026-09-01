# ADR 0039 — Replay refusal outlives the consumed receipt

- Status: Proposed
- Deciders: Verdict maintainers
- Issue: #460

## Context

Today the **consumed receipt row is itself the replay guard.** `issue()` refuses a replay by looking
the binding up by its three-part key, `findForBinding(toolCallId, capability, bindingFingerprint)`.
That path checks **expiry before terminal status**, so its outcomes are: an open (`Pending`/`Approved`)
row → `Existing`; an expired row (any status, incl. expired-consumed) → `Expired`; a live `Consumed`
row → `InvalidState`.

`PrunableApprovalReceiptStore` therefore **deliberately never prunes `Consumed` rows** (`pruneExpired()`
filters `status != Consumed`), and its Stable contract and stability doc both say why: deleting one
"would free its unique binding for a second human-approved execution."

The cost is #460. The receipt table only ever grows, and it is payload-bearing: approver summary,
proposal provenance, approval context, and the provider `tool_call_id` **in the clear** — a
never-prunable table that is also confidential.

The #460 candidates each cost something: (1) never prune — unbounded confidential growth; (2) prune
after a long retention — silently restores the replay window; (3) prune only where an execution claim
also protects the capability — couples the receipt store to capability config; (4) same-row tombstone —
still one unbounded row per binding, still the cleartext `tool_call_id`; (5) mandate `atMostOnce()` — a
contract change moving replay prevention into the claim store.

## Decision

Separate the two things the consumed row conflates: a **permanent** fact — "this exact binding was
consumed and must never be re-issued" — from the **payload** of that approval, an audit artifact with a
retention lifecycle. Introduce a fixed-width, permanent **consumed-binding guard** table keyed by a
one-way digest of the binding:

```
guardKey (row PK) = sha256_raw( sha256(toolCallId) . sha256(capability) . bindingFingerprint )  ->  consumed_at
```

The guard is permanent and never pruned. The full receipt MAY be pruned after a documented
payload-retention window, because the guard — not the row — is what makes the binding non-reissuable.

### Binding admission is serialized on a coarse lock key (closes the replay race)

Guard-check-then-insert is not atomic on its own: an issuer could read "no row, no guard" while a
concurrent `consume()`+prune commits a guard and deletes the row, then mint a fresh `Pending`. So every
writer of binding-admission state — **`issue()`, `consume()`, and `pruneConsumedPayload()`** — first
acquires a **transaction-scoped binding-admission lock**, then does its row work, then commits.

The lock key is the **coarse pair `(toolCallId, bindingFingerprint)`**, deliberately *not* the full
triple: `consume(toolCallId, bindingFingerprint, at)` does not receive `capability`, so only the pair
is available to every writer before it reads a row. Over-serialization (two different-capability
bindings that share the pair briefly blocking each other) is always safe; under-serialization is not.
The **guard row key stays the full triple** — as precise as today's uniqueness key, so no false
refusal across capabilities. `issue()` has the full binding and computes the guard key directly;
`consume()` computes it *after* reading its receipt (a `Pending`/`Approved` row, never pruned, so its
`capability` is always available under the lock). One global lock order — admission lock first, then row
operations, everywhere — so there is no lock-order inversion between `issue()` and `consume()`.

Per-driver primitive (named, not optional):

- **PostgreSQL:** `pg_advisory_xact_lock(k)`, `k` a 64-bit key derived from the pair; released at
  commit/rollback.
- **MySQL / MariaDB:** upsert a row in a dedicated single-column lock table keyed by the pair
  (`INSERT … ON DUPLICATE KEY UPDATE`), then `SELECT … FOR UPDATE` that row inside the transaction.
- **SQLite:** the admission transaction must be entered with **`BEGIN IMMEDIATE` as the outer
  transaction** on the connection, *before* the first admission read — the reserved write lock
  serializes all writers (SQLite is single-writer). `BEGIN IMMEDIATE` cannot be issued inside an
  already-open deferred transaction, so this requires a **SQLite-specific outer-transaction entry
  path** in the transaction runner that preserves the existing rollback/retry behavior; it must not run
  inside the generic deferred-transaction path.
- **In-memory store:** trivially serial — a single synchronous mutation with no yield point.

### The admission critical section: check → (attest) → persist, in that order

Everything below happens **inside one per-binding admission-locked transaction.** The ordering is
load-bearing for two invariants at once — *a persisted receipt always has a preceding successful
attestation* (strict issuance, existing) and *a refused issuance produces no attestation and no receipt*
(new). The current manager attests **before** `store->issue()`; that is reordered.

1. **Admission check (reads only).** Run today's `findForBinding` path first and unchanged. A row
   present → today's exact outcome (`Existing` / `Expired` / `InvalidState`) with the receipt object,
   so the unpruned path is byte-for-byte preserved. If **no row exists**, consult the guard:
   guard present → **refuse** (see below); guard absent → clear to mint.
2. **Attest (strict issuance only), only when step 1 cleared to mint.** For an attested-issuance
   capability, materialize and `attestIssuedSummary()` here — *after* the admission check, so a refused
   replay can never append an attestation claiming an issuance that did not happen. Attest failure
   still refuses issuance (existing `IssuanceRefused` reasons) without persisting.
3. **Persist.** Mint the `Pending` receipt (same id that was attested). A persisted receipt therefore
   always has a preceding successful attestation, as today.

**Attestation is external I/O inside a retryable transaction — three requirements.** (a) The identity
fingerprint's receipt **id is generated exactly once per logical issuance, outside/before the retryable
transaction boundary, and reused by every retry** — so `sha256(id)`, the attest idempotency key, is
stable across retries. (Today's `issue()` generates a fresh `Str::random(64)` per invocation; the id
must be lifted so a retried transaction does not re-randomize it.) (b) `AttestsIssuance` must be
**idempotent per identity fingerprint** (that same `sha256(id)`): a transaction retry that re-runs step
2 with the same id is a no-op append, never a duplicate attestation. (a) and (b) are contract
requirements, stated and tested. (c) A permanent persist/commit failure
*after* a successful attest leaves a **reconcilable orphaned attestation** — an identity fingerprint
with no receipt, which authorizes nothing (no receipt = nothing consumable) and is surfaced by the
evidence↔receipt reconciliation path (cf. VC-14 correlation), not a silent false record. This orphan
possibility is inherited from the existing attest-before-persist ordering, not introduced here; the new
part is the idempotency requirement that makes it retry-safe. The admission lock is held across the
attest call, but it is the **coarse pair lock**, so only concurrent issuance sharing the same
`(toolCallId, bindingFingerprint)` pair waits (which, per the coarse-key choice, includes the rare case
of two different capabilities sharing that pair); issuance of any other binding never blocks — an
accepted, bounded contention cost.

A guard-present refusal in step 1 returns a new store-level outcome **`ApprovalOutcome::PreviouslyConsumed`**
(`succeeded() === false`); there is no receipt object, inherent to the row being pruned.

### Refusal is an operation without a receipt (new evidence contract)

There is **no refusal-recording path today**: `recordOperation()` short-circuits unless the outcome is
the success outcome *and* the receipt is non-null, and it anchors evidence on `sha256(receipt->id)`.
So a receiptless refusal cannot use it, and the existing `IssuanceRefused` reasons are in fact **not
recorded as operations at all**.

This ADR defines a **refusal-operation evidence record**: an operation with **no receipt**, anchored on
the **guard digest** (`sha256_raw(...)` above — a hash of the binding, available without a receipt id),
carrying the refusal reason. `PreviouslyConsumed` records through it. The same contract **subsumes the
existing attest-refusals** (`SummaryNotReleased` / `AttestNotConfigured` / `AttestAppendFailed`), which
become recorded consistently rather than silently dropped — a deliberate, tested addition, not a side
effect. `EvidenceWriteFailed` handling mirrors `recordOperation` (a failing writer/listener never
blocks the caller).

**Refusal records are deduplicated, or a replay flood grows evidence without bound.** A `PreviouslyConsumed`
refusal is an idempotent fact about a binding, so it is recorded **at most once per guard digest** — the
record is keyed by the digest and carries an attempt count and last-seen time (incremented/updated on
subsequent replays) rather than one row per attempt. An attacker replaying a consumed binding therefore
cannot inflate the evidence table beyond one row per distinct binding. Unlike the permanent guard, these
operational refusal records are **prunable** (they are evidence, not the replay guard); their retention
follows the ordinary evidence lifecycle, not the guard's permanence.

### Consumption: exactly one guard, fail-closed, indivisible from the transition

Under the lock, `consume()` persists the guard and the `Consumed` transition **indivisibly** — neither
observable without the other (one transaction in a DB store; one synchronous mutation in-memory). A
successful consume writes **exactly one** guard row. A guard already present for this key at consume
time is a **fail-closed invariant violation** (a repeated `consume()` never reaches the insert —
validation rejects an already-`Consumed` receipt — so a present guard means collision, corruption, or a
rolling-deploy hole): the store raises. (Insert-or-ignore is rejected.)

`validate()` and `consume()` on an already-pruned binding return `NotFound`; the guard governs
**issuance only**, which is the sole replay vector.

### Pruning: additive contract, self-guarding, consumed_at-clocked, one row per transaction

- A new **additive** marker `PrunesConsumedApprovalPayload` (sibling of `PrunableApprovalReceiptStore`,
  per the #425 `Distinguishes…` pattern) declares `pruneConsumedPayload(DateTimeImmutable $consumedBefore): int`.
  Stable `pruneExpired()` is **not** redefined and still prunes only never-consumed rows.
- The command gains a distinct `--consumed-days` / `verdict.approvals.consumed_retention_days`, default
  `null` = never prune consumed. `--consumed-days` against a store not declaring the marker **fails
  closed** with an error.
- Retention is clocked on **`consumed_at`**, inclusive (`consumed_at <= $consumedBefore`), not
  `expires_at`.
- **One admission-locked transaction per pruned row.** For each row: acquire its pair admission lock,
  guarantee the guard exists (insert if absent), delete the receipt, commit — then move to the next row.
  Processing one row per transaction (rather than many rows under accumulated locks) means two
  concurrent sweepers can never hold pair locks in opposite orders, so the sweep cannot deadlock and
  lock-hold time stays bounded. A consumed row missed by backfill (old pre-guard consumer) is guarded at
  prune time, never stranded.

### Migration and deployment

The migration creating the guard table **backfills** a guard for every existing `Consumed` receipt.
Both shipped stores exclude `Consumed` from pruning (verified in code and test), so no consumed binding
has been pruned before this change and the backfill is complete for shipped stores. Guard-writing
`consume()` must reach **all** processes before `--consumed-days` is enabled; independently, the
self-guarding prune means correctness does not depend on that ordering.

## Digest posture: keyless by default, keyed opt-in (decided)

The digest is unsalted and deterministic, and the guard is **permanent** while the receipt is
ephemeral. Anyone with read access to the guard table *and* a candidate `(toolCallId, capability,
bindingFingerprint)` triple can test **offline, indefinitely**, whether that action was consumed — and
if the fingerprint's inputs are low-entropy, the triple may be enumerable. A real confidentiality
exception; **not** "strictly less disclosive" than the receipt across time.

**Decision: keyless by default, keyed (versioned HMAC) as an opt-in.** Keyless preserves the current
zero-secret, no-outage baseline while giving confidentiality-sensitive adopters a deliberate hardening
path. The leaked-snapshot membership inference is real but requires *both* snapshot access *and* a
guessable triple; a universal mandatory key would convert key-lifecycle failure into an availability
failure for **every** deployment, which is the worse default.

- **Keyless (default).** Self-contained and outage-free. The attack requires an exfiltrated snapshot
  *and* a target-action hypothesis, and yields only "this action was consumed."
- **Keyed (opt-in),** for threat models including long-lived database-read compromise. Versioned HMAC.

Recorded constraints:

1. **A keyless guard is a documented privacy tradeoff, not anonymization.** The docs state this plainly
   where retention is configured; it must never be described as anonymizing the binding.
2. **Keyed mode persists `algorithm` and `key_version`** alongside each guard (both null/keyless in the
   default mode; the digest column stays fixed-width `BINARY(32)` under either scheme). Because guards
   are permanent, rotation **retains every historical key** still needed to check any existing guard —
   keys are append-only, never dropped while a guard derived from them survives. A consequence to
   document: since `issue()` cannot know which key version guarded a given binding, a keyed check probes
   each retained keyed version plus the keyless candidate (up to one lookup per version), where a
   keyless-only deployment is a single lookup — an opt-in cost borne only by keyed deployments.
3. **A missing historical keyed secret fails closed for issuance** — `issue()` cannot re-derive the
   digest to check, so it refuses rather than mint. This is an explicit, operator-owned operational
   responsibility, documented as such.
4. **Migrating keyless → keyed keeps checking legacy keyless guards.** Enabling keyed mode adds keyed
   derivation for new guards; it does **not** retroactively re-key or hide existing keyless guards, which
   remain permanently checkable. `issue()` cannot read a row's scheme before it finds the row (the digest
   *is* the primary key), so a check **probes candidate digests** — the keyless digest plus one per
   retained keyed version — and the row a probe hits is the guard; the matched row's stored
   `algorithm`/`key_version` then *describe* how that guard was derived (validated after the match), they
   do not select the scheme beforehand. This is why legacy keyless guards stay reachable after migration:
   the keyless candidate is always among those probed.
5. **The opt-in must be selected before high-confidentiality consumption begins.** Keyed mode is not a
   retroactive protection — guards written while keyless stay keyless — so an adopter that needs keyed
   confidentiality must enable it before the consumptions it wants protected occur. Documented as a
   deployment-time decision, not a later toggle.

## Consequences

**Store invariant.** A store that consumes a binding persists a permanent guard; `issue()` refuses (via
the guard) any binding with a present guard and no row; `issue()`/`consume()`/`pruneConsumedPayload()`
serialize on the coarse admission lock. This preserves the existing invariant — a consumed binding is
never re-issued — while permitting payload pruning.

**`PreviouslyConsumed` is a public vocabulary change** added to every exhaustive consumer:
`ApprovalOutcome` + `succeeded()` (false), the `ApprovalManager` mapping, and custom-store parity
fixtures. The manager maps a store-returned `PreviouslyConsumed` to the existing
`ApprovalOutcome::IssuanceRefused` + a **new** `IssuanceRefusalReason::PreviouslyConsumed`, recorded via
the new refusal-operation contract above.

**Audit retention is conditional.** Verdict evidence is opt-in and **defaults to none**; ADR 0031 holds
receipts are operational state, not evidence. For an adopter without durable evidence, pruning the
receipt **discards the only local record** — the guard is not an audit substitute. Enabling
`--consumed-days` warns the operator, and the docs state that forensic history after the window requires
durable evidence that is both **sufficiently detailed for the adopter's forensic need and retained at
least as long as the claimed audit window** — merely being configured establishes neither. Enabling it
sheds *audit payload*; it does **not** restore a replay window.

**Portability / stability.** The guard table travels with the store on any migration. A change to the
`bindingFingerprint` algorithm invalidates old guards — already true of today's row key, out of scope.

## Alternatives rejected

Candidates 1–5 above (4: cleartext-id + unbounded rows; 5: contract break; 2: silent replay window).
Mandatory keyed digest rejected as the default for the availability coupling above; offered as opt-in.

## Pinned regression (test spec — parity across InMemory and Database stores)

1. **Survives payload pruning.** issue → approve → consume → `pruneConsumedPayload(window)` deletes the
   receipt → re-issue identical proposal → refused (`PreviouslyConsumed` → `IssuanceRefused`/
   `PreviouslyConsumed`) **before any `Pending` row exists**; assert none created.
2. **Guard persists, receipt gone** after the sweep.
3. **Unpruned parity unchanged.** Row present: live-consumed → `InvalidState`; expired (incl.
   expired-consumed) → `Expired`; open → `Existing`.
4. **Sensitivity.** After the sweep, a different binding (different fingerprint, capability, or
   toolCallId) mints `Pending`, not refused.
5. **Guard is load-bearing.** With `consume()` not writing the guard (or `issue()` ignoring it on a
   pruned binding), test 1 fails. Stated in the PR.
6. **Exactly-one / fail-closed.** Success writes exactly one guard; a failed receipt transition writes
   none (rollback); a pre-existing guard at consume raises.
7. **Self-guarding prune closes the backfill gap.** A consumed row with no guard is guarded by
   `pruneConsumedPayload()` in the same step that deletes it; a later re-issue is refused.
8. **Concurrency / lock order.** Interleaved consume-of-binding and issue-of-same-binding serialize:
   the issuer returns `Existing`/refusal, never a second `Pending`, never a lost guard; no deadlock.
9. **No false attestation on refused replay.** For an attested-issuance capability, a pruned-binding
   re-issue is refused (`PreviouslyConsumed`) and **`attestIssuedSummary()` is never called** — no
   attestation record is appended for the refused issuance.
10. **Refusal is recorded.** The refused re-issue produces a refusal-operation evidence record anchored
    on the guard digest with reason `PreviouslyConsumed` (and the existing attest-refusals record
    through the same contract).
11. **Retention clock.** Deletes by `consumed_at`, inclusive; a row consumed just before `expires_at` is
    retained for the full consumed-retention window.
12. **Prune does not deadlock.** Two concurrent sweeps over overlapping bindings complete without
    deadlock (one admission-locked transaction per row).
13. **Opt-in / fail-closed command.** `--consumed-days` against a store without the marker errors;
    default leaves consumed rows untouched, exactly as today.
14. **Attest is retry-safe.** A transaction retry across the attest step produces exactly one
    attestation for the identity fingerprint, never a duplicate; a persist failure after a successful
    attest mints no receipt and the attestation is surfaced as an orphan by reconciliation.
15. **Refusal recording is flood-bounded.** N replays of the same consumed binding produce **one**
    refusal record (attempt count = N), not N records.
