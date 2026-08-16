# Naming decision: what Verdict says it bounds

Status: **Open — Allen's call.** A default is recommended below; nothing has been changed.

Recorded 2026-08-16, alongside the authority/intent scoping in
[`docs/superpowers/plans/`](docs/superpowers/plans/).

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

This is not a documentation bug that a paragraph fixes. It is a question about whether the top-level
framing invites the misreading in the first place.

## The case for keeping the framing

**It is accurate.** A security boundary is a place where a request is checked against a policy before
it proceeds. That is exactly what Verdict is. No security control bounds intent — a firewall does not
know whether you meant to open that connection, and an IAM policy does not know whether you meant to
delete that bucket. Verdict is not unusual in this; it is unusual in *saying so*.

**Narrowing invites a different misreading.** "Authorization boundary" is more precise about the
authority half but understates the other three: duplication, rate, and approval are not
authorization. A name that describes one of four mechanisms is not obviously an improvement.

**The category is how adopters search.** Someone looking for this package is looking for AI agent
security, not for "an authorization-and-idempotency-and-rate boundary." Precision that costs
discoverability is a real cost for a package at developer preview with outside contributors.

**The disclosure already exists and is unusually thorough.** `docs/limitations.md` runs to fourteen
entries, `docs/security-model.md` has a threat model with explicit in-scope framing, and the
authority/intent gap is being added to both. The gap between framing and detail is one click.

## The case for narrowing

**The misreading is the expensive one.** A reader who over-trusts "security boundary" deploys a
proposal-resolved consequential capability and believes injection is handled. The failure is silent
and lands in production. A reader who *under*-trusts a narrower name reads further and finds more
than they expected — a strictly better failure mode.

**The flagship example demonstrated the unsafe pattern.** That is now being fixed, but it happened,
and it happened because the framing did not create pressure to demonstrate the safe path. A name that
foregrounds *authority* would have made the proposal-resolved example feel like it needed a caveat.

**The adversarial review found this by reading Verdict's own docs.** The specifics were accurate and
built almost entirely from `limitations.md` and the ADRs. Thorough disclosure makes a project *more*
attackable than one that documents nothing — which is a reason to fix where claims outrun code, not
a reason to disclose less. But it does mean the top-line framing is the part doing the most
unsupervised work, because it is what gets quoted without the surrounding material.

**Pre-1.0 is when naming is cheap.** After 1.0 it is a breaking change to a package's identity.

## Options

1. **Keep "security boundary."** Rely on the authority/intent documentation now landing to carry the
   distinction. Zero cost, zero disruption.
2. **Narrow to "authorization boundary."** Most precise about the largest mechanism; understates
   duplication, rate, and approval.
3. **Keep the category, qualify the claim.** Retain "security boundary for AI-triggered application
   actions" and add a standing one-line qualifier wherever the description appears — README opening,
   `composer.json` description, repository description, blog copy: *"It bounds what an agent may do,
   not what it was trying to do."*
4. **Rename the mechanisms, not the category.** Leave the description and make the four bounds
   explicit in the opening lines, so "security" is immediately cashed out into a list rather than
   left to the reader's imagination.

## Recommendation

**Option 3, with Option 4's opening-lines change as the same edit.**

The category is right and the discoverability cost of narrowing is real. What is missing is not
precision in the noun; it is that the noun currently travels alone. A qualifier that ships *with* the
description — in the README's first screen, in `composer.json`, in the repo description — closes the
gap at the point where the framing gets quoted, which is exactly where the detail does not follow it.

Concretely: keep *"a Laravel security boundary for AI-triggered application actions,"* and never let
it appear without a following sentence naming the four bounds and the one non-bound.

This is a default, not a decision. If the assessment is that the misreading is common enough to
warrant the discoverability cost, Option 2 is defensible and pre-1.0 is the moment for it.

## Not in scope

Changing the package name. `fissible/verdict` describes the output — a verdict on a proposed action —
and that is accurate regardless of which framing wins.
