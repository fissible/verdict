# ADR 0022: Coverage adequacy applies per case, gated by eligibility

Status: Accepted

## Related issues

- [#174](https://github.com/fissible/verdict/issues/174) raised the per-case policy question this ADR settles.
- [#138](https://github.com/fissible/verdict/issues/138) / [ADR 0021](0021-coverage-adequacy-gates-a-live-verdict.md)
  settled the purpose-level rule this ADR extends, and deferred the per-case question deliberately.
- [#170](https://github.com/fissible/verdict/issues/170) is why the question stopped being deferrable — see *Why now*.

## Context

ADR 0021's coverage rule operates per purpose: a threshold reports `INSUFFICIENT` when its
measurable-but-unmeasured outcomes outnumber its evaluated ones, summed across all of the purpose's
cases. Summing is exactly where a case-shaped hole hides:

| Case | passed | failed | unmeasured |
|---|---|---|---|
| `cross-principal-order-lookup` | 25 | 0 | 0 |
| `cross-principal-cancellation` | 0 | 0 | 25 |

Purpose totals: 25 evaluated, 25 measurable-but-unmeasured. Not a strict majority, so the purpose-level
rule passes, the rate is 100%, and the threshold reports `MET` — while one of the pack's two attacks was
never once observed being blocked.

### Why now

v0.7.0's control arm ([#170](https://github.com/fissible/verdict/issues/170)) measures a 2×2 *per case*:
guarded denied or executed, against control executed or declined. Landing that comparison on a
purpose-level adequacy rule would let it read as complete while a whole control went unmeasured — the
same class of defect ADR 0021 closed one level up.

## Decision

**The coverage gate extends to cases, and eligibility decides which cases it may oblige.**

- A case is **eligible** if it has a measurable population: at least one evaluated or
  measurable-but-unmeasured outcome across the run. A case that is entirely structurally unavailable
  (`not_expressible`, `pending`) has no measurable population and is exempt — obliging it would make any
  suite containing one permanently insufficient, for a property of the suite rather than of the run.
- **Every eligible case must have at least one evaluated outcome.** An eligible case with zero makes its
  purpose's disposition `Insufficient`, regardless of the purpose-level sums. A purpose can therefore
  never report `MET` while an attack inside it was never once observed.
- The purpose-level rule of ADR 0021 is unchanged and still checked; the per-case floor is a further
  coverage condition, not a replacement. The exit contract needs no special case: `Insufficient`
  already exits non-zero.

The floor is deliberately the weakest rule that catches the table above. A case observed once out of
twenty-five trials satisfies it: *thinly* observed is surfaced, not gated —

- both renderers print each case's `evaluated / measurable but unmeasured / structurally unavailable`
  counts beside its score, mirroring how ADR 0021 surfaced the purpose-level counts;
- an `INSUFFICIENT` caused by the per-case floor **names** the never-measured cases in the threshold's
  coverage summary, because the disposition is unactionable without knowing which attack went
  unobserved (the clause is silent when nothing at all was measured — `NOT EVALUATED`'s zero counts
  already say so);
- the report file carries the same three counts per case, additive to the v1 schema. The purpose-level
  coverage is exactly the sum of its cases', so nothing else in the schema changes meaning.

Case ids reaching the `--format=github` output through the named clause are free text, so they pass
through the same message escaping as every other emitted character, proven end-to-end by a
hostile-case-id test.

**This is still a coverage adequacy floor, not a statistical confidence claim** — ADR 0021's caveat
carries over per case. One observation of a case says the attack was observed being handled once; it
does not validate a rate for that case.

## Consequences

**This is a behaviour change.** A run whose purpose totals pass the majority rule while an eligible case
went entirely unmeasured previously reported `MET`; it now reports `INSUFFICIENT` and exits non-zero.
That is deliberate: such a run never observed one of the attacks it claims to have evaluated.

A genuinely stochastic model that declines a given case on every trial of a run will now hold its purpose
at `INSUFFICIENT` until some trial produces an observation for that case. That is the intended reading:
raise the trial count, or fix the harness shape that prevents the case from being observed.

## Alternatives considered

**Report-only (per-case counts without a gate).** Adopted as a component, rejected as the whole answer,
for ADR 0021's reason one level down: it leaves the machinery asserting `MET` on a pack whose attack it
knows was never observed, and puts the burden back on a human reading carefully.

**Applying the majority rule per eligible case.** Rejected. Per-case populations are trial-count-sized,
so a majority test per case is noisy where the purpose-level one is not, and reports `INSUFFICIENT` for
a model that is merely stochastic — a much stronger claim than "never observed".

**A configured per-case observation floor (sibling of `minimum_observations`).** Rejected for now, for
the reason ADR 0021 rejected a configured floor as the whole answer: default-off ships the hole in the
default configuration. The always-on eligibility floor needs no configuration; a per-case sibling of
`minimum_observations` can be added later without disturbing it, if an adopter asks for one.

**Requiring every case to be measured, eligibility notwithstanding.** Rejected, as in ADR 0021:
unsatisfiable for any suite containing a non-expressible case — the workbench storefront suite has four
of ten.
