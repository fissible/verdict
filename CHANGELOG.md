# Changelog

All notable changes to Verdict will be documented in this file.

## [Unreleased]

- Add `verdict:evaluation-live`, an opt-in command that runs an existing attack pack against an
  application-supplied live Laravel AI agent. Verdict ships no provider, agent, tool, or model
  choice; the application supplies its suite factory through `verdict.evaluation.suites`. See
  [#51](https://github.com/fissible/verdict/issues/51).

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

[Unreleased]: https://github.com/fissible/verdict/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/fissible/verdict/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/fissible/verdict/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/fissible/verdict/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/fissible/verdict/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/fissible/verdict/releases/tag/v0.1.0
