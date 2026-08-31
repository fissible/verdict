# ADR 0038: What an approver is shown about the action

Status: Accepted

## Related issues

- [#306](https://github.com/fissible/verdict/issues/306) is the design round this settles.
- [#300](https://github.com/fissible/verdict/issues/300) — `ApprovalChallenge` gains `issuedAt`, folded into the
  same approver-visibility surface.
- [ADR 0026](0026-what-an-approver-is-shown.md) shaped the approval payload and **deliberately deferred** this
  question; this ADR takes up that deferral.
- [ADR 0008](0008-evidence-privacy-model.md) governs release of application data to a new audience.
- [ADR 0035](0035-the-asynchronous-review-lane.md) keeps the confirmation and review lanes' state machines
  separate; this ADR shares a value, not a lifecycle.
- [#201](https://github.com/fissible/verdict/issues/201) — cross-invocation lineage, a distinct extension point.

## Context

ADR 0026 gave an approver the *provenance* of a proposal — source, trust, data class, channel — and closed by
naming what it did not do: "It does not tell an approver what the action **will do**. The payload still carries
no canonical facts about amount, destination, or target … the approval payload remains thin. Whether Verdict
should carry canonical action facts is a real question and a separate one, and answering it by reflex inside a
provenance change would be the wrong way to decide it."

This is that question. `ApprovalChallenge` shows a receipt id, a tool-call id, a capability name, a
registration-time `reason`, an expiry, and the provenance disclosure. It shows **nothing about the arguments**.
The `bindingFingerprint` binds them cryptographically but is a hash — it matches an approval to an action, it
does not describe it. So an approver authorises "orders.cancel — Confirm this cancellation" without seeing
*which order*, and an injection-driven proposal with hostile arguments produces a challenge indistinguishable
from a benign one. The gpt-oss suite-v2 run made this concrete: 32 live injected cancel attempts halted at the
gate, each presenting a human no argument-level basis to reject.

## Decision

### 1. A binding-informed presentation candidate, authored by the application

A capability may register an optional presentation closure:

```php
$capability->describeForApprover(fn (ActionEnvelope $envelope, mixed $target, array $binding): string => …);
```

`$binding` is the **already-computed** `approvalBinding($envelope, $target)` value — the exact array the binding
fingerprint payload is built from, passed in, never recomputed. The summary is therefore *informed by what
approval actually binds* (arguments, application binding, resolved target, and, where it applies, approval
context), not by the raw proposal alone. This closes a divergence hole: a summary derived from the
`ActionEnvelope` by itself omits the resolved target and canonical binding, and could truthfully describe the
proposal while omitting or contradicting the facts approval commits to.

Verdict does not interpret arguments. The application owns the domain knowledge that makes `order_id: 9001`
mean "Jane's outstanding order," and it owns the judgement of what is safe to show an approver. Verdict supplies
the mechanism and the moment; the adopter supplies the rendering. This is the posture of ADR 0026 §5 and
[ADR 0021](0021-coverage-adequacy-gates-a-live-verdict.md): Verdict ships the visibility, the adopter sets the
policy.

The other two options are not the default:

- **A canonical argument digest** already exists as `bindingFingerprint`. It is the **proof floor** — verifiable,
  not decision material for a human. The summary is binding-*informed* presentation; it is **not** a proof that
  the prose describes every bound fact. The fingerprint remains that proof.
- **A Verdict-side redaction pipeline over selected argument fields** is rejected as the default: it re-interprets
  every application's arguments and is a far larger privacy surface than the decision needs. The closure lets an
  application build exactly that itself.

### 2. The candidate is untrusted display content

A free-form string cannot make a malicious or buggy application summary truthful. Verdict guarantees the
**fidelity of the snapshot** — that what an approver saw is what is recorded — never the **truthfulness of the
content**. The rendering contract, binding on every consumer:

- stored as **plain text**, escaped by each renderer **at render time** (never stored pre-escaped — that
  double-escapes and couples the snapshot to one presentation);
- **size-bounded**;
- never interpreted as HTML, Markdown, or instructions;
- labelled a **proposed action**, never a verified execution result.

### 3. Authoring a candidate and releasing it are separate acts

Per ADR 0026 §1, surfacing application data to the approver audience is a **context release** governed by
ADR 0008; constructing the candidate in application code does not bypass that. The candidate passes the
approver-audience release path, and the outcome is a **typed release state**, not a bare nullable:

- `Released` — a summary was produced and released; its content and fingerprint are present.
- `NotReleased` — no summary was produced (no closure, or the closure returned nothing releasable).
- `ReleaseDenied` — a summary existed but policy withheld it.

A `null` field means a **pre-feature record** — a storage era, not a disclosure state. This absence is distinct
from provenance's `Unknown`, which means "no declared derivation": that is a different absence and its semantics
are not reused here.

### 4. Evidence — new operational events, anchored to identity

Issuance/release, the human approve/reject, and successful consumption are **new post-commit operational
events**. They are not a reuse of the execution-side approval-phase records (proposal-validation,
execution-validation, consumption), which capture neither issuance nor the human decision. Each event carries:

- `identityFingerprint` — `sha256(receiptId)` for the confirmation lane, `sha256(requestId)` for the review lane.
  **Always present.** It is a **distinct anchor from the binding fingerprint**. (The review evidence shipped for
  #297 correlates via the *binding* fingerprint as the request identifier; this ADR introduces the identity
  anchor and reconciles that naming — a binding is not an identity, and two requests may share a binding.)
- `summaryFingerprint` — a `?string`, present **only** when the release state is `Released`; null otherwise.

Raw released content lives on the **operational** receipt/request (the approver-authorised record), immutable
after issuance. Ordinary database rows are **correlation, not immutability**.

### 5. Two integrity tiers

- **Normal.** The operational events above are **observational** — delivered after commit, and may lag or drop.
  They correlate a decision to a snapshot; they are not an immutability guarantee.
- **Strict** (opt-in, for high-consequence capabilities). A **synchronous, issuance-blocking attested-issuance
  step**: if attested recording of the released material-facts summary fails, **issuance is refused** — no
  receipt or request is minted and the action never reaches an approver. Asynchronous evidence delivery is not
  what provides this guarantee, and Attest is an integrity anchor only when it is configured and the append
  succeeds. Summary production must never silently emit an empty display; strict mode makes that a refusal.

### 6. Materialised at issuance, one value across two lanes

The summary is assembled in `ApprovalManager::issue()` / `ReviewManager::issue()`, inside the invocation frame,
for the same reason ADR 0026 §6 gives — the frame is gone by the time an approver opens the challenge. It is
persisted on the receipt / request.

The confirmation lane (`ApprovalReceipt` / `ApprovalChallenge`) and the review lane (`ReviewRequest`) share the
immutable `ApproverSummary(content, fingerprint)` value and a common materialisation service. They keep their
**own** stores, readers, lifecycles, and state machines — ADR 0035 separated them deliberately, and this ADR does
not collapse them. Each surface carries its own `approverSummary` field.

### 7. `issuedAt` (#300)

`ApprovalChallenge` gains an immutable `issuedAt` (`DateTimeImmutable`), sourced from the receipt's issuance time
(`ApprovalReceipt::$createdAt`). A consumer computes "waiting N minutes"; the field carries the instant, not a
pre-computed elapsed value. It touches neither the binding fingerprint nor receipt identity.

### 8. `#201` is a distinct extension point

Cross-invocation lineage is **not** solved by current provenance and must not be inferred from the action
summary. A distinct extension point keeps lineage presentable **alongside** the summary later, never conflated
with it.

## Consequences

- An approver gains the argument-level signal the gate was missing — *which* order, *which* destination — which
  is exactly what distinguishes an injected proposal from a benign one at the only moment it can be rejected.
- The approval payload stops being uniformly thin, but only as far as an application chooses: absence is a
  visible, typed state, and Verdict still never interprets arguments.
- **It does not make the summary trustworthy.** Verdict snapshots what the application chose to show; a wrong
  summary is snapshotted faithfully. The binding fingerprint remains the proof of what is bound.
- **It does not gate by default.** ADR 0007 holds: the summary is information for a human, not a control. Only
  the opt-in strict tier refuses issuance, and only for capabilities whose adopter set that policy.

## Alternatives rejected

**A canonical argument digest as the answer.** Rejected as the *answer* — it already exists as the binding
fingerprint and gives a human nothing to decide with. It is retained as the integrity floor.

**A Verdict-side argument-redaction pipeline as the default.** Rejected: it re-interprets every application's
arguments and is a larger privacy surface than the decision requires. Available to an application through the
closure.

**A bare `?ApproverSummary` for absence.** Rejected: it collides "withheld by policy" and "no summary" with
"pre-feature record." Absence is a typed release state; `null` is reserved for the storage era.

**Unifying the confirmation and review lanes' stores or lifecycles.** Rejected: ADR 0035 keeps their state
machines separate. Only the immutable value and the materialisation service are shared.

**Guaranteeing integrity through the post-commit evidence events.** Rejected: post-commit delivery is
observational. Strict integrity is the synchronous, issuance-blocking attested step, or it is not a guarantee.

**Correlating summary evidence by the binding fingerprint.** Rejected: a binding is not an identity — two
requests may share one. The evidence anchors on `sha256(receiptId)` / `sha256(requestId)`, and the review
lane's existing binding-as-identifier naming is reconciled to it.
