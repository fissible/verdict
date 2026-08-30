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

The pre-read is not the authority and does not need to be atomic with the transition. A receipt
cannot return from terminal to pending, so a read that says "terminal" can never become wrong. A
read that says "decidable" is optimistic, and the store re-validates under its own transaction
before finalizing, so the worst case is an authorizer consulted for a receipt that has since become
undecidable — which then fails on state, as it should.

## Consequences

- An expired or already-decided receipt now reports `expired` or `invalid_state` to a caller whose
  authorizer would also have denied. An application that keyed on `unauthorized` for those cases
  reads a different outcome; the decision is refused in both orders, so nothing that was denied
  becomes permitted.
- The authorizer is consulted strictly less often. An authorizer with side effects — an audit line,
  a metric, a notification — no longer records an attempt against a receipt that was never
  decidable. This is the intended reading of "an authorization decision was made".
- The store contract carries an invariant it did not carry before, and adapter authors must satisfy
  it. It is documented on `approve()` and in the extension-contract inventory.
