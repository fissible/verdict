# Issue 2 — Documentation: authority is not intent

> Scoped 2026-08-16 against `main` @ `b17c713`. Issue body, not a plan to execute.

**Title:** `[Docs] Separate authority from intent in the README and security model`

**Labels:** `documentation`, `area: provenance`

---

## Status before reading further

**This work has landed — PR #182 merged 2026-08-16** (`docs/authority-is-not-intent`), applying the
supplied patch with its line numbers and quoted claims verified against `main` @ `b17c713` first. This issue exists to
record the reasoning and to name what the PR did *not* cover — the starter-pattern question in item 5
remains open — so the remainder is not lost now that the PR has merged.

Verified during that work, and worth keeping because each was a claim that could have been wrong:

- `README.md:42-44` does resolve from `$envelope->proposal->arguments['order_id']`.
- `README.md:69` does say the executor receives "the application-selected execution target—not an
  object supplied by the model."
- `docs/security-model.md:165` does list "an untrusted argument directs an action at the wrong
  resource."
- `ActionContext::$metadata` (`src/Actions/ActionContext.php:14`) is genuinely unread by Verdict —
  the only `->metadata` reads in `src/` are `Decision::$metadata`, a different object. The
  recommended pattern rests on this, so it was checked rather than assumed.

## Problem

Two places where a claim outruns the code.

**The README's flagship example.** It resolves the target from the proposal and then asserts, twenty
lines later, that the executor receives the application-selected target rather than a model-supplied
object. Both statements are individually true. Together they read as a stronger guarantee than the
code delivers: the application selects the *object*, so the model cannot forge an `Order`; the model
still selects *which* order. Under injection the actor is the legitimate user, the user owns the
order, and `can('refund', $order)` returns `true`.

**The threat model bullet.** "The wrong resource" is ambiguous between *outside the actor's
authority* and *not what the user intended*. Verdict addresses the first. Read as the second — which
is how a security-conscious reader will read it — it overclaims.

## Threat model delta

No change to what Verdict does. This is a change to what Verdict *says* it does. The disclosure gets
larger, not smaller.

## What to scope

1. **README quick-example replacement** — resolve from `ActionContext`, with proposal-resolved
   targets kept as a clearly-marked second section showing actor-scoped lookup and stating its bound
   exactly: bounds authority, not intent.
2. **`### Authority is not intent`** in `docs/security-model.md`, inserted in the Authorization
   section (before `### Actor and subject evidence`, `docs/security-model.md:31`), distinguishing
   context-resolved from proposal-resolved targets.
3. **Tightened threat-model bullet** (`docs/security-model.md:165`) plus a following note that
   Verdict does not attempt to determine intent.
4. **New `docs/limitations.md` entry** — *Authorization bounds authority, not intent*.
5. **Open question the draft does not answer:** whether `docs/capability-starter-patterns.md` needs a
   matching context-resolved starter. It currently ships two patterns (refreshed-target and
   one-logical-operation, added in #109). A third would make the safe shape copy-pasteable, which is
   how starters actually get used. **Recommend adding it**, but as a follow-up rather than blocking
   the docs fix, and ideally *after* Issue 1 settles whether there is a named constructor to
   demonstrate — otherwise the starter is written twice.

## Explicitly out of scope

**Softening or removing any existing limitations text.** `docs/limitations.md` is an asset, not a
liability. Nothing here reduces disclosure; the file gains an entry and loses nothing.

This matters because of how the gap was found. The adversarial critique that surfaced it was
accurate on specifics and built almost entirely out of Verdict's own `limitations.md` and ADRs.
Thorough disclosure makes a project more attackable than one documenting nothing. **The correct
response is to fix where a claim outruns the code, not to disclose less.** If a future reader
reaches for this file to justify trimming disclosure, that is a misreading of it.

## Alternatives rejected

**Fix the prose, keep the proposal-resolved example.** Rejected. The flagship example is what gets
copied. Leaving the unsafe-by-default shape in the most-read position while the caveat lives in
`docs/` inverts which one an adopter meets first.

**Remove the proposal-resolved form from the docs entirely.** Rejected. It is a legitimate pattern —
some capabilities exist precisely so a model can choose among candidates. Hiding it would push
adopters to invent it themselves without the actor-scoping that bounds it.

## Tests as spec

Documentation has no unit tests, but it has two mechanical checks that must pass and one that
should be added:

1. `composer verify:claims` — the new limitation carries a claim marker and the count rises (21 → 22
   as drafted). A marker that fails to register is an inert comment.
2. **Cross-link resolution** — the three new anchors resolve, checked by generating heading anchors
   from target files rather than by eye. (Done in PR #182; `#authorization-bounds-authority-not-intent`
   depends on comma-stripping, which is exactly the kind of thing eyeballing misses.)
3. **Pending-on-dependency:** the paired attack-pack case in Issue 1 is what makes this documentation
   *demonstrated* rather than asserted. Until it lands, this issue's claims rest on reasoning.
   Passing it would let the security model cite an executable case instead of an argument.

## ADR impact

**None.** This documents an existing boundary rather than deciding one. If Issue 1 lands a named
constructor, ADR 0024 (proposed there) becomes the record, and this documentation should then link to
it rather than restating the argument.

## Documentation claims introduced

```
<!-- @verdict-claim limitation.intent untestable reason="A package cannot determine whether an authorized action reflects the actor's intent." -->
```

`untestable` is correct and not a dodge: there is no observable runtime property distinguishing an
authorized action the user wanted from an authorized action they did not.

## Dependency order

**First.** Independent of the other three, already drafted, and it is the item where the shipped
claim is currently wrong — which makes it the one with a clock on it.
