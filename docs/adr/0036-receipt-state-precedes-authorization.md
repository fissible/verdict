# ADR 0036: Receipt state is resolved before authorization

Status: Accepted

## Related issues

- [#320](https://github.com/fissible/verdict/issues/320) is the design round this settles; it was
  surfaced while reviewing PR #318 and named the two orders as Option A and Option B.
- [#305](https://github.com/fissible/verdict/issues/305) / PR #316 added the required
  `ApprovalDecisionAuthorizer` and, with it, the order this document corrects.
- [#167](https://github.com/fissible/verdict/issues/167) (open) is the end-to-end proof that one
  actor cannot consume another's receipt; it exercises the path this ADR orders.
- [ADR 0031](0031-approval-reads-are-observational-and-scoped.md) governs reading approval state.
  This ADR governs the decision path, which is a different surface with a different guarantee: a
  read is observational, a decision transitions.
- [ADR 0029](0029-approval-challenge-issuance-is-the-measured-fact.md) §1: expiry has no transition
  moment. That is why an expired receipt is only ever discovered on a read.
- [ADR 0004](0004-independent-security-state-transactions.md) places receipt transitions in the
  independent security state, which is what makes the store — not its caller — the authority here.

## Context

`ApprovalManager::approve()` and `reject()` consult a required `ApprovalDecisionAuthorizer` before
finalizing a receipt. The authorizer was consulted for any *found, call-matching* receipt, including
one already expired, approved, rejected, or consumed. A denying authorizer then returned
`Unauthorized` and short-circuited — before the store would have reported the receipt's real
terminal state.

The direction was safe: the decision was refused either way, and nothing was finalized. What was
wrong was the reported reason. An operator holding a receipt that expired an hour ago read
`unauthorized` and went looking for a permissions problem. The method's own docblock promised the
store would produce the canonical `NotFound`/`Mismatch`/`Expired`/`InvalidState` outcome, and for a
matched-denied receipt it never got the chance — the promise was false for exactly the receipts an
operator is most likely to be holding.

There is a real argument for the original order, and it is why this needed a decision rather than a
patch. Telling a caller who fails authorization that a receipt is "expired" or "already consumed"
discloses receipt state to someone not entitled to it. A uniform `Unauthorized` discloses nothing.

## Decision

Receipt state is resolved first. The order is:

1. Refuse outright if no authorizer is configured.
2. Resolve the receipt by id and match it against the tool call.
3. Consult the authorizer **only** for a matching receipt that is still decidable — `Pending` and
   unexpired.
4. Return `Unauthorized` only from a denial in step 3. Everything else is the store's.

The manager originates exactly one outcome, `Unauthorized`. `NotFound`, `Mismatch`, `Expired`,
`InvalidState`, `Approved`, and `Rejected` are all produced by the store and returned unaltered —
not rebuilt, not re-wrapped. The only thing the manager decides is whether to consult the authorizer
at all. This is what the "single authority on receipt state" invariant means, stated as something a
test can hold: the returned transition is the store's own.

Fail-closed comes first and is unconditional. With no authorizer configured, `approve()`/`reject()`
throw `ApprovalAuthorizerMissing` whatever the receipt's state, and no decision reaches the store.
Refusing to decide outranks reporting state; a terminal receipt must not become the one path that
answers without an authorizer. Reading a receipt before that refusal is permitted — deciding one is
not.

## Why uniform non-disclosure is rejected

The confidentiality argument does not survive contact with what the decision path already does. It
returns distinct `NotFound` and `Mismatch` *before* authorization — an existence oracle — and the
caller supplied the receipt id in the first place. Returning `Unauthorized` for an expired,
matching receipt therefore concealed nothing the same call discloses one branch earlier. The
original order was neither canonical state reporting nor a credible non-disclosure policy.

The coherent version of that policy — collapse `NotFound`, `Mismatch`, `Expired`, and
`Unauthorized` into one opaque refusal — is explicitly not chosen. It would destroy operator
diagnostics and change the outcome vocabulary on paths this decision does not otherwise touch, in
exchange for a confidentiality property nothing else in the approval surface claims: ADR 0031's
`ApprovalStatusReader` already publishes per-receipt status to the application, scoped by
`approval_context` rather than by who is deciding.

## What this moves onto the store contract

Before this decision, the authorizer ran for every found, matching receipt, so what a store
considered decidable had no bearing on authorization. It does now: the manager skips the authorizer
for a receipt it reads as terminal and calls the store anyway, trusting it to refuse.

`ApprovalReceiptStore` is a stable extension point, so that trust is a stated invariant rather than
an assumption about the two shipped implementations. A decision is admissible only for a receipt
that is call-matching, `Pending`, and unexpired at the supplied instant; everything else must be
refused with the applicable failure outcome. **A store that finalizes a terminal or expired receipt
finalizes it without authorization.** Both shipped stores already satisfy this; a third-party store
that did not was already producing outcomes Verdict does not define.

## The race is benign

The pre-read is not the authority and is not atomic with the transition. A receipt cannot return
from terminal to pending, so a read that says "terminal" can never become wrong. A read that says
"decidable" is optimistic: the receipt may expire or be decided before the store transitions it, in
which case the authorizer *is* consulted and the store then refuses on state — the outcome is still
canonical, and nothing is finalized.

So the guarantee this decision makes is deliberately the narrower one. A receipt that is already
terminal or expired **when it is read** never reaches the authorizer and reports its own state.
A receipt that becomes terminal in the window between that read and the transition still reaches
the authorizer. This is an ordering and reporting decision, not an atomic
state-before-authorization guarantee, and it does not need to be one: the store's transition is
atomic (`DatabaseApprovalReceiptStore` under a locked security-state transaction, per ADR 0004),
and that is where admission is actually decided.

## Consequences

- An expired or already-decided receipt now reports `expired` or `invalid_state` to a caller whose
  authorizer would also have denied. An application that keyed on `unauthorized` for those cases
  reads a different outcome; the decision is refused in both orders, so nothing that was denied
  becomes permitted.
- The authorizer is never consulted more often than before, and never for a receipt that was
  already terminal or expired when it was read. An authorizer with side effects — an audit line, a
  metric, a notification — stops recording attempts against those receipts, which is the intended
  reading of "an authorization decision was made". It still records one for a receipt that goes
  terminal inside the window described above.
- The store contract carries an invariant it did not carry before, and adapter authors must satisfy
  it. It is documented on `approve()` and in the extension-contract inventory.

## Update (#436): the shortcut is taken only for a store that declares it refuses an inadmissible decision

The section above — *What this moves onto the store contract* — understated what it was doing. It
recorded a new obligation on `ApprovalReceiptStore` as though documenting it were enough. It was not,
for a reason the original text names without following through: `ApprovalReceiptStore` is labelled
**Stable**, "intended to remain compatible through Verdict 1.0".

Before this ADR, the authorizer ran for every found, call-matching receipt, so a store that was lax
about receipt state was still covered — the authorizer ran, and a denial stopped the decision. After
it, the manager skips the authorizer for a receipt it reads as inadmissible and delegates anyway. A
store written against v0.14.0, unchanged and still compiling, therefore acquired an authorization
hole on upgrade, with no signal but prose in three documents. That is worse than a signature break,
which at least fails at load: this one is compile-clean, and its failure mode is a missing
authorization check.

**The decision, unchanged in substance and narrowed in reach.** The shortcut stands, and is taken for
a store that declares `Fissible\Verdict\Contracts\EnforcesDecisionAdmissibility` — a marker by which
a store states that `approve()`/`reject()` atomically refuse any receipt that is not call-matching,
`Pending`, and unexpired. For such a store everything above holds exactly: an inadmissible receipt
bypasses the authorizer, the store produces the canonical `Expired`/`InvalidState`, and the manager
returns that transition unaltered.

**A store that does not declare it keeps the pre-#320 order.** Every found, call-matching receipt
reaches the authorizer first, whatever its state; a denial returns `Unauthorized` and nothing is
delegated, so a lax store is never handed a decision to mishandle. Both shipped stores declare the
marker, so a default install is unaffected.

**What an undeclared store gives up is the reporting fidelity this ADR was written to add.** A
denying authorizer masks `Expired` and `InvalidState` again — the exact defect §*Context* opens with.
That is the price of compatibility and it is deliberate: an authorization hole is a worse failure
than a misreported outcome, and the two properties move together the moment the store declares.

**The marker is a claim, not a verification.** Verdict cannot prove an external store's atomic state
semantics, and does not try — conformance belongs in an adapter's own contract tests. What the marker
changes is who bears the consequence of being wrong: a store that declares and then finalizes an
inadmissible receipt has broken its own stated promise, where before the package had quietly assumed
one it never asked for.

**A decorator does not inherit the declaration, and that is the safe direction.** An application
wrapping a shipped store for logging or tenancy produces an object that is not `instanceof` the
marker, so the manager falls back to authorizing everything. This is the most likely way a real
deployment reaches the compatibility path — more likely than a genuinely old store — and the fallback
costs an authorizer call, not a security property.

This is the same shape as the #425 correction in PR #435, and deliberately so: an `Experimental`
opt-in interface beside a `Stable` contract, shipped implementations adopting it, and existing
adapters unchanged and safe. A `Stable` label constrains how a fix may be shaped. Recording a break
is not shaping it.
