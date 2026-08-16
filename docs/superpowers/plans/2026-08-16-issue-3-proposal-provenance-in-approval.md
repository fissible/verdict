# Issue 3 — Proposal provenance in the approval payload

> Scoped 2026-08-16 against `main` @ `b17c713`. Issue body, not a plan to execute.

**Title:** `[Approvals] Surface proposal provenance at the moment of decision`

**Labels:** `enhancement`, `area: provenance`, `scope: design`

---

## A correction to the premise, before the problem statement

The framing this issue was scoped from says `requiresConfirmation()` "shows an approver canonical
facts about the action: amount, destination, target." **It does not.** That was checked, and the
shipped payload is far thinner:

`ApprovalChallenge` (`src/Approvals/ApprovalChallenge.php:12-16`) carries exactly five fields:

```php
public string $receiptId,
public string $toolCallId,
public string $capability,
public ?string $reason,
public DateTimeImmutable $expiresAt,
```

`ApprovalReceipt` (`src/Approvals/ApprovalReceipt.php:12-20`) adds a `$bindingFingerprint` — a
**hash**, not readable facts. The `bindUsing` closure passed to `requiresConfirmation()`
(`src/Capabilities/Capability.php:152-153`) produces the binding that fingerprint is computed from;
it is used for *matching* an approval to an action, not for *display*.

So the approver sees: a capability name, a free-text `reason` the application wrote at registration
time, and an expiry. Any "canonical facts" an approver sees today are rendered by the application
from its own state — Verdict's payload does not carry them.

**This makes the issue larger, not smaller**, and changes its shape: adding provenance is not
"one more field on a payload that already has facts." It requires deciding what the payload carries
at all, and that decision runs straight into ADR 0008.

## Problem

The approval gate asks a human to authorize an action whose *motive* is invisible to them. An
approver cannot distinguish a proposal that originated in the user's own instruction from one that
originated in retrieved content, a tool result, or an untrusted document.

