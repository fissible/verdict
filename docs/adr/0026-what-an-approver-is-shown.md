# ADR 0026: What an approver is shown, and why it is a context release

Status: Accepted

## Related issues

- [#195](https://github.com/fissible/verdict/issues/195) is the work this settles.
- [ADR 0008](0008-evidence-privacy-model.md) governs what evidence may contain and who may see it.
- [ADR 0007](0007-evidence-layering.md) — evidence is not an authorization gate; this ADR does not
  make it one.
- `docs/limitations.md` — *Provenance derivation is deliberately incomplete* is the constraint every
  decision below is shaped by.

## Context

`requiresConfirmation()` asks a human to authorize an action. What that human is shown today is
`ApprovalChallenge` (`src/Approvals/ApprovalChallenge.php:12-16`): a receipt id, a tool-call id, a
capability name, a free-text `reason` written at registration time, and an expiry.
`ApprovalReceipt` adds a `bindingFingerprint` — a hash, used to match an approval to an action, not
to describe it.

So the payload carries **no facts about the action** and, in particular, nothing about *why* it was
proposed. An approver cannot distinguish a proposal that originated in the user's own instruction
from one that originated in retrieved content, a tool result, or an untrusted document.

This is the failure mode confirmation fatigue makes worse. An approver clicking through a tenth
identical-looking refund challenge has no signal that the tenth one came from an injected document.

Verdict already records what would answer this. `ProvenanceLedger` holds entries carrying `Source`,
`Trust`, `DataClass`, `ContextChannel`, and a content fingerprint, and
`backwardReachableContentFingerprints()` walks declared derivations. **The gap is not that the
information is missing. It is that provenance is recorded for post-hoc audit and never surfaced at
the moment a human decides.**

## Decision

### 1. The payload is a context release, not an exemption from one

Surfacing anything about upstream content to an approver releases application data to a new
audience. It is governed by [ADR 0008](0008-evidence-privacy-model.md) and goes through the existing
allowlist path rather than around it.

Note the tension this creates, and that it is deliberate: the receipt carries a `bindingFingerprint`
rather than readable facts precisely because hashes are privacy-preserving. Widening that for the
approval audience is a considered exception — an approver is authorised to see what they are
approving — not an oversight being corrected.

### 2. Source identity and kind, not content

The default payload surfaces what a `ProvenanceEntry` already classifies: **source, trust, data
class, and channel**. Not the content itself.

This is not a compromise. It is the information an approver actually needs — *this proposal has an
untrusted retrieved document upstream of it* is the decision-relevant fact; the document's text is
not, and releasing it is a far larger privacy step for a marginal gain. Content release stays
possible, opt-in, and allowlisted.

### 3. Declared derivations only, never correlation

`docs/limitations.md` states it already:

> Verdict records a derivation edge only when it observed a transformation directly … It does not
> infer that retrieved content influenced a model output, tool request, or decision merely because
> the records share an invocation. **Missing derivation edges mean "not observed or not declared,"
> not "no influence occurred."**

Everything in an invocation shares a correlation id, so *what content was retrieved during this
invocation* is trivially answerable. *What content caused this proposal* is answerable only where a
derivation was declared.

**The payload shows declared derivations and says that is what they are.** It does not present
invocation-scoped entries as upstream of the proposal, because that manufactures a causal claim the
ledger deliberately refuses to make — and it would be *usually right*, which is worse than usually
wrong, because it would be trusted.

### 4. Absence is rendered as a visible unknown

When no derivation is declared, the payload shows **unknown**, never an empty list and never
silence. Silence reads as "no untrusted sources," which is exactly the inference the limitation
forbids.

This mirrors [ADR 0021](0021-coverage-adequacy-gates-a-live-verdict.md)'s posture for coverage and
[ADR 0024](0024-integrity-is-gated-before-coverage.md)'s for integrity: an absence of evidence is
reported as an absence, not as a clean result.

### 5. An unattributable proposal is not denied by default

A consequential capability whose proposal has no declared provenance is **not** refused.

Denying would break every application that has not adopted derivation declaration — which today is
all of them, since declaration is opt-in. It would convert a documented incompleteness into a hard
failure at the worst possible moment, and the pressure it created would be to declare *something*
rather than to declare accurately.

The honest alternative is to make the gap visible and configurable: unknown by default, with an
opt-in strict mode for applications whose declaration coverage they trust. Same shape as
`minimum_observations` in ADR 0021 — Verdict ships the visibility, the adopter sets the policy.

### 6. The payload is materialised when the challenge is created

Not resolved lazily when an approver opens it. By then the invocation frame may be gone —
`VerdictProvenanceMiddleware` rewrites a streamed response's generator precisely because that frame's
lifetime is shorter than the work that depends on it. A payload assembled at challenge-creation time
is the same shape of fix, and it means an approver sees the same payload regardless of how the
response was produced.

### Update: where "challenge creation" turned out to be

Implementation moved the materialization point from challenge creation to **receipt issuance,
persisted on the receipt**. `ApprovalChallenge` is not built when the approval is requested — it is
built by `challengeForToolCall()` when an approver's controller asks for it, in a separate request
with no invocation frame, so assembling there would report unknown for every proposal.

The payload is therefore assembled in `ApprovalManager::issue()`, inside the frame, and stored in a
nullable `provenance` column on the approval receipt. §6's intent is unchanged and is what the
durable form delivers: one payload, assembled while the invocation is in scope, identical regardless
of how the response was produced. A null column means a receipt issued before Verdict captured this
at all — a storage era, not a disclosure state, which is why it is not a case of
`ProvenanceDisclosure`.

## Consequences

An approver gains a decision-relevant signal that Verdict already had and was discarding at the only
moment it could have been acted on.

**What this does not do**, each stated because it is where a reader would generalise:

- **It does not make provenance complete.** The payload is only as good as the declarations an
  application makes, and `docs/limitations.md` already says those are deliberately incomplete. A
  proposal with no declared upstream may still have been influenced by one.
- **It does not gate.** ADR 0007 holds: this is information for a human, not a control. A missing
  provenance record never blocks an action.
- **It does not tell an approver what the action will do.** The payload still carries no canonical
  facts about amount, destination, or target — those are the application's to render. This ADR adds
  provenance, not a general payload design.

That last point is worth stating plainly: **the approval payload remains thin.** Whether Verdict
should carry canonical action facts is a real question and a separate one, and answering it by
reflex inside a provenance change would be the wrong way to decide it.

## Alternatives rejected

**Link to the audit trail rather than embedding.** Rejected: the approver is frequently not the
person with evidence-store access, and a decision that requires a second system is a decision made
without it.

**Infer provenance from invocation correlation.** Rejected — see §3. It manufactures the causal claim
the ledger refuses to make, and its usual correctness is what makes it dangerous.

**Deny unattributable consequential proposals by default.** Rejected — see §5; available as opt-in.

**Surface content by default.** Rejected. A far larger privacy step than the decision requires, for
information an approver does not need to reach the decision.
