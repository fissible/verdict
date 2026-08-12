# Changelog

All notable changes to Verdict will be documented in this file.

## [Unreleased]

- Retry approval-receipt state transitions on a database concurrency conflict.
  `DatabaseApprovalReceiptStore::approve()`, `reject()`, and `consume()` now take the same bounded,
  jittered retry as `issue()`, so a deadlock while deciding or spending a human approval returns a
  policy outcome instead of an unhandled `QueryException`. Retrying cannot turn two consumers into two
  successful consumptions: a recognized conflict aborts its transaction, so the retry re-executes
  against committed state. Also fixes a conflict raised at COMMIT leaving the driver handle open,
  which made the retry itself fail on PostgreSQL. See
  [#100](https://github.com/fissible/verdict/issues/100) and
  [ADR 0018](docs/adr/0018-repeatable-read-and-serializable-require-a-conflict-retry.md).

- Narrow `CapabilityConfigurationStore` to a closure-free `CapabilityConfiguration` value object,
  so custom registry adapters receive only the content-addressed fingerprint and declared
  configuration they are permitted to retain. See [#91](https://github.com/fissible/verdict/issues/91).

- Split the experimental mixed `EvidenceRecorder` extension contract into `EvidenceWriter` and
  `ProvenanceLedgerStore`, so custom adapters implement only the write or ledger-read responsibility
  they provide. `EvidenceRecorder` remains a deprecated pre-1.0 compatibility bridge, and existing
  recorder configuration remains unchanged. See [#90](https://github.com/fissible/verdict/issues/90).

- Fix streamed invocation-ID correlation: `VerdictProvenanceMiddleware` now keeps its invocation
  frame active while a `StreamableAgentResponse` is iterated, so lazily executed tool decisions and
  context releases retain their `invocation_id`. See [#80](https://github.com/fissible/verdict/issues/80).

- Add a consolidated security-state gate ordering table and a streaming/queued execution-mode
  compatibility matrix to `docs/architecture.md`, citing ADR 0001–0004 and ADR 0013 at each step.

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

[Unreleased]: https://github.com/fissible/verdict/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/fissible/verdict/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/fissible/verdict/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/fissible/verdict/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/fissible/verdict/releases/tag/v0.1.0