This is the failure mode confirmation fatigue makes worse — and Verdict already documents
confirmation fatigue as a real risk (#39, shipped in v0.4.0). An approver clicking through a tenth
identical-looking refund challenge has no signal that the tenth one came from an injected document.

The material point: **Verdict already records this.** `docs/security-model.md:132` documents
"Tracing declared derivations backward," and the provenance ledger exists. The gap is that
provenance is recorded for **post-hoc audit** and never surfaced at the **moment of decision** — the
one moment where a human could act on it.

## Threat model delta

Closes nothing on its own; the boundary is unchanged. It improves the quality of the human decision
the boundary defers to, which for consequential capabilities is the intent control (Issue 2's
"authority is not intent"). An approval that a human granted without being able to see the
proposal's origin is a weaker control than it appears.

## Design argument

### Attribution: provenance to a proposal, not to an invocation

`docs/limitations.md:139-142` already states the honest position:

> Verdict records a derivation edge only when it observed a transformation directly, such as an
> application context release, or when an application explicitly declared one. It does not infer
> that retrieved content influenced a model output, tool request, or decision merely because the
> records share an invocation. **Missing derivation edges mean "not observed or not declared," not
> "no influence occurred."**

That limitation is load-bearing here. Everything in the invocation shares a correlation id, so
"what content was retrieved during this invocation" is answerable; "what content caused *this
proposal*" is only answerable where a derivation was declared. **The payload must not present
invocation-scoped provenance as proposal-scoped**, because that would manufacture a causal claim the
ledger deliberately refuses to make.

Practical consequence: the payload shows *declared* upstream sources, and says so — not "this
proposal came from X" but "X was declared upstream of this proposal."

### Partial or absent provenance: visible unknown, not silence

When nothing is declared, the payload must show **unknown**, not clean. Silence reads as "no
untrusted sources," which is precisely the inference the limitation forbids. This mirrors the
posture ADR 0021 took for coverage: an absence of evidence is reported as an absence, not as a
passing result.

### Should an unattributable proposal on a consequential capability be denied?

**Argue no, and record why.** Denying would break every application that has not adopted declaration
— which today is all of them, since derivation declaration is opt-in. It would convert a documented
incompleteness into a hard failure at the worst moment, and the pressure would be to declare
something rather than to declare accurately.

The honest alternative is to make the gap *visible and configurable*: unknown by default, with an
opt-in strict mode for applications that have adopted declaration thoroughly enough to trust it.
Same shape as `minimum_observations` in ADR 0021 — Verdict ships the visibility, the adopter sets
the policy.

### Composition with streaming approval

`docs/superpowers/plans/2026-08-10-streaming-approval-context.md` governs how approvals survive a
streamed response. A provenance payload must be assembled **before** the challenge is emitted, not
lazily resolved when an approver opens it, because by then the invocation frame may be gone. This is
the same lifetime problem `VerdictProvenanceMiddleware` already solves for streamed responses by
rewriting the generator in place — the payload should be materialised at challenge-creation time.

### Privacy: this is a context release

**The critical constraint.** [ADR 0008](../../adr/0008-evidence-privacy-model.md) governs the
evidence privacy model, and surfacing source *content* to an approver is a release of application
data to a new audience. It must go through the existing redaction allowlist path rather than around
it.

Note the tension this creates with the `$bindingFingerprint` design: the receipt deliberately
carries a hash rather than facts, which is a privacy-preserving choice. Adding readable provenance
partially reverses that for the approval audience specifically. That is defensible — an approver is
authorised to see what they are approving — but it is a **deliberate widening** and belongs in an
ADR rather than in an implementation.

Safest default: surface *source identity and kind* (a document id, "retrieved content", "tool
result") rather than content, with content release opt-in and allowlisted.

## Alternatives rejected

**Link to the audit trail instead of embedding.** Rejected: the approver is often not the person with
evidence-store access, and a decision that requires a second system is a decision that will be made
without it.

**Infer provenance from invocation correlation.** Rejected: it manufactures the causal claim
`docs/limitations.md:142` explicitly refuses. It would also be *usually right*, which is worse than
usually wrong — it would be trusted.

**Deny unattributable consequential proposals by default.** Rejected above; available as opt-in.

## Tests as spec

1. An attack pack case where a refund proposal derives from injected retrieved content and the
   approval payload **marks it as such**.
2. A case where provenance is undeclared and the payload shows **unknown**, not clean. Mutation
   check: make absence render as empty and confirm this fails.
3. A case asserting **redaction applies** to surfaced source content — an allowlist-excluded field
   does not reach the payload.
4. Payload is materialised at challenge creation, not lazily — assert it survives a streamed response
   whose frame has been released.

   **Pending on dependency — streaming approval context.** Until that plan's work lands, this can be
   asserted only for the synchronous path. Passing the streamed form buys the guarantee that the
   approver sees the same payload regardless of how the response was produced.

5. **Pending on dependency — Issue 1.** A case pairing resolution path with provenance: a
   proposal-resolved consequential capability whose proposal derives from injected content is the
   highest-risk combination, and the payload should show both facts together. Passing it buys the
   first end-to-end demonstration that Verdict can tell an approver *this target was chosen by the
   model, from this untrusted source.*

**Coverage note (ADR 0021/0022):** these belong in `RagBorneInjectionAttackPack`, which already
carries retrieved-document fixtures. Per-case coverage applies; a case the model never triggers
reports unmeasured.

## ADR impact

**New ADR.** Proposed title: *ADR 0025: What an approver is shown, and why it is a context release*.

Thesis: the approval payload is a release of application data to a human audience, governed by
ADR 0008's privacy model rather than exempt from it; Verdict surfaces declared provenance as
declared — never inferred from correlation — and renders absence as a visible unknown, because the
alternative manufactures a causal claim the provenance ledger deliberately refuses to make.

Should cross-reference ADR 0008 (privacy), and amend nothing.

## Documentation claims introduced

```
<!-- @verdict-claim approval.provenance-declared-only tested -->
<!-- @verdict-claim approval.provenance-absence-visible tested -->
<!-- @verdict-claim approval.provenance-redacted tested -->
```

All three testable. None should be phrased as "the approver sees where the proposal came from" —
that is the overclaim this whole set exists to avoid.

## Dependency order

**Third**, and the highest-value of the four. Depends on Issue 1 only for its strongest test, not for
its core. Depends on the streaming-approval-context plan for full coverage.
