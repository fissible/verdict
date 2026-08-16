# Issue 1 — First-class context-resolved targets

> Scoped 2026-08-16 against `main` @ `b17c713` (Merge pull request #180). Issue body, not a plan to
> execute. Line numbers cited below are from that commit.

**Title:** `[Capabilities] Make context-resolved targets a first-class, evidence-visible choice`

**Labels:** `enhancement`, `area: provenance`, `scope: design`

---

## Problem

`Capability::usingPolicy()` takes a `resolveTarget` closure that receives an `ActionEnvelope`
(`src/Capabilities/Capability.php`). `ActionEnvelope` carries both channels as public properties
(`src/Actions/ActionEnvelope.php:14-15`):

```php
public ActionProposal $proposal,
public ActionContext $context,
```

So a resolver may read `$envelope->proposal->arguments[...]` — model-supplied — or
`$envelope->context->metadata[...]` — application-supplied. **Both are one property access away and
identical at the call site.** Nothing in the type system, the API, or the recorded evidence
distinguishes them.

The two have materially different security properties:

- `ActionProposal::$arguments` is model output. Under prompt injection it is attacker-controlled.
- `ActionContext` is constructed by application code inside the `Verdict::bound(context: ...)`
  closure and carried through unchanged. Its `$metadata` array
  (`src/Actions/ActionContext.php:14`) is never read by Verdict — the only `->metadata` reads in
  `src/` are `Decision::$metadata`, a different object. It is already a trusted channel.

`README.md:42-44` demonstrates the proposal-resolved form as the flagship example. The safe pattern
is reachable today and **undemonstrated and unenforced, not absent**.

`ActionContext::$subject` is not the right slot for a target. Per
[ADR 0015](../../adr/0015-authority-propagation.md) a subject is a *principal* — on whose behalf the
actor acts — not a resource.

## Threat model delta

`docs/security-model.md:165` lists "an untrusted argument directs an action at the wrong resource"
as in-scope. Verdict addresses the *outside-the-actor's-authority* reading of that. It does not
address the *not-the-record-the-user-meant* reading, because under injection the actor is the
legitimate user and `can('refund', $order)` returns `true` for any order they own.

This issue does not close that gap — nothing can close it by authorization alone. It makes the
safe path the short path, and makes the choice **auditable after the fact**, which is the part
Verdict can actually deliver.

## Design argument

Four options, argued rather than listed.

### (a) A named constructor whose resolver cannot see the proposal

```php
Capability::usingPolicyForContextTarget(
    name: 'orders.refund',
    ability: 'refund',
    resolveTarget: fn (ActionContext $context): Order => Order::findOrFail($context->metadata['order_id']),
);
```

**This is the only option that makes the guarantee structural rather than declared.** The resolver
is handed an `ActionContext`, not an `ActionEnvelope`; the proposal is not in scope, so it cannot be
read. The property is enforced by PHP's parameter types, not by a promise.

Cost: a second constructor, and a capability that legitimately needs both channels (target from
context, *arguments* from the proposal for the executor) must use the existing form. That is
acceptable — the executor still receives the full `AuthorizedAction`.

### (b) A declared source: `->resolveTargetFrom(TargetSource::Context)`

Cheaper and composable with the existing constructor, but **Verdict cannot enforce it.** The closure
still receives an `ActionEnvelope`. A resolver could declare `Context` and read
`$envelope->proposal->arguments` on the next line, and nothing would notice.

This is exactly the shape of [ADR 0017](../../adr/0017-configuration-identity-in-evidence.md)'s
closure-hashing exclusion: *"a change to resolver or executor logic with identical [configuration]
is invisible"* (ADR 0017:77, and `docs/limitations.md:108` — "A configuration fingerprint does not
cover resolver or executor logic"). The declarative record cannot see inside the closure. **Any
option that declares rather than proves inherits that gap and must say so in the same register
limitations.md uses.**

### (c) A typed `ActionContext::$targets` slot

Replaces ad-hoc `$metadata` use with something named. Improves legibility and gives evidence a
stable key to record. It does not by itself stop a resolver reading the proposal, so it is a
complement to (a) or (b) rather than an alternative to them.

Worth weighing against the cost: `$metadata` is a documented public property today, so adding a slot
is additive, but a second blessed location invites "which one do I use" without a clear rule.

### (d) Documentation only

Rejected as the whole answer. It is the status quo plus prose, and the failure mode is silent — the
same argument [ADR 0020](../../adr/0020-live-trial-isolation-is-application-owned.md) used to reject
"document a trial-idempotence requirement and change no code." A subtle requirement pushed onto
every adopter fails quietly when missed.

However: **the documentation half is being done separately and first** — see Issue 2. This issue is
the structural half.

### Recommendation

**(a) with (c)**, and explicitly *not* (b) alone. Prove where proving is cheap; do not ship a
declaration that looks like a guarantee.

### Can Verdict prove a resolver did not read the proposal?

Under (a), yes — by construction, for the resolver. It cannot prove the *executor* did not read the
proposal, and it cannot prove the application did not smuggle proposal-derived data into
`ActionContext` before constructing it. Both residuals should be stated rather than glossed.

If a declaration-only option is chosen anyway, evidence must represent it honestly: a field named
for what it is (`target_source_declared`), never one that reads as verified.

## Evidence requirement

Decision evidence should record which resolution path a capability used, so an auditor can query for
*proposal-resolved consequential capabilities* — the population where injected selection is possible.

**Per-decision field, not the configuration fingerprint.** ADR 0017's fingerprint covers *declarative
configuration*, and it deliberately excludes closures. Resolution path is a property of the
capability's declaration, so it *could* live there — but the fingerprint is opaque by design
(a hash), and an auditor filtering for a population needs a queryable column, not a value they must
recompute a fingerprint to interpret. Record it as a per-decision field; if the capability's
declaration changes, ADR 0017's existing machinery already detects that separately.

This does mean one fact recorded in two places. That is the correct trade: the fingerprint answers
"did the configuration change," the field answers "which path did this decision take."

## Tests as spec

1. A capability declared context-resolved **cannot access proposal arguments** — under option (a),
   this is a type-level test: the resolver signature takes `ActionContext`, and a test asserts the
   closure receives no envelope. Mutation check: widen the parameter back to `ActionEnvelope` and
   confirm a test fails.
2. **Evidence records the resolution path** for both a context-resolved and a proposal-resolved
   capability, and the two differ. Mutation check: hardcode one value and confirm failure.
3. `ActionContext::$metadata` remains unread by Verdict — a regression test asserting no framework
   read path, so the trusted-channel claim the docs now make stays true.
4. **Attack pack case (paired):** injected arguments name a *different record owned by the same
   actor*. The context-resolved capability acts on the correct record; the proposal-resolved variant
   acts on the injected one.

   **Pending on dependency — ADR 0023 control-arm pairing.** This case is only meaningful as a
   guarded/unguarded pair, because the finding is *"the same injection redirects one and not the
   other."* Until the control arm ships, the case can assert the context-resolved half only, which
   proves the safe path works but not that the unsafe path is unsafe. Passing the paired form buys
   the first executable demonstration that target provenance — not authorization — is what stops
   redirection.

   **Coverage note (ADR 0021/0022):** this case belongs in `StorefrontAttackPack`, which already has
   the actor/order fixtures. Per-case coverage adequacy applies, so if the model never attempts the
   redirect the case reports unmeasured rather than passing.

## ADR impact

**New ADR.** Proposed title: *ADR 0024: Target provenance is declared where it cannot be proven*.

Thesis: Verdict distinguishes context-resolved from proposal-resolved targets because only the first
bounds *selection*; where the distinction can be enforced by type it is enforced, and where it can
only be declared it is recorded as a declaration and never as a verified property — the same honesty
ADR 0017 applies to closure hashing.

Amends nothing, but should cross-reference ADR 0015 (subject is a principal, not a resource) and
ADR 0017 (the closure gap this inherits).

## Documentation claims introduced

```
<!-- @verdict-claim capability.context-resolved-target tested -->
<!-- @verdict-claim evidence.resolution-path-recorded tested -->
```

Both are testable. If option (b) is chosen instead, the first becomes
`untestable reason="Verdict cannot inspect closure bodies to confirm a declared source."`

## Dependency order

Issue 2 (docs) first — it is already drafted and does not depend on this. **This issue is second**,
because Issue 3's approval payload work benefits from resolution path being recordable. Issue 4 is
independent.
