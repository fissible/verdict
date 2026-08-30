# ADR 0034: the framing claim never travels without its qualifier

Status: Accepted

## The question

Verdict is described as *"a Laravel security boundary for AI-triggered application actions."*

What it bounds, precisely:

| Bound | Mechanism |
|---|---|
| **Authority** | Laravel policy evaluation on a resolved target |
| **Duplication** | at-most-once execution claims |
| **Rate** | semantic rate limits |
| **Approval** | human confirmation with a bound receipt |

What a reader hears in *"security boundary"* is a fifth thing Verdict does not bound: **intent** —
whether the action reflects what the user actually wanted. Under prompt injection the actor is the
legitimate authenticated user, so an injected instruction selecting any record inside that user's
authority passes every check by design.

That is not a documentation bug a paragraph fixes. `docs/limitations.md` and `docs/security-model.md`
already say it, thoroughly, and the gap is one click away. The question is whether the top-level
framing invites the misreading in the first place.

## Options weighed

1. **Keep "security boundary" unqualified.** Rely on the authority/intent documentation to carry the
   distinction. Zero cost, zero disruption.
2. **Narrow the category to "authorization boundary."** Most precise about the largest mechanism.
3. **Keep the category, qualify the claim** — a standing sentence that ships wherever the description
   appears.
4. **Name the mechanisms in the opening lines**, so "security" is cashed out into a list rather than
   left to the reader's imagination.

## Decision

**Options 3 and 4, as one edit.** The README keeps the wording and gains a following paragraph
naming the four bounds and the one non-bound, ending with the qualifier verbatim: *"It bounds what
an agent may do, not what it was trying to do."* The GitHub repository description carries the same
qualifier in compressed form.

The invariant is that the claim never appears alone. It is enforced, not merely written down:
`tests/Feature/DocumentationConsistencyTest.php` locates the claim and requires the surrounding text
to name all four bounds and disclaim intent. Move the claim and the qualifier moves with it.

## Why not Option 2, the narrowing

**The category is accurate.** A security boundary is a place where a request is checked against a
policy before it proceeds. No security control bounds intent — a firewall does not know whether you
meant to open that connection. Verdict is not unusual in this; it is unusual in saying so.

**Narrowing invites a different misreading.** "Authorization boundary" understates duplication, rate
and approval. A name describing one of four mechanisms is not obviously an improvement.

**The category is how adopters search.** Precision that costs discoverability is a real cost for a
package at developer preview with outside contributors.

Against all that, the case for narrowing is real and worth restating rather than burying: **the
misreading is the expensive one.** A reader who over-trusts "security boundary" deploys a
proposal-resolved consequential capability believing injection is handled, and that fails silently in
production. A reader who under-trusts a narrower name reads further and finds more than they
expected — a strictly better failure mode. The flagship example once demonstrated the unsafe pattern,
and it did so partly because the framing created no pressure to demonstrate the safe path.

The judgement is that a qualifier shipped *with* the claim closes the gap at the point where the
framing gets quoted, which is exactly where the surrounding detail does not follow it — and does so
without the discoverability cost.

## The two nouns are a deliberate split, not drift

`docs/security-model.md` opens *"Verdict is an application-controlled authorization boundary for
AI-triggered actions"*, and `docs/architecture.md` uses the same narrower noun. That is intended.
The headline claim is the one that travels without context and therefore carries the qualifier; the
security model is where precision about the largest mechanism belongs and where a reader has already
committed to the detail.

Do not "fix" one to match the other. Changing either is a change to this decision.

## What would reverse this

Evidence that the misreading happens anyway — an adopter, an issue, a review finding that reads the
qualified claim and still over-trusts it. Then Option 2 becomes the right call, and pre-1.0 is the
moment for it, because after 1.0 it is a breaking change to a package's identity. The
documentation-consistency test names itself as where the new framing would get its own qualifier.

## Not in scope

Renaming the package. `fissible/verdict` describes the output — a verdict on a proposed action — and
is accurate regardless of which framing wins.

## Provenance

This settles a naming round recorded 2026-08-16 on a working branch, which weighed the four options
above and recommended the one taken here. That branch was deleted once the recommendation shipped;
this ADR is the durable record, and it exists because the test above cited a decision document that
otherwise no longer existed anywhere.
