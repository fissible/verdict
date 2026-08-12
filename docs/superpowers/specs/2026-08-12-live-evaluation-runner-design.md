# Live Agent Evaluation: Provider-Neutral Observation Adapter

**Status:** Approved design, not yet implemented.

## Goal

Add an opt-in command path that runs existing evaluation attack packs against an
application-supplied live Laravel AI agent, while keeping the package
provider-neutral and making a recorded run interpretable. Verdict owns the
evaluation mechanism. The application owns its model, agent, tools, fixtures,
and provider credentials.

This does not change `CaseStatus` or `Score`. `SecuritySuite::runCase()` already
turns a thrown `Throwable` into `CaseStatus::Error` and records its class on the
`CaseResult`. The design uses that existing distinction rather than treating a
model decline as a failed assertion or as a provider outage.

## Components and ownership

### Verdict package

- `Evaluation\\LiveAgentObserver`: an invokable
  `Closure(CaseInput): Observation` adapter. It invokes an application-created
  agent, normalizes its response, and produces an `Observation`.
- `Evaluation\\LiveEvaluationSuiteFactory`: an application-implemented contract
  which returns a `SecuritySuite`. The command resolves it from the container.
- `Evaluation\\ModelDeclinedToAct`: thrown when a completed invocation made no
  bound-tool call.
- `Evaluation\\CaseNotLiveExpressible`: thrown when a case has no
  `untrustedInput['request']` and therefore cannot be expressed as a live model
  prompt.
- `Evaluation\\LiveObservationUnavailable`: thrown for missing invocation
  correlation or unavailable/corrupt required observation evidence.
- Add `?string $errorClass = null` to
  `LiveEvaluationScoreCounter::record()`, and expose categorized error counts
  from `LiveEvaluationCaseResult` and `LiveEvaluationResult`. Existing callers
  continue to work. The counter is the sole source of live-run tallies.
- `Console\\Commands\\RunLiveEvaluationCommand`, with the signature
  `verdict:evaluation-live {suite} {--trials=} {--format=console}`.
- `config/verdict.php` gains `evaluation.suites`: an empty map from suite name
  to factory class, with a commented application example. A configured class
  must implement `LiveEvaluationSuiteFactory`.

`LiveAgentObserver` is deliberately not `LiveEvaluationRunner`. The existing
`LiveEvaluationRunner` retains ownership of its two opt-in gates, trial limit,
per-case aggregation, thresholds, and reports.

The contract has one intentionally small method:

```php
interface LiveEvaluationSuiteFactory
{
    public function make(): SecuritySuite;
}
```

The application factory may resolve its own model-specific dependencies from
the container. It must not make a provider call while the command is merely
validating the configured factory.

### Application workbench

The storefront live suite belongs in `workbench/`. It supplies the Ollama-backed
agent factory, bound tools, fixture data, `ActionLog`, and an
invocation-scoped evidence reader/capture. It is not shipped as a package
provider, route, fixture, model configuration, or credential policy.

The existing `StorefrontAttackPack` and the other packs remain unchanged: they
already accept `Closure(CaseInput): Observation`. Some storefront cases are
mechanical rather than prompt-shaped; the observer makes those visible as
`CaseNotLiveExpressible` rather than inventing model input for them.

## Data flow

```text
verdict:evaluation-live storefront --trials=5
  -> evaluation.suites['storefront'] factory, resolved from the container
  -> factory builds the agent, LiveAgentObserver, and existing attack-pack cases
  -> existing LiveEvaluationRunner::run($suite, $options)
  -> each SecuritySuite run invokes LiveAgentObserver for each case
  -> runner aggregates statuses and error classes into the live result/report
```

For a prompt-shaped case, `LiveAgentObserver`:

1. Requires a string `untrustedInput['request']`; otherwise throws
   `CaseNotLiveExpressible`.
2. Builds the application agent for the supplied trusted setup and invokes it
   through the application's real `BoundTool` path.
3. Gets the invocation ID from the returned Laravel AI response. Synchronous,
   structured, streamed, and streamable response types expose it. A future
   response shape without one is a harness failure, not a model decline.
4. Fully consumes a `StreamableAgentResponse` before inspecting the capture.
   Tool execution and correlated evidence are lazy until iteration.
5. Uses the per-invocation bound-tool capture for causal facts: capability,
   argument fingerprint, disposition, and `ExecutionResult::executed` for every
   tool call. Fixture-owned `ActionLog` supplies domain side effects.
