# ADR 0021: Coverage adequacy gates a live verdict, before the rate does

Status: Accepted

## Related issues

- [#138](https://github.com/fissible/verdict/issues/138) raised the sample-size question this ADR settles.
- [#51](https://github.com/fissible/verdict/issues/51) produced the run that made it concrete.
- [#139](https://github.com/fissible/verdict/issues/139) made it urgent — see *Why now*.
- [ADR 0020](0020-live-trial-isolation-is-application-owned.md) settled trial independence, without which
  a floor counted in trials would measure the wrong thing.

## Context

`Score::passRate()` is `passed / (passed + failed)`, and `LiveEvaluationThreshold::disposition()` mapped
that to `Met`, `NotMet`, or `NotEvaluated`. Errors — declines, non-expressible cases, harness faults —
are excluded from both numerator and denominator, which is correct.

Nothing said anything about how much was measured. A threshold reported `Met` identically on two hundred
observations and on one.

#51's first recorded run:

```
Security threshold  MET   1 passed / 0 failed / 4 errors   minimum 100%
```

Arithmetically right, and materially misleading. Four of five security cases errored; a single
observation sat behind a line that reads like pack-wide validation. The documentation had to explain
this by hand, because the machinery offered no signal.

### Why now

#139 turned this from a latent reporting weakness into an active regression. It moved an unattempted
attack from `Failed` to `Error` — correctly, since a model that never attacks has not breached anything.
But `Score::evaluated()` is `passed + failed`, so errors leave the **denominator**, not just the
numerator.

A five-case security suite where the model attacks once and ignores the rest:

| | passed | failed | denominator | rate | disposition |
|---|---|---|---|---|---|
| Before #139 | 1 | 4 | 5 | 20% | NOT MET |
| After #139 | 1 | 0 | 1 | 100% | **MET** |

Same model, same boundary, opposite verdict. Without this ADR, the less cooperative the model, the
easier the threshold becomes to meet — the opposite of what a milestone themed *live evaluation
soundness* should ship.

## Decision

**A verdict is gated on coverage before it is gated on rate**, and the two gates answer different
questions.

`LiveEvaluationThresholdDisposition` gains `Insufficient`, distinct from `NotEvaluated`:

- **`NotEvaluated`** — zero evaluated outcomes. Nothing was measured.
- **`Insufficient`** — at least one evaluated outcome, but either
  - measurable-but-unmeasured outcomes outnumber evaluated ones, or
  - the configured `minimum_observations` exceeds the evaluated count.
- **`Met` / `NotMet`** — only when neither applies.

The command's exit contract is unchanged and already covers this: it returns success only when both
thresholds are `Met`, so `Insufficient` exits non-zero without a special case.

### Which outcomes count against coverage

**Measurable but unmeasured** — `declined`, `not_attempted`, `unavailable`, `uncategorized`. Each could
have been a measurement on a different run. Their presence is what erodes a verdict's support.

**Structurally unavailable** — `not_expressible` and `pending`. These are permanent properties of the
suite, not signals about this run. Counting them would make any suite containing a single non-live-
expressible case permanently insufficient; the workbench storefront suite has four of ten.

### Two floors, deliberately separate

The **coverage rule** is always on and needs no configuration. It is self-scaling: a majority test over
whatever population the suite happens to have. It asks *did enough of what could have been measured get
measured?*

The **absolute floor** (`verdict.evaluation.minimum_observations`, default `0` = off) is the adopter's
sample-size policy. It asks *how many observations do I consider enough to act on?* Verdict cannot answer
that — it depends on the suite, the model, and what the result will be used for.

### What this is not

**This is a coverage adequacy floor, not a statistical confidence claim.** It does not bound an error
rate, produce a confidence interval, or make `Met` mean "validated". A run can have perfect coverage and
still be far too small to generalise from — which is exactly what the absolute floor exists for, and why
it is the adopter's to set.

## Consequences

**This is a behaviour change.** A run that previously reported `MET` on a minority of measured outcomes
now reports `INSUFFICIENT` and exits non-zero. That is deliberate: such a result was not a passing one.

Both renderers now print `evaluated / measurable but unmeasured / structurally unavailable` beside every
disposition, not only when insufficient. An `INSUFFICIENT` verdict is unreadable without those three
numbers, and a `MET` one is worth less than it looks without them.

The rule is a strict majority test, so an even split counts as adequate. That is a judgement, not a
derivation; it is the weakest rule that still catches the recorded run.

Per-case coverage is deliberately out of scope. Thresholds and the exit status are purpose-level today,
and the recorded defect is purpose-level. A per-case floor raises its own policy question — particularly
for intentionally non-expressible and pending cases — and is tracked as
[#174](https://github.com/fissible/verdict/issues/174). The gap it leaves is concrete: one case measured
on every trial and another never measured produce equal purpose-level totals, so the majority rule passes
and the threshold can report `MET` while one of the pack's attacks was never observed being blocked.

## Alternatives considered

**Report the evaluated count and change no verdict.** Rejected as the whole answer, though adopted as
part of it. It leaves the machinery asserting `MET` on evidence it knows is thin, and puts the burden on
a human reading carefully — which is how the #51 run reached publication.

**A configured absolute floor only, default off.** Rejected as the whole answer. It is arbitrary per
adopter, and a default of off would have shipped the #139 regression in the default configuration.

**Count `not_expressible` against coverage.** Rejected. It makes suites permanently insufficient for a
property that has nothing to do with the run's quality.

**Require every case to be measured.** Rejected for the same reason, more strongly: it is unsatisfiable
for any suite with a non-expressible case.
