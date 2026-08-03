# Changelog

All notable changes to Verdict will be documented in this file.

## [Unreleased]

- Add validated atomic evaluation-baseline creation and console or GitHub Actions comparison
  commands with stable CI exit codes and escaped redacted findings.
- Preserve curated changelog history and documentation notes during release preparation.
- Mirror the repository's focused security topics in Composer package metadata.
- Add scam resistance as an exploratory, provider-neutral roadmap area.

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

[Unreleased]: https://github.com/fissible/verdict/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/fissible/verdict/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/fissible/verdict/releases/tag/v0.1.0
