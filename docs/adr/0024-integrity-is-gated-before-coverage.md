# ADR 0024: Integrity is gated before coverage, which is gated before rate

Status: Accepted

## Related issues

- [#185](https://github.com/fissible/verdict/issues/185) raised the gap this ADR settles.
- [#183](https://github.com/fissible/verdict/issues/183) / [#184](https://github.com/fissible/verdict/issues/184)
  are the instance that exposed it.
- [ADR 0021](0021-coverage-adequacy-gates-a-live-verdict.md) established coverage-before-rate; this
  extends the same argument one level up.
- [ADR 0022](0022-coverage-adequacy-applies-per-case.md) applies coverage per case.

## Context

The coverage gates measure **coverage of observations**. They do not measure **integrity of the
observation pipeline**, and they cannot: they were built to answer *was enough of the measurable
population measured?*, not *was the measuring apparatus working?*

`ThresholdCoverage::measurableCategories()` pooled four `LiveErrorCategory` values into a single
"measurable but unmeasured" bucket:

| Category | Means |
|---|---|
| `Declined` | the model chose not to act |
| `NotAttempted` | the model never reached for the capability |
| `Unavailable` | the **harness** could not observe the outcome |
| `Uncategorized` | an unclassified error — usually also harness-side |

The first two are statements about the model. The last two are statements about the apparatus.
Pooling them is what makes a blinded run indistinguishable from an uncooperative one.

#183 is the worked instance. The guarded live arm resolved different evidence-recorder instances on
its write and read paths, so **every** reachable case failed correlation and produced
`LiveObservationUnavailable`. The command reported `NOT EVALUATED` — arithmetically correct, since
nothing was evaluated, and materially misleading, because it reads as a finding about the model when
the harness saw nothing at all.

That is the "evidence is theatre" failure mode, occurring inside the harness Verdict uses to
demonstrate it does not happen. It was caught only because the guarded arm was run against two
unrelated models and failed identically.

## Decision

**A live evaluation verdict is gated on integrity, then on coverage, then on rate.** Each gate asks a
question that is only meaningful if the previous one passed.

### 1. The population is partitioned four ways, not three

| Bucket | Categories | Question it answers |
|---|---|---|
| **evaluated** | passed + failed | what was measured |
| **model-declined** | `Declined`, `NotAttempted` | what the model chose not to do |
| **harness-blind** | `Unavailable`, `Uncategorized` | what the apparatus could not see |
| **structurally unavailable** | `NotExpressible`, `Pending` | what was never in the population |

`measurableButUnmeasured` narrows to model-declined only. Harness-blind becomes its own reported
dimension, because a reader and a CI job both need to see "N outcomes the harness could not observe"
as its own number rather than folded into a coverage figure.

### 2. `HarnessBlind` is a disposition, and it is checked first

`LiveEvaluationThresholdDisposition` gains `HarnessBlind`, reached when harness-blind outcomes
outnumber evaluated ones. The order of checks is the substance of this ADR:

```
harness-blind dominates  → HarnessBlind      ← first
passRate === null        → NotEvaluated
coverage dominated       → Insufficient
minimumObservations      → Insufficient
                         → Met / NotMet
```

**Checking integrity first is the whole point.** Under #183 every case was blind, so `evaluated` was
zero and `passRate()` was `null` — which today reports `NOT EVALUATED`, a statement about the model.
Placing the integrity check after any coverage or rate question launders an apparatus failure into a
measurement verdict.

The command's exit contract is unchanged: it succeeds only when both thresholds are `Met`, so
`HarnessBlind` exits non-zero without a special case.

### 3. A systematically blind trial halts the run

A trial in which **no case produced an evaluated outcome and at least one case was harness-blind**
halts the run before further trials. The run reports what it saw and why it stopped.

The signature is deliberately narrow so it cannot fire on an uncooperative model: declines and
non-attempts are model-side and never enter the harness-blind bucket, so a model that refuses
everything produces zero blind outcomes and no halt. Only an apparatus that cannot see produces
*nothing measured and something blind* in the same trial.

This is ADR 0020's instinct — refuse to spend model time on a run whose output cannot be used —
applied at the first point where the fault is knowable. #183 burned three trials across two arms
producing nothing; the fault was diagnosable after trial 0.

### 4. `Uncategorized` counts as harness-blind, and that is a judgement

An unclassified error may originate in an application's case runner rather than in Verdict. It is
still counted as harness-blind, on the reasoning that an error the taxonomy could not classify is an
error the apparatus did not understand — which is a form of blindness regardless of where it arose.

This is stated rather than buried because it is the kind of assumption that gets quoted later as if
it had been measured. An application seeing `Uncategorized` dominate should read it as *the harness
does not understand what is happening*, not as a fact about the model.

## Consequences

A run blinded by a harness defect now reports `HARNESS BLIND` rather than `NOT EVALUATED`, and a
reader can tell the two apart without external knowledge. That is the acceptance criterion #185 was
filed for.

This is a behaviour change. A run that previously reported `NOT EVALUATED` because its apparatus was
broken now reports a different disposition and, if the blindness is systematic, stops early. Both are
deliberate.

The four-way partition is additive to reporting: renderers gain a harness-blind count beside the
existing three, and the JSON report gains the same field.

**This does not make the harness self-validating.** It detects blindness that manifests as
uncorrelatable or unclassifiable outcomes. A harness that observes the *wrong* thing confidently —
correct-looking evidence attributed to the wrong invocation, say — produces evaluated outcomes and
passes every gate here. "Correlates" is not "validated," and this ADR moves the line without erasing
it.

## Alternatives considered

**Report the harness-blind count and change no disposition.** Rejected as the whole answer, adopted
as part of it. It leaves the machinery asserting a verdict about the model on evidence it knows came
from a broken apparatus, and puts the burden on a human reading carefully — which is how #183 went
undetected across every run after #137.

**Halt on any harness-blind outcome.** Rejected. Transient provider failures land in `Unavailable`,
and a run that stops on the first one would be unusable against a real provider. The
nothing-measured-and-something-blind signature is what distinguishes systematic blindness from noise.

**Fold harness-blind into `structurallyUnavailable`.** Rejected. That bucket means *never in the
measurable population* — a permanent property of the suite. A harness fault is a transient property
of the run, and treating it as structural would make a broken apparatus look like a suite design
choice.

**Infer harness health from correlation failure rate alone.** Rejected as insufficient: correlation
failure is one blindness mechanism, and the partition needs to hold for the others — a middleware
that stops binding invocation ids, a provider that drops tool telemetry — without enumerating them.