6. Uses the factory-supplied reader to obtain decision evidence whose
   `invocationId` equals the response invocation ID. This is containment and
   corroboration, not evidence that the model caused a later action. The
   observer requires every captured bound-tool call to have at least one
   correlated `DecisionEvidence` record with the same capability, argument
   fingerprint, and disposition before assembling the `Observation`. Extra
   correlated evidence records are expected because one bound-tool call can
   record multiple Verdict stages; they do not require a one-to-one mapping.

The reader is explicitly invocation-scoped. `InMemoryEvidenceRecorder::all()`
accumulates across a process and has no reset, so unfiltered reads would mix
previous trials and cases. The default `NullEvidenceRecorder` has no readable
decision trail. Neither may silently degrade to a model decline.

`Observation` can carry provenance entries for attack-pack assertions. Its
existing `ObservationEvidence::fromObservation()` projection intentionally does
not persist provenance entries in reports or baselines; this design does not
expand that reporting boundary.

## Error taxonomy and fail-closed behavior

| Condition | Outcome |
| --- | --- |
| No `untrustedInput['request']` | `CaseNotLiveExpressible` |
| Agent completes, capture is empty, and correlated reader result is empty | `ModelDeclinedToAct` |
| Response has no invocation ID | `LiveObservationUnavailable` |
| Bound-tool capture is non-empty but correlated evidence is missing or incompatible | `LiveObservationUnavailable` |
| No invocation-scoped reader is supplied | `LiveObservationUnavailable` |
| Provider, serialization, or executor failure | Original exception class |

Only the second row is a model decline. In particular, an empty reader is
expected when there was no tool invocation; it becomes a harness error only
when the capture proves that a bound tool did run. This avoids both flattering
false passes and erroneous failure of a genuine refusal.

The three named conditions and genuine failures become `CaseStatus::Error` in
the existing suite runner. `errorClass` makes them separately reportable. The
live counter classifies decline, non-expressible, harness-unavailable, and
uncategorized/genuine errors without changing score semantics.

## Command behavior

The command resolves a configured suite factory by name and passes the returned
suite into the existing live runner. Command invocation is the explicit opt-in
for `LiveEvaluationOptions(enabled: true)`; it is not a separately
user-configurable command gate. `verdict.evaluation.live_enabled` remains the
deployment configuration gate. The runner retains both checks for direct API
callers, and tests exercise the options gate at that level. A disabled
configuration, invalid factory, unknown suite, or trial count above
`maximum_trials` returns a clear non-zero command error without a stack trace.

`--format` accepts `console` (the default) or `github`, matching the existing
evaluation command convention. Both render each case's rates and categorized
error breakdown, plus the existing security and utility thresholds; `github`
uses GitHub workflow commands. Exit status is `0` for both thresholds met, `1`
for not met, and `1` for not evaluated.

## Testing plan

CI uses `Agent::fake()` to test the adapter and command; it does not claim to
evaluate a model. The Ollama storefront run is manual, names the model used,
and records observed rates in documentation.

Observer tests cover:

1. `AgentResponse`: response invocation ID, capture-derived execution/tool
   observations, and corroborating evidence.
2. `StructuredAgentResponse`: the same response-ID contract.
3. Fully consumed stream: tool execution happens on iteration and produces an
   executed observation. A mutation removing consumption must classify it as a
   decline and make this test fail.
4. Text-only completed response with empty capture and evidence:
   `ModelDeclinedToAct`.
5. Non-empty capture with empty reader result:
   `LiveObservationUnavailable`, never decline.
6. Missing request: `CaseNotLiveExpressible`.
7. Missing response invocation ID: `LiveObservationUnavailable`.
8. Provider or executor exception during stream consumption: its original class
   propagates to `SecuritySuite`.
9. Per-trial isolation: a permitted first trial and denied second trial must
   yield an observation containing only the second invocation's evidence.

Command tests cover the deployment gate, the maximum trial bound, unknown
suite, factory-contract validation, console and GitHub output validation,
no-stack-trace failures, threshold exit statuses, and report output including
the four-way error breakdown. Runner-level tests cover the second live gate.
Counter tests prove that `record($status, $errorClass)` totals match `Score`
and that each named error class remains distinguishable.

Each test protecting a guard is mutation-checked by removing or bypassing the
guard and confirming the test fails.

## Out of scope

- Shipping an Ollama, storefront, or other provider implementation.
- Shipping an application agent, tool, policy, fixture, route, job,
  notification, or credential configuration.
- Changing existing attack-pack case definitions.
- Redesigning `CaseStatus`, `Score`, or report provenance projection.
- Treating invocation correlation as proof of causality.
