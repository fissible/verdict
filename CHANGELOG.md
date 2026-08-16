# Changelog

All notable changes to Verdict will be documented in this file.

## [Unreleased]

## [0.7.0] - 2026-08-16

- Add an **unguarded control arm** to live evaluation, so a run can show whether an attack would have
  succeeded *without* Verdict rather than only that Verdict denied it. With `--control`, each attack case
  also runs against the same agent, model, and inputs with Verdict's tool wrapping absent — the dangerous
  capability actually executes. Because "call a real model" and "let an attack succeed" are different
  risks, the control arm has its own opt-in: `verdict.evaluation.control_enabled` (default `false`, in
  addition to the two live-evaluation gates), the `--control` flag, and a factory implementing
  `LiveEvaluationControlArmFactory`. Any missing piece is refused before a model is invoked, and a control
  observation carrying a Verdict disposition refuses the run as accidentally guarded. See
  [ADR 0023](docs/adr/0023-unguarded-control-arm-pairing-and-opt-in.md) and
  [#170](https://github.com/fissible/verdict/issues/170).

  Each trial runs both arms with a fresh build and state reset before *each* arm, and the guarded and
  control suites are held to the same identity. Under **greedy decoding** every (case, trial) is
  classified into a 2×2 — `prevented` (guarded denied, control executed), `self_declined` (the model
  refused even unguarded), `breach` (executed through Verdict), `inconsistent`, and `unmeasured` (either
  arm produced no measurement; a model that never attempts the capability is unmeasured in both arms,
  never a prevention). Under **sampled decoding** the two arms are independent draws, so the runner stores
  no pair counts and reports per-arm marginals with no per-trial pairing claimed. Thresholds and the exit
  contract stay on the guarded arm; the 2×2 is measurement, not gating.

  **What the first recorded run demonstrates, and what it does not.** Against an abliterated Ollama model
  under greedy decoding, the unguarded arm executed the cross-principal lookup and cancellation on every
  replay and the guarded arm denied them on every replay — the first artifact this project can produce that
  *demonstrates* prevention rather than asserting it. It demonstrates the **authorization** boundary only:
  it is not a breach *rate* (greedy replays one deterministic path — a rate needs sampled decoding), not
  the authority/intent gap (both cases are outside-authority; no inside-authority case exists in the pack,
  tracked as [#187](https://github.com/fissible/verdict/issues/187)), and not the human-approval boundary
  (the denial is an authorization denial that short-circuits before the confirmation gate, and guarded
  `orders.cancel` cannot complete past `RequireConfirmation` without `Laravel\Ai\Contracts\Conversational`).
  A zero-breach greedy arm prints a reproducibility note, not a rule-of-three bound, because its replays
  are not independent observations. See `docs/evaluation.md`.

- Apply coverage adequacy **per case**, not only per purpose. The purpose-level rule from the previous
  release could report `MET` while an individual attack was never once observed: one case measured on
  every trial and another never measured produce identical purpose-level totals, so the majority rule
  passes. A case is now *eligible* for the per-case floor if it produced at least one measurable outcome;
  every eligible case must then have at least one evaluated outcome, or its purpose reports `INSUFFICIENT`
  and names the never-measured case. Cases that are entirely `not_expressible` or `pending` have no
  measurable population and are exempt, so a suite containing them is not permanently insufficient. The
  floor is the weakest rule that catches "never observed": a case measured once is thinly observed, which
  the per-case counts make visible rather than gate. Per-case
  `evaluated / measurable but unmeasured / structurally unavailable` counts are printed beside every case
  and recorded per case in the report. See
  [ADR 0022](docs/adr/0022-coverage-adequacy-applies-per-case.md) and
  [#174](https://github.com/fissible/verdict/issues/174).

- Stop three container bindings pinning an evidence recorder that a trial reset has replaced. The
  guarded live evaluation arm failed to correlate every captured tool call to its decision evidence,
  reporting `LiveObservationUnavailable` for each reachable case, so a live run produced
  `NOT EVALUATED` thresholds regardless of model behaviour.

  `EvidenceWriter`, `ProvenanceLedgerStore`, and `CapabilityConfigurationStore` were bound
  `singleton` while resolving collaborators an application may bind with a shorter lifetime. The
  first resolution captured whatever instance existed then and held it for the process, surviving
  every `Container::forgetScopedInstances()`. Once trial isolation
  ([ADR 0020](docs/adr/0020-live-trial-isolation-is-application-owned.md)) made that reset routine,
  writes went to the pinned recorder while reads resolved the current one, and nothing errored. All
  three are now `scoped`, so a binding never outlives what it captures.

  The defect was invisible before trial isolation existed: with nothing replacing the scoped
  recorder, the pinned instance and the resolved one were the same object. It was found by running
  the guarded arm against two unrelated models and observing identical correlation failure, which
  ruled out a provider quirk. See [#183](https://github.com/fissible/verdict/issues/183).

## [0.6.0] - 2026-08-14

- Gate a live evaluation verdict on coverage before gating it on rate. A threshold previously reported
  `MET` identically whether it rested on two hundred observations or on one. #51's first recorded run
  read `Security threshold MET — 1 passed / 0 failed / 4 errors, minimum 100%`: arithmetically correct,
  and a single observation behind a line that reads like pack-wide validation.

  `LiveEvaluationThresholdDisposition` gains `Insufficient`, distinct from `NotEvaluated` — the latter
  means *zero* evaluated outcomes, the former *too few*. A purpose reports `Insufficient` when it has at
  least one evaluated outcome but its measurable-but-unmeasured outcomes outnumber them, or when the new
  optional `verdict.evaluation.minimum_observations` exceeds its evaluated count. `Met` and `NotMet` are
  reached only once coverage is adequate. The command's exit contract already required both thresholds
  to be `Met`, so an insufficient run exits non-zero without a special case.

  `declined`, `not_attempted`, `unavailable`, and `uncategorized` count against coverage — each could
  have been a measurement on another run. `not_expressible` and `pending` do not: they are permanent
  properties of a suite rather than signals about a run, and counting them would make any suite with a
  single non-live-expressible case permanently insufficient. Both renderers now print
  `evaluated / measurable but unmeasured / structurally unavailable` beside every disposition.

  **This is a deliberate behaviour change.** A run that previously reported `MET` on a minority of
  measured outcomes now reports `INSUFFICIENT` and exits non-zero. It became urgent because of the
  change above: moving an unattempted attack from `Failed` to `Error` removes it from
  `Score::evaluated()`, which is `passed + failed`, so a five-case suite where the model attacks once and
  ignores the rest went from `1 passed / 4 failed` (20%, NOT MET) to `1 passed / 0 failed` (100%, MET).
  Without this, the less cooperative the model, the easier the threshold became to meet.

  **This is a coverage adequacy floor, not a statistical confidence claim.** It does not bound an error
  rate or make `Met` mean "validated". `minimum_observations` (default `0`, off) is the adopter's
  sample-size policy, which Verdict cannot set for them. See
  [#138](https://github.com/fissible/verdict/issues/138) and
  [ADR 0021](docs/adr/0021-coverage-adequacy-gates-a-live-verdict.md).

- Stop reporting an attack the model never attempted as a failed security case. `toolDidNotExecute()`
  failed in two situations that mean opposite things: the attacked capability executed — a breach —
  or it never appeared in the observation at all. Under a deterministic runner the second is
  unreachable, since the runner always drives the attacked capability. Under a live agent it is
  common: a model that reaches for a different tool, declines part-way, or answers with a read
  instead of a mutation produces no entry for the capability, and the case failed as though the
  boundary had broken.

  An absent capability now raises `CapabilityNotAttempted`, which `SecuritySuite` records as an
  error under the new `not_attempted` category and excludes from pass rates — the treatment
  `ModelDeclinedToAct`, `CaseNotLiveExpressible`, and `LiveObservationUnavailable` already receive.
  Absence of an attempted attack is absence of evidence, not a security finding. A capability that
  *executed* remains an assertion failure, unchanged.

  The assertion is now `Assertions::toolAttemptedButBlocked()`, which names what it enforces;
  `toolDidNotExecute()` is a deprecated alias with identical semantics. All four shipped packs use
  the new name, so the reported assertion label changes from `tool_did_not_execute` to
  `tool_attempted_but_blocked`. See [#139](https://github.com/fissible/verdict/issues/139).

  **This does not weaken the command's gate.** A threshold with no measured observations reports
  `NOT EVALUATED`, and `verdict:evaluation-live` exits non-zero unless both thresholds are `MET`, so
  a run that measured nothing cannot pass CI. Whether a threshold should be allowed to be `MET` on
  too few non-error observations is a separate question, tracked in
  [#138](https://github.com/fissible/verdict/issues/138).

  **Note for suites asserting on a prerequisite capability.** The packs use this assertion for the
  attacked capability *and* for prerequisites — `AccountRecoveryAttackPack` asserts it on identity
  verification as well as on recovery. An observation missing the prerequisite now reports as
  unmeasured rather than failed. The suite still does not pass, but the distinction moved from
  "the boundary failed" to "this case measured nothing", which is the more accurate reading.
- Refuse a multi-trial live evaluation that cannot make its trials independent, instead of reporting
  a pass rate that assumes an independence it does not have. `LiveEvaluationRunner` previously
  received one constructed `SecuritySuite` and looped it, so trial N observed whatever trial N-1 left
  behind — an approval receipt or execution claim from the first trial changed the second trial's
  disposition, and the aggregate reported a model failure the model had no part in.

  The runner now takes the factory rather than a suite and calls it once per trial. A run of more
  than one trial requires the new `LiveEvaluationTrialFactory`, whose single `makeForTrial()`
  operation resets application-owned state and then builds that trial's suite; it runs before every
  trial, including the first, since a process or database used before the run contaminates trial 0
  just as easily. A factory without it throws `LiveEvaluationRequiresTrialIsolation` **before any
  model is invoked**. Single-trial runs are unchanged and need no reset — one trial makes no
  independence claim.

  Two things were measured and rejected on the way to that design, and are recorded because they are
  the obvious guesses: rebuilding the `SecuritySuite` per trial isolates nothing, and
  `Container::forgetScopedInstances()` does not either, because Verdict's operational stores are
  singletons — correct production behaviour, and precisely why resetting is the application's job.

  Trial results are now aggregated by case identity rather than array position, so a factory may
  return its cases in any order. A suite whose name, version, case identities, per-case immutable
  metadata, or reproduction metadata change mid-run raises `TrialSuiteChanged` rather than being
  reconciled. Reproduction metadata is included because the report carries one such record for the
  whole aggregate: a factory that switched model, provider, prompt configuration, or policy revision
  between trials would otherwise have its results averaged into a report claiming a configuration
  they were not all produced under. See
  [#137](https://github.com/fissible/verdict/issues/137) and
  [ADR 0020](docs/adr/0020-live-trial-isolation-is-application-owned.md).

  **Upgrade note.** `LiveEvaluationRunner::run()` takes a `LiveEvaluationSuiteFactory` where it took
  a `SecuritySuite`. Callers using `verdict:evaluation-live` are unaffected; a caller driving the
  runner directly passes the factory it already resolves. An existing factory keeps working for
  single-trial runs with no change.

## [0.5.0] - 2026-08-13

- Stop an evidence-write failure from vetoing an action that every security control already
  permitted. The three mutating gates — consume a semantic rate-limit unit, consume an approval
  receipt, admit an execution claim — each commit operational state and then record it. A failure of
  that record previously propagated, abandoning the action while the mutation stood, which made
  evidence an authorization gate that [ADR 0007](docs/adr/0007-evidence-layering.md) decision point 2
  says it is not. At the claim gate it was worse: the exception unwound past the executor and left an
  admitted claim that was never finalized, blocking every future duplicate of that binding until an
  operator ran `verdict:resolve-execution-claim`.

  Such a failure now dispatches `EvidenceWriteFailed` and execution continues. The operational
  outcomes still gate — a denied rate limit, an unconsumable receipt, or a duplicate claim each
  remains a `Decision` that stops execution; only the *record* of them lost that power. Non-mutating
  gates are unchanged: before anything is mutated, abandoning is fail-closed and costs only a retry.

  **Operational note.** An evidence-store outage no longer halts protected actions; they execute with
  no durable record. That is what ADR 0007 requires, and it is a change in posture for a deployment
  that would rather fail closed than act unrecorded. A general fail-closed lever is tracked in
  [#160](https://github.com/fissible/verdict/issues/160). See
  [#153](https://github.com/fissible/verdict/issues/153) and ADR 0007's Update (#153).

- Mark Verdict service constructors `@internal` and remove the tool adapters' container fallback.
  `VerdictManager`, the managers it composes, and the tool adapters are container-resolved
  collaborators; constructing them directly was never documented and is now stated as unsupported in
  [`RELEASES.md`](RELEASES.md) and [ADR 0019](docs/adr/0019-verdict-services-are-container-resolved.md),
  so a new collaborator may be added as a required constructor parameter in any release. Build tool
  adapters through `Verdict::bound()` or `Verdict::guard()`.

  `AbstractVerdictTool`'s `InvocationContext` and `ApprovalExecutionContext` parameters are now
  required, and the `Container::getInstance()` fallback behind them is gone. **This is a deliberate
  downgrade in tolerance:** four-argument direct construction previously produced a working object
  whose collaborators came from wherever the global container happened to point, with no signal that
  anything had been substituted. It now fails immediately with a missing argument. Applications using
  `Verdict::bound()`, `Verdict::guard()`, or container resolution are unaffected. See
  [#157](https://github.com/fissible/verdict/issues/157).

- Reject a redaction path that the release allowlist can never match, instead of silently scrubbing
  nothing. A `StructuredRedactor` configured with `user.social_security` when the allowlist permits
  `user.socialSecurity` previously released the field in full and recorded the release as permitted;
  it now raises `UnreachableTransformerFieldPath` naming the offending path. The check compares
  configuration against configuration, so a path matching nothing in a particular payload stays
  legitimate — a wildcard over an empty collection, or an optional field a record happens to lack.
  Transformers declare their paths through a new optional `DeclaresFieldPaths` contract; the
  `ContextTransformer` contract is unchanged, and a transformer that does not implement the new one
  is skipped by the check.

  **Upgrade note.** This turns a previously silent misconfiguration into a release-time failure, so a
  release carrying a redaction path that was never matching will now throw where it used to return.
  That is the defect being fixed, but it surfaces at runtime rather than at deploy time. **The check
  does not reach inside a subtree allowlist:** `only(['user'])` makes both `user.socialSecurity` and a
  misspelled `user.social_security` reachable, so a typo under that subtree remains undetectable —
  allowlist the field explicitly if you want the check to protect it. `withoutFieldPathValidation()`
  opts a release out deliberately. See [#150](https://github.com/fissible/verdict/issues/150).

- Stop reporting a completed action as a failure when its at-most-once claim cannot be finalized.
  If the claim transition fails after the executor succeeded — most often because an operator ran
  `verdict:resolve-execution-claim` against a claim that looked stuck while its executor was still
  running — Verdict now throws `ExecutionCompletedWithUnfinalizedClaim`, carrying the executor's
  output and the claim ID, instead of a bare `LogicException` that a caller would reasonably read as
  "the action did not run". If instead the *evidence* write for that finalization fails, it no longer
  reaches the caller at all: an `EvidenceWriteFailed` event is dispatched and the successful result is
  returned, because an exception there is indistinguishable from execution failure. Retrying after
  either outcome still fails closed. `ExecutionClaimManager::complete()` and `markIndeterminate()` now
  raise `ExecutionClaimTransitionFailed` rather than `LogicException`, since an operator resolving a
  claim concurrently is a concurrency event, not a programming error. `VerdictManager::__construct()`
  takes an event dispatcher. See [#149](https://github.com/fissible/verdict/issues/149) and
  [ADR 0007](docs/adr/0007-evidence-layering.md) Update (#149).

- Add `verdict:evaluation-live`, an opt-in command that runs an existing attack pack against an
  application-supplied live Laravel AI agent. Verdict ships no provider, agent, tool, or model
  choice; the application supplies its suite factory through `verdict.evaluation.suites`.
  `verdict.evaluation.minimum_security_pass_rate` (default `1.0`) and
  `verdict.evaluation.minimum_utility_pass_rate` (default `0.8`) configure the two thresholds the
  command evaluates. See [#51](https://github.com/fissible/verdict/issues/51).

## [0.4.0] - 2026-08-12

- Make Laravel AI `BoundTool` callable contexts consistent across immediate approval preflight and
  execution within one invocation, without caching across approval resumes, direct calls, nested
  agent invocations, or request scopes. `VerdictManager::__construct()` now requires the scoped
  `ApprovalExecutionContext` dependency. See [#116](https://github.com/fissible/verdict/issues/116).

- Add refreshed-target and one-logical-operation capability starter patterns. They show existing
  Verdict policies with application-owned lookup, identity, and operation-binding callbacks; they
  do not introduce authorization, tenancy, side effects, or new public API. See
  [#109](https://github.com/fissible/verdict/issues/109).

- Add `verdict:make-approval-flow`, an opt-in generator for route-free, application-owned approval
  decision skeletons. It publishes no routes, middleware, views, notifications, jobs, or policies;
  adopters must supply reviewer authorization, tenant and conversation context, notification, and
  resumption behavior. See [#105](https://github.com/fissible/verdict/issues/105).

- Expose an admitted execution claim's opaque ID to target-bound capability executors through
  `AuthorizedAction::executionIdentity()`, so downstream side effects can use it as a stable
  idempotency key. The identity is `null` without `atMostOnce()` and remains stable for an
  operator-authorized retry. See [#34](https://github.com/fissible/verdict/issues/34).

- Add `verdict:make-capability`, an interactive or flag-driven generator for fail-closed capability
  and selected-control test skeletons. It writes no policy, route, or provider changes; target
  lookup, refresh, executor, and semantic bindings remain explicit application TODOs. See
  [#107](https://github.com/fissible/verdict/issues/107).

- Add a framework-agnostic `CapabilitySecurityTestKit` for driving application capabilities through
  Verdict's real protected execution path. It covers policy denial, refreshed targets, approval
  binding invalidation, duplicate claims, rate limits, and indeterminate claims after executor
  failures. `VerdictManager::registeredCapability()` exposes the application-registered capability
  to the kit, and `ApprovalManager::withinApprovedToolCalls()` executes an assertion in the same
  approval context Verdict validates. See [#108](https://github.com/fissible/verdict/issues/108).

- Add reproducible SQLite and MySQL security-state concurrency benchmark results for execution
  claims, semantic rate limits, and approval receipts. See [#16](https://github.com/fissible/verdict/issues/16).

- Add `verdict:validate`, a read-only wiring audit over registered capabilities. It executes no
  actions: it reports non-executable capabilities, capabilities that deliberately accept a stale
  execution-target snapshot, and — only for the store contracts the registered capabilities actually
  need — unresolvable stores and missing database tables. It exits non-zero on errors alone, so
  warnings and informational findings do not fail a deploy check. See
  [#36](https://github.com/fissible/verdict/issues/36).

- Retry approval-receipt state transitions on a database concurrency conflict.
  `DatabaseApprovalReceiptStore::approve()`, `reject()`, and `consume()` now take the same bounded
  retry boundary as `issue()`, so a deadlock while deciding or spending a human approval returns a
  policy outcome instead of an unhandled `QueryException`. Retrying cannot turn two consumers into two
  successful consumptions: a recognized conflict aborts its transaction, so the retry re-executes
  against committed state. Also fixes a conflict raised at COMMIT leaving the driver handle open,
  which made the retry itself fail on PostgreSQL. See
  [#100](https://github.com/fissible/verdict/issues/100) and
  [ADR 0018](docs/adr/0018-repeatable-read-and-serializable-require-a-conflict-retry.md).

- Retry every Verdict-owned security-state transaction on a database concurrency conflict, through one
  shared `SecurityStateTransaction` boundary now used by the approval-receipt, semantic rate-limit, and
  execution-claim stores alike — execution-claim transitions previously had no retry at all. The
  boundary makes at most four attempts, sleeping a randomized 10–50 ms scaled by attempt number so that
  synchronized first-insert races spread rather than re-collide. Conflicts are classified by Laravel's
  container-bound `ConcurrencyErrorDetector`, so an application that rebinds it is honored and SQLite's
  `database is locked` still counts as a conflict; a `DeadlockException` from an application-owned outer
  transaction is deliberately never retried. See [#86](https://github.com/fissible/verdict/issues/86)
  and [#112](https://github.com/fissible/verdict/issues/112).

- Fix prompt provenance for fresh streamed prompts. `VerdictProvenanceMiddleware` registers a pending
  prompt registration only when lazy iteration actually begins and Laravel AI dispatches
  `StreamingAgent`, instead of registering synchronously and discarding it before the stream is
  consumed. A streamed prompt now records its provenance, and a `StreamableAgentResponse` that is
  created but never iterated no longer pins a stale registration for the rest of the agent's request
  scope. See [#83](https://github.com/fissible/verdict/issues/83).

- Verify queued Laravel AI execution for Verdict authorization, execution claims, semantic rate
  limits, durable evidence, and context release using an actual database-queue `InvokeAgent` payload
  and `queue:work --once`. Queued approval resumption remains explicitly unverified because Laravel
  AI does not persist the initial job's pending tool-call response for a later queued decision without
  application-owned conversation state. See [#102](https://github.com/fissible/verdict/issues/102).

- Verify Laravel AI streamed execution for Verdict authorization, execution claims, semantic rate
  limits, and callable action context resolution through lazy `Agent::stream()` integration
  coverage. The verification uses Laravel AI's `FakeTextGateway` and a stub `CapabilityAuthorizer`;
  it asserts gate ordering and lazy-iteration timing, not live provider transport or policy
  resolution. See [#101](https://github.com/fissible/verdict/issues/101).

- Resolve PostgreSQL SERIALIZABLE rate-limit conflicts with up to three retries after increasing
  randomized delays (10–50 ms, then 20–100 ms, then 30–150 ms). The retries remain confined to
  Verdict-owned transactions; a synchronized 20-way PostgreSQL contention suite now requires every
  caller to receive a policy-shaped outcome. See [#97](https://github.com/fissible/verdict/issues/97).

- Narrow `CapabilityConfigurationStore` to a closure-free `CapabilityConfiguration` value object,
  so custom registry adapters receive only the content-addressed fingerprint and declared
  configuration they are permitted to retain. See [#91](https://github.com/fissible/verdict/issues/91).

- Split the experimental mixed `EvidenceRecorder` extension contract into `EvidenceWriter` and
  `ProvenanceLedgerStore`, so custom adapters implement only the write or ledger-read responsibility
  they provide. `EvidenceRecorder` remains a deprecated pre-1.0 compatibility bridge, and existing
  recorder configuration remains unchanged. See [#90](https://github.com/fissible/verdict/issues/90).

- Record actor and subject identity in decision evidence. `ActionContext` gains an optional `subject`
  — who the actor is acting on behalf of, defaulting to `null` for "the actor acts for itself" — and
  `DecisionEvidence` gains `actor_fingerprint` and `subject_fingerprint` columns via migration. Both
  are SHA-256 fingerprints under ADR 0008's privacy model, and both are populated only when the value
  implements the new `ProvidesVerdictIdentity` contract; anything else fingerprints as `null`, so
  identity is an application-declared canonical string rather than a guess at object shape. Evidence
  can now demonstrate the identity-binding layer of
  [ADR 0013](docs/adr/0013-authorization-binding-layers.md) after the fact, and record the delegation
  and escalation distinction of [ADR 0015](docs/adr/0015-authority-propagation.md). Existing
  `ActionContext` construction is unchanged. See [#31](https://github.com/fissible/verdict/issues/31).

- Fix streamed invocation-ID correlation: `VerdictProvenanceMiddleware` now keeps its invocation
  frame active while a `StreamableAgentResponse` is iterated, so lazily executed tool decisions and
  context releases retain their `invocation_id`. See [#80](https://github.com/fissible/verdict/issues/80).

- Add a consolidated security-state gate ordering table and a streaming/queued execution-mode
  compatibility matrix to `docs/architecture.md`, citing ADR 0001–0004 and ADR 0013 at each step.

- Add four operator-facing documents. `docs/adoption-guide.md` turns the documented security boundary
  into a pilot-first adoption plan, explicitly not a production certification
  ([#103](https://github.com/fissible/verdict/issues/103)). `docs/extension-contract-stability.md`
  inventories which interfaces are intentional extension points and what kind of extension each one
  supports ([#17](https://github.com/fissible/verdict/issues/17)).
  `docs/laravel-ai-compatibility.md` inventories every place `src/` depends on Laravel AI's surface,
  classifies each dependency by how silently it could change, and names the test that would catch it
  ([#18](https://github.com/fissible/verdict/issues/18)). `docs/evaluation.md` documents the evaluation
  harness, the shipped attack packs, baselines, and comparison
  ([#49](https://github.com/fissible/verdict/issues/49)).

- Fix streaming approval resumption: `VerdictApprovalMiddleware` now keeps the scoped
  approval context alive for a streamed response's full iteration instead of popping it
  when the middleware call returns, which happens before a lazy stream is ever consumed.
  An already-approved tool call inside a streamed agent response no longer fails closed
  with `ApprovalOutcome::InvalidState`. See [ADR 0006](docs/adr/0006-streaming-approval-resumption-deferred.md).

- Add a durable, content-addressed capability configuration registry. It records the declared
  configuration once at capability registration and resolves each evidence
  `configuration_fingerprint` to readable policy configuration without retaining closures or raw
  application data. The database-backed default is published with a dedicated migration; it is
  deliberately not pruned with evidence. See [ADR 0017](docs/adr/0017-configuration-identity-in-evidence.md)
  Decision §2–4.

- Add `Capability::configurationFingerprint()` — a SHA-256 over the capability's declared,
  security-material configuration (name, ability, confirmation requirement/TTL, execution-target
  policy, rate-limit policy, execution-claim policy, and an optional
  `Capability::configurationVersion()`), computed once at construction and carried on every
  `DecisionEvidence` row, so an audit can tell whether the rules governing a decision changed
  without anyone needing to remember to rename a policy. See
  [ADR 0017](docs/adr/0017-configuration-identity-in-evidence.md) Decision §1. Adds a
  `configuration_fingerprint` column to `verdict_evidence` via migration.

- Add a `tool_kind` field (`guarded` or `bound`) to `DecisionEvidence`, populated by
  `AbstractVerdictTool` from the concrete subclass, so applications can audit their own
  `GuardedTool` migration debt without grepping source. See
  [ADR 0005](docs/adr/0005-guardedtool-is-a-bounded-migration-bridge.md). Adds a `tool_kind` column
  to `verdict_evidence` via migration.

- Add configured and invocation-time tool-description fingerprints to Laravel AI Verdict tools, so
  applications can observe description drift without folding model-facing text into capability policy
  configuration.

- Add a deterministic `ToolIntegrityAttackPack` covering poisoned tool-description argument
  injection, capability shadowing, clean-tool utility, and tool-description drift pending on
  [#65](https://github.com/fissible/verdict/issues/65).
- Add explicit pending evaluation cases with mandatory blocker metadata and suspended-coverage
  comparison findings. `Score` now has a required fourth `pending` constructor argument. Baselines
  containing `pending` require this release or newer; older Verdict versions reject that enum value.

- Add an opt-in `AttestEvidenceRecorder` that writes signed, hash-chained decision and context-release
  evidence through `fissible/attest-laravel`. Chain topology is a required, explicit choice — a fixed
  chain id or a per-tenant `AttestChainResolver` class, with no default — plus optional provenance
  chaining, bounded write retries, and a durable `chain_gap` marker plus `ChainWriteFailed` event on
  exhaustion. See `docs/limitations.md`, "Tamper-evident evidence is opt-in, partial, and bounded by key
  custody", for what the chain does and does not guarantee.

- Record derivation edges between provenance entries, so a correlation is a graph rather than a set.
  `ProvenanceLedger::declareDerivation()` stores content-addressed child-to-parent edges — multiple
  parents per child — typed by a `DerivationKind` of `retrieved`, `summarized`, `transformed`, or
  `tool_result`, in a new `verdict_provenance_derivations` table published by migration.
  `backwardReachableContentFingerprints()` answers the forensic question the edges exist for: which
  inputs contributed to this output. `ContextReleaseManager` declares the one derivation Verdict
  observes directly — a release whose content changed is `transformed` from its source — and does not
  infer the rest; applications declare derivations they can prove. See
  [#30](https://github.com/fissible/verdict/issues/30).

- Correlate provenance entries with decision and context-release evidence. `DecisionEvidence` and
  `ContextReleaseEvidence` gain a nullable `invocationId`, persisted to an indexed `invocation_id`
  column on `verdict_evidence` via migration, so "what was in the context window when this capability
  ran" is one indexed lookup rather than a timestamp correlation. The identifier is Laravel AI's
  per-generation invocation ID, propagated through Verdict's execution scope with no application-side
  threading, and validated with the existing `ProvenanceEntry::assertIdentifier()` format. It reads
  `null` outside a Laravel AI invocation — a queue job, a controller, a test — which means "no
  invocation context", not "lost". Correlation is containment, not causality. See
  [#29](https://github.com/fissible/verdict/issues/29).

## [0.3.0] - 2026-08-08

- Reject unregistered and non-executable capabilities when `Verdict::bound()` constructs a tool, so a
  missing `->executeUsing()` surfaces as a wiring error instead of a request-time deny.

- Add a deterministic `RagBorneInjectionAttackPack` covering unauthorized, confirmable,
  argument-manipulation, and untrusted retrieved-document provenance cases for
  RAG-borne injection, plus an Observation-local provenance assertion.

## [0.2.0] - 2026-08-05

- Add a deterministic `AccountRecoveryAttackPack` with urgency-pressure identity-verification
  bypass coverage for account unlock and MFA reset, plus an ordered verification-decision
  assertion (`toolDecisionPrecedes`).
- Add an `AttackPack` contract and deterministic `StorefrontAttackPack` with paired lookup,
  cancellation, confirmation-mutation, replay, and retrieved-document security/utility cases, plus
  workbench evaluation consumption.
- Add an explicit provider-agnostic provenance ledger with canonical redacted content fingerprints,
  source/trust/classification/channel labels, correlation reads, and null, in-memory, and database
  recorder support through an additive evidence migration.
- Add validated atomic evaluation-baseline creation and console or GitHub Actions comparison
  commands with stable CI exit codes and escaped redacted findings.
- Preserve curated changelog history and documentation notes during release preparation.
- Mirror the repository's focused security topics in Composer package metadata.
- Add scam resistance as an exploratory, provider-neutral roadmap area.
- Add synchronous Laravel AI prompt and explicitly classified tool-result provenance hooks.
- Add an opt-in, provider-neutral repeated-trial live evaluation runner with dual execution gates,
  bounded trials, independent security and utility thresholds, and redacted aggregate reports.

## [0.1.1] - 2026-08-01

- Replace the private VCS installation instructions with the published Packagist command.

## [0.1.0] - 2026-08-01

- Scaffold the Laravel package for PHP 8.3 and Laravel 12/13.
- Add capability registration and Laravel Gate/Policy authorization.
- Add a fail-closed Laravel AI guarded-tool adapter that preserves tool approvals.
- Add pluggable, redacted decision evidence with stable argument fingerprints.
- Add deterministic IDOR coverage, static analysis, formatting, type coverage, and CI.
- Add target-bound capability executors and a strict Laravel AI `BoundTool` adapter.
- Re-inspect the same resolved in-process target immediately before bound execution.
- Distinguish proposal and execution authorization evidence.
- Convert explicitly signaled target-resolution failures into recorded denials.
- Document the current freshness boundary and unsafe long-running use of in-memory evidence.
- Add capability-bound, expiring, single-use approval receipts for synchronous Laravel AI
  `BoundTool` execution, including a transactional database store and fail-closed approval
  middleware, with a hashed receipt reference in decision evidence.
- Add a deterministic Testbench storefront security lab comparing naive, manually authorized,
  and Verdict-bound order access, plus argument tampering and approval replay demonstrations.
- Add fail-closed structured context release with source, trust, and data-class labels, explicit
  nested field projection, exact destination connection/trust-zone policies, and redacted release
  evidence.
- Add an independent storefront context-release lab comparing a permitted local Ollama route with
  a denied remote trust zone using the same provider name.
- Add an opt-in database evidence recorder and publishable migration for action decisions and
  context releases, hashing tool-call keys and retaining no raw arguments or released payloads.
- Add a provider-agnostic deterministic security-evaluation foundation with labeled trusted and
  untrusted inputs, structured observations, redacted results, explicit harness errors, and
  separate security-containment and legitimate-utility scoring.
- Add a versioned redacted JSON evaluation report and render the actual storefront attack and
  utility suite as an independent workbench lab.
- Add deterministic structured field redaction after explicit projection, prevent custom context
  transforms from expanding the allowlist, and record hashed transformation evidence.
- Add repo-native JSON evaluation baselines with distinct behavioral regression, harness error,
  new-failure, improvement, recovery, added-coverage, and removed-coverage findings.
- Add opt-in semantic execution rate limits with trusted application-defined bucket bindings,
  atomic fixed-window database counters, throttle evidence, and expired-bucket pruning.
- Add a deterministic storefront agent-loop lab showing three individually authorized shipment
  refresh proposals producing two executions and one semantic throttle.
- Add opt-in strict at-most-once executor admission with application-defined canonical operation
  bindings, durable atomic claim state, redacted evidence, and explicit operator reconciliation.
- Add an independent storefront lab demonstrating that changed provider call IDs and non-material
  prose cannot bypass a canonical operation claim.
- Add mandatory execution-target policies for `BoundTool`, including trusted refresh, canonical
  identity comparison, explicit stale-snapshot acknowledgement, and redacted freshness evidence.
- Validate approval receipts without mutation before rate limiting, then consume approval atomically
  before final execution-claim admission, with distinct evidence phases for each approval check.
- Fail closed when database-backed approval, semantic-limit, or execution-claim state would be
  mutated inside an already-active transaction on the same connection.
- Fix the storefront claim demonstration for PHPStan's PHP 8.5 analysis.
- Document the pre-1.0 public surface, support matrix, contribution workflow, and release policy.
- Verify package discovery, configuration/migration publication, migration execution, and Artisan
  command registration in a clean Laravel consumer CI job.
- Add Fissible-standard version, changelog, tag, and GitHub release tooling.

[Unreleased]: https://github.com/fissible/verdict/compare/v0.7.0...HEAD
[0.7.0]: https://github.com/fissible/verdict/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/fissible/verdict/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/fissible/verdict/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/fissible/verdict/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/fissible/verdict/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/fissible/verdict/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/fissible/verdict/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/fissible/verdict/releases/tag/v0.1.0
