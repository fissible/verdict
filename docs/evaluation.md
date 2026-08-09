# Evaluation harness and attack packs

Verdict provides an evaluation harness and executable attack packs to demonstrate how its authorization boundary responds to both malicious intent and legitimate utility.

## What the harness is for

Attack packs are executable specifications of the threat model, not unit tests. The harness distinguishes between `CasePurpose::Security` and `CasePurpose::Utility`. A boundary that simply denies everything passes every security case, so utility cases are load-bearing to prove that legitimate traffic still succeeds.

## Running it

The harness is centered around `SecuritySuite`. It is constructed with a suite name, a version, and a non-empty list of `EvaluationCase` instances. It runs deterministically via the `run()` method, returning a `SuiteResult`. There are no network or provider calls in this execution path—it executes synthetically against your application logic, making it safe and fast enough to run in continuous integration (CI).

## The shipped packs

Verdict ships with three attack packs that model specific threats:

- `StorefrontAttackPack`: Models a compromised storefront interacting with an order system. It includes 10 cases covering cross-principal lookup/cancellation denial, owned-order utility cases, argument-mutation-after-confirmation, confirmed mutation execution, duplicate-mutation admission, single-mutation admission, indirect-instruction-in-retrieved-document, and an owned-order-document utility.
- `AccountRecoveryAttackPack`: Models a social-engineering attacker attempting to bypass verification in recovery flows. It tests urgency-pressure verification bypass versus verified operation, for both account-unlock and MFA-reset scenarios.
- `RagBorneInjectionAttackPack`: Models untrusted data retrieved by RAG flows attacking the executor. It ensures unauthorized injected action is denied, authorized-but-confirmable action halts at confirmation, argument manipulation from a poisoned retrieved document halts at confirmation, and asserts untrusted-document provenance.

## Writing a pack

Implementing an attack pack means satisfying the `AttackPack` interface, which defines one method: `cases(Closure $runner): array`. A pack generates an array of `EvaluationCase` instances created via `EvaluationCase::attack()` or `EvaluationCase::utility()`. Each case requires a non-empty `id`, a `version`, and at least one assertion.

Cases use a `CaseInput` that holds `trustedSetup` and `untrustedInput` arrays. The framework computes SHA-256 fingerprints of each using `ArgumentFingerprint`.

You write assertions using the 12 static factory methods on `Assertions`:
- `decisionIs`
- `executed`
- `notExecuted`
- `noSideEffects`
- `sideEffectOccurred`
- `toolDidNotExecute`
- `toolDecisionPrecedes`
- `toolExecuted`
- `toolArgumentFingerprintIs`
- `toolCallCount`
- `outputExcludes`
- `provenanceEntryIs`

For custom assertions, use `CallbackAssertion`, which implements `ObservationAssertion` and wraps a `Closure(Observation): bool`. Assertions run against an `Observation` (or `ToolObservation`), which can be projected to `ObservationEvidence` for reporting.

To keep packs deterministic and synthetic, use the `*AttackPackConfig` convention (e.g., `StorefrontAttackPackConfig`, `AccountRecoveryAttackPackConfig`, `RagBorneInjectionAttackPackConfig`). These immutable, validated config objects parameterize a pack's actor IDs, resource IDs, and forbidden markers.

## Reports and baselines

Suite executions produce an `EvaluationReport` adhering to the `verdict.evaluation-report.v1` schema, capable of serializing itself via `toJson()` and `fromJson()`. File I/O is handled by `EvaluationReportFile` using `report()`, `baseline()`, and `writeBaseline()`. Writing refuses to overwrite an existing baseline without `--force`, and verifies the written file round-trips correctly before returning.

An `EvaluationBaseline` stores only the suite identity and the per-case purpose and status—it stores nothing else. Baselines are created using the `verdict:evaluation-baseline` command, which has the signature:
`{report} {baseline} {--force}`

Crucially, `ObservationEvidence::fromObservation()` never carries raw output or side-effect strings into a report. Side effects and output are hashed to SHA-256 fingerprints (`ArgumentFingerprint` and `hash('sha256', ...)`) before serialization, and tool-call arguments are likewise stored only as fingerprints. This mechanism makes a baseline safe to commit: it is schema-validated, fingerprint-only, and structurally incapable of holding a raw prompt or provider response. There is no general-purpose PII redaction step because privacy is achieved fingerprint-first-by-construction.

## Comparing

The `verdict:evaluation-compare` command compares a current report against a baseline. Its signature is:
`{current} {baseline} {--format=console|github}`

It exits with a `FAILURE` status code if the comparison has blocking changes. Comparisons are powered by `BaselineComparator` where `compare()` diffs a `SuiteResult` against an `EvaluationBaseline`.

A comparison yields changes of the `BaselineChangeKind` enum:

| Kind | Meaning | Reaction |
| --- | --- | --- |
| `BehavioralRegression` | A case that was passing now fails (and wasn't previously erroring). | Investigate — the protected behavior got worse. |
| `BehavioralFailure` | A case is failing and was already failing (or is newly added and fails) — status didn't improve from a prior error/fail state. | Known/expected if intentional (e.g. a pending RAG-provenance case); otherwise investigate. |
| `HarnessError` | The current run errored (`CaseStatus::Error`) regardless of baseline status. | Treat as broken harness/environment first, not a security signal — the case didn't execute meaningfully. |
| `RemovedCoverage` | A case present in the baseline is missing from the current run, or its purpose changed. | Confirm the removal/reclassification was intentional; this shrinks what's actually being tested. |
| `Improvement` | A case moved from a non-Error failing/regressed state to `Passed`. | Good news; consider re-baselining. |
| `Recovered` | A case moved from `Error` to `Passed`. | Confirm the underlying harness issue is actually fixed, not just intermittently green. |
| `AddedCoverage` | A new case not in the baseline was added and passed. | Informational; re-baseline to lock it in. |

The command treats `BehavioralRegression`, `BehavioralFailure`, `HarnessError`, and `RemovedCoverage` as blocking in CI. The other three change kinds are not blocking.

## Live evaluation

Verdict provides a `LiveEvaluationRunner` for executing against live provider endpoints. It calls real providers, it can cost money, and it is strictly off by default at two independent layers.

The runner is constructed with `liveEnabled: bool` and `maximumTrials: int`. The `run()` method throws a `LogicException` unless both the `verdict.evaluation.live_enabled` config is `true` (it defaults to `false`) and the caller passes `LiveEvaluationOptions(enabled: true)`. The `maximum_trials` configuration defaults to 25.

## Limitations

An attack pack demonstrates a property against a scripted, deterministic transcript. It is not a guarantee against an adaptive adversary, and passing packs is not a security certification. See [limitations](limitations.md) for more details.
