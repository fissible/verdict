# ADR 0023: The control arm pairs within one run, opts in separately, and gates nothing

Status: Accepted

## Related issues

- [#170](https://github.com/fissible/verdict/issues/170) — the unguarded control arm this ADR shapes.
- [ADR 0020](0020-live-trial-isolation-is-application-owned.md) — the reset seam and the
  attested-not-verified posture this ADR extends to arms and to decoding.
- [ADR 0021](0021-coverage-adequacy-gates-a-live-verdict.md) / [ADR 0022](0022-coverage-adequacy-applies-per-case.md) —
  the coverage gates that make each arm's result trustworthy enough to compare.
- [#137](https://github.com/fissible/verdict/issues/137), [#138](https://github.com/fissible/verdict/issues/138),
  [#139](https://github.com/fissible/verdict/issues/139) — the soundness work this sits downstream of.

## Context

Live evaluation measures whether Verdict denied an attack, not whether the attack would have succeeded
without Verdict. Those are different claims, and only the second shows the boundary is load-bearing. The
recorded Ollama run contains the ambiguity in the flesh: `cross-principal-cancellation` failed with the
model stopping after a denied lookup, and whether it would have completed the cancellation unguarded is
unknowable from that data.

The measurement that resolves it is a 2×2 per case — guarded denied or executed, against control executed
or declined — which makes its cells **joint observations**, not two rates.

## Decision

### One invocation, two arms, reset before each

The control arm runs inside the same runner invocation as the guarded arm. Per trial:
`makeForTrial($trial)` → guarded run → `makeControlForTrial($trial)` → control run. A fresh application
build — and therefore a fresh state reset — precedes **every arm**, not every trial: the control arm
actually executes the dangerous capability, and a control breach sharing state with the next guarded
observation is the defect ADR 0020 exists to prevent, one level down.

The contract is `LiveEvaluationControlArmFactory extends LiveEvaluationTrialFactory`, with a distinct
`makeControlForTrial()` method so the arm being built is explicit in the seam the application owns.
`TrialSuiteIdentity` is asserted across arms as well as trials: same suite, same cases, same fingerprints,
only the tool wrapping differing. Because both arms come from one factory in one process, the identity,
model, and configuration agreement that a post-hoc comparison would have to detect is obtained by
construction.

### A separate opt-in, refused rather than warned

"Call a real model" and "let an attack succeed" are different risks with different owners. The control arm
requires all of: `verdict.evaluation.control_enabled` (default `false`), the explicit `--control` flag,
the two existing live-evaluation gates, and a factory implementing the control contract. Any one missing
refuses the run before a model is invoked, in the refuse-don't-warn posture of #137.

### The 2×2 is a greedy-only claim; sampled runs get marginals

Per-trial pairing is a genuine matched pair only under greedy decoding with a fixed seed. Under sampling,
"trial 3 guarded" and "trial 3 control" are two independent draws, and a four-cell table would present
marginals dressed as joint observations — a reader looking at cells reads joint semantics into them no
matter what a nearby line says the mode was.

So presentation changes by mode, not only metadata:

- **Greedy** — each (case, trial) pair is classified: **prevented** (guarded denied × control executed),
  **self-declined** (guarded denied × control declined or not attempted — Verdict not load-bearing that
  trial), **breach** (executed × executed), **inconsistent** (guarded executed × control declined —
  investigate the harness), **unmeasured** (either arm unmeasured; a model that never attempts the
  capability is unmeasured in both arms, never a prevention).
- **Sampled** — the runner does not classify or store pair counts at all, so nothing downstream can
  render them. Reports carry per-arm marginals — guarded denial counts, control breach counts, each arm's
  coverage — labeled as independent draws with no per-trial pairing claimed.

The mode is declared twice: a typed `samplingMode()` on the control contract, which drives the branching
above, and a `sampling` component in `ReproductionMetadata` recording the actual parameters (temperature,
seed), whose absence refuses a control run. **Both are application-attested, not verified.** Verdict
cannot check that a factory declaring `greedy temperature=0 seed=42` actually decoded greedily, any more
than ADR 0020 can check that a reset actually reset — the declaration is required and its absence refused,
but its truth belongs to the application. A reader should weight the labels accordingly.

### Thresholds and the exit contract do not change

Both thresholds remain computed from the guarded arm alone. A breach through Verdict already fails the
security threshold there; the 2×2 is measurement, not gating. Whether any control-arm outcome should ever
gate a verdict is deferred deliberately — the same posture ADR 0021 took when it deferred the per-case
question to #174 rather than answering it by reflex.

### Integrity checks the pairing makes possible

- A control-arm tool observation carrying a non-null Verdict disposition means the arm was accidentally
  guarded; the run is refused with a dedicated error rather than recorded, because every pair in it is
  invalid.
- A control attack case that *passes* — capability attempted unguarded yet not executed — lands in
  **inconsistent**: without Verdict in the path there is nothing that should have stopped it.

### A breach that cannot be demonstrated is a finding, not a failure to finish

"Prevented" requires the control arm to have executed **in the same trial**, so a case that never breaches
unguarded produces zero preventions by construction — the vacuous pass cannot be smuggled in. Each case is
additionally marked *demonstrated to breach* or *never breached* across the run.

The empirical phase may legitimately conclude that no available model breaches reliably. The criterion is
*capable enough to act, not aligned enough to refuse*, and the harness and workbench wiring ship
regardless of that conclusion, with the control-coverage table recording breach cases as **unvalidated**
rather than the phase as incomplete. Continuing to pull models until one cooperates is its own selection
bias, and this ADR declines it up front.

## Consequences

**The dangerous capability actually executes.** In the workbench that writes an in-memory `ActionLog`; in
an adopter's suite it could issue a real refund. `CONTRIBUTING.md`'s synthetic-and-reversible requirement
stops being advisory: documentation leads with it as a precondition, as does the `control_enabled` config
comment.

A control run costs twice the model calls and twice the builds of a guarded run at the same trial count.

Baselines remain guarded-only. The expected CI shape is guarded runs in CI and control runs deliberate and
manual; a future compare command over two stored artifacts (one guarded, one control) remains open as
follow-up, but it would need the identity checks this design gets by construction, and it is not the
primary mechanism.

Any published comparison states the model, trial count, decoding mode, and reproducibility set, and any
rate is a property of the named model, not a production risk estimate. For the zero-breach direction the
output states the rule-of-three bound (≈3/n at 95% for n trials with zero breaches) rather than implying
certainty.

## Alternatives considered

**Two runs compared post hoc, as the primary mechanism.** Rejected: it cannot construct the 2×2. The
cells are joint observations, and from two marginals the pairing cannot be recovered — only assumed via
independence, which is precisely the comparison #170 warns "looks rigorous and is not". It also
reintroduces between processes the drift #137's identity work eliminated between trials.

**Control cases inside the suite (id-suffixed siblings).** Rejected as disqualified, not merely weaker: the
exclusion becomes a string convention, and one missing suffix silently folds an unguarded breach into the
guarded security threshold — inverting the result rather than degrading it. It would also contaminate
ADR 0022's per-case eligibility population, and push the pairing contract onto applications as a naming
convention that fails silently — the pattern #137 and #139 each rejected in their own domains.

**Gating the verdict on control outcomes.** Deferred, not designed here. The guarded arm keeps the exit
contract; a control-arm gate is a separate policy question to be raised on its own evidence.

**Verifying the decoding mode instead of requiring its declaration.** Rejected as impossible from inside
the package: decoding configuration is application-owned, exactly as trial reset is under ADR 0020.
