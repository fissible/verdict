# ADR 0025: Target provenance is proven where it can be, declared nowhere

Status: Accepted

## Related issues

- [#192](https://github.com/fissible/verdict/issues/192) is the mechanism this ADR settles.
- [#187](https://github.com/fissible/verdict/issues/187) demonstrated the gap it closes, as a
  deterministic differential.
- [#182](https://github.com/fissible/verdict/pull/182) documented the boundary — *authority is not
  intent* — that this makes structural.
- [ADR 0017](0017-configuration-identity-in-evidence.md) excludes closures from the configuration
  fingerprint; this ADR inherits that limit and says so.
- [ADR 0015](0015-authority-propagation.md) — `ActionContext::$subject` is a principal, not a
  resource, so it is not the slot for a target.

## Context

`Capability::usingPolicy()` takes a `resolveTarget` closure receiving an `ActionEnvelope`
(`src/Capabilities/Capability.php:117`). `ActionEnvelope` carries both channels as public properties:

```php
public ActionProposal $proposal,   // model output — attacker-controlled under injection
public ActionContext $context,     // application-built, never read by Verdict
```

A resolver may read either. Both are one property access away, identical at the call site, and
nothing in the type system, the API, or the recorded evidence distinguishes them.

The security properties are not identical. A proposal-resolved target lets an injected instruction
choose **which record** is acted on; scoping the lookup to the actor bounds *authority*, so the
action cannot reach a record the actor could not reach themselves, but it does not establish that the
actor chose this one. A context-resolved target cannot be redirected at all: the resolver reads from
state the model never touches.

#187 made this executable rather than argued. Two capability configurations, identical injected
argument, differing only in resolver: the proposal-resolved arm discloses the injected record, the
context-resolved arm discloses the intended one.

Verdict's authorization layer is correct on both paths. Only one of them bounds selection.

## Decision

**Where the property can be proven, prove it. Where it cannot, do not claim it.**

### 1. A named constructor whose resolver cannot see the proposal

```php
Capability::usingPolicyForContextTarget(
    name: 'orders.refund',
    ability: 'refund',
    resolveTarget: fn (ActionContext $context): Order => Order::findOrFail($context->metadata['order_id']),
);
```

The resolver receives an `ActionContext`, not an `ActionEnvelope`. The proposal is not in scope, so
it cannot be read. **This is enforced by PHP's parameter types, not by a promise** — which is the
entire reason to prefer it over a declaration.

The existing `usingPolicy()` is unchanged and remains correct for capabilities that legitimately let
a model choose among candidates.

### 2. Resolution path is recorded per decision, not in the configuration fingerprint

`DecisionEvidence` records which path a capability used, so an auditor can query the population that
matters: *proposal-resolved consequential capabilities*.

It does not go in ADR 0017's configuration fingerprint. That fingerprint answers "did the
configuration change" and is opaque by design — a hash. An auditor filtering a population needs a
queryable value, not one they must recompute a fingerprint to interpret. Recording the same fact in
both places would be redundant; recording it only in the fingerprint would make it unusable for the
question it exists to answer.

### 3. No declared-source option

A `->resolveTargetFrom(TargetSource::Context)` declaration was considered and rejected. The closure
would still receive an `ActionEnvelope`; a resolver could declare `Context` and read
`$envelope->proposal->arguments` on the next line, and nothing would notice.

That is [ADR 0017](0017-configuration-identity-in-evidence.md)'s closure exclusion in a new place —
*"a change to resolver or executor logic with identical configuration is invisible"* — and
`docs/limitations.md` already carries it as a limitation. Shipping a second declaration that looks
like a guarantee and is not would make the surface less honest, not more.

**Consequence:** a capability built with `usingPolicy()` is recorded as proposal-resolved even if its
resolver happens to read only from context. Verdict cannot see inside the closure, so it reports the
constructor that was used, and nothing stronger. The evidence field names what was *chosen*, not what
was *verified about the closure body*.

## Consequences

**What this proves.** A capability built with `usingPolicyForContextTarget()` cannot have its target
redirected by proposal arguments. That is a type-level property, not a convention.

**What it does not prove**, stated because each is a place a reader would otherwise generalise:

- **The executor is unconstrained.** It receives the full `AuthorizedAction` and may read the
  proposal. Only target *selection* is bounded.
- **The application may still smuggle proposal-derived data into `ActionContext`** before
  constructing it. Verdict cannot detect that, and the context channel's trustworthiness remains the
  application's to maintain.
- **Intent is still not determined.** `limitation.intent` stays `untestable`. This bounds which
  record is acted on; it says nothing about whether the actor wanted the operation. The limitation
  file's entry is unchanged.

An adopter must choose a constructor, and the choice is now visible in evidence. For capabilities
where a model legitimately selects among candidates, proposal resolution stays available and
correct — the recorded path makes those auditable rather than discouraged.

## Alternatives rejected

**A declared source, enforced by nothing.** Rejected above — it inherits ADR 0017's blindness while
looking like a guarantee.

**A typed `ActionContext::$targets` slot instead of `$metadata`.** Deferred rather than rejected. It
improves legibility and gives evidence a stable key, but it does not by itself stop a resolver reading
the proposal, so it complements the named constructor rather than replacing it. Adding a second
blessed location now, while `$metadata` is documented and in use, would invite "which one do I use"
without a rule to answer it. Worth revisiting if the constructor lands and the ad-hoc `$metadata`
convention proves unclear in practice.

**Documentation only.** Rejected as the whole answer; adopted as part of it via #182. The failure mode
is silent, which is the same argument [ADR 0020](0020-live-trial-isolation-is-application-owned.md)
used to reject documenting a trial-idempotence requirement instead of enforcing it.

**Deprecating `usingPolicy()`.** Rejected. Proposal-resolved targets are a legitimate pattern —
some capabilities exist precisely so a model can choose. Deprecating it would push adopters to invent
the pattern themselves without the actor-scoping that bounds it.
