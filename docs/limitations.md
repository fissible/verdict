# Verdict limitations

Verdict deliberately secures a narrow boundary: application actions that are registered as capabilities and invoked through Verdict. It does not claim to solve every security or reliability problem around AI systems.

## What Verdict does not guarantee

### No complete TOCTOU protection

Refreshing an execution target narrows the gap between authorization and execution, but it cannot make mutable databases immutable or eliminate concurrent changes. Use transactions, row locks, optimistic concurrency, idempotency, and domain checks where the operation needs them.

### No replacement for Laravel authorization or domain rules

Verdict calls Laravel authorization; it does not create your policies, tenancy model, ownership rules, validation, or business invariants. A poorly scoped target resolver or policy remains an application bug.

### No protection for bypassed paths

Only tools and code paths that use Verdict are protected. An unwrapped Laravel AI tool, a controller, a queue job, a scheduled task, or another service can still invoke the underlying side effect unless your application applies its own controls there too.

### No guarantee of downstream side effects

An execution claim controls Verdict admission. It cannot guarantee exactly-once completion in a payment processor, email API, queue, or remote system after the executor begins. Design external integrations with idempotency keys, transactional outboxes, reconciliation, and compensating operations where appropriate.

When an executor fails without a conclusive outcome, Verdict marks the claim indeterminate rather than guessing or caching a potentially sensitive result. An operator must investigate and reconcile it:

```bash
php artisan verdict:execution-claims
php artisan verdict:resolve-execution-claim CLAIM_ID completed \
    --by=operator:7 --reason="Carrier confirmed cancellation succeeded"
php artisan verdict:resolve-execution-claim CLAIM_ID retryable \
    --by=operator:7 --reason="Carrier confirmed no request was accepted"
```

Resolving a claim as `retryable` releases it for one explicit retry. A claim still marked active requires `--force`, which should be used only after application-specific investigation. Claim rows are part of the guarantee horizon, so Verdict provides no automatic pruning command; see [ADR 0009](adr/0009-execution-claim-retention.md).

### No provider-internal inspection

Verdict does not inspect model weights, hidden reasoning, provider-side tool behavior, or arbitrary provider telemetry. Its Laravel AI integrations observe the package-supported application lifecycle, not every detail of a provider implementation.

### No PII inference

Verdict’s fingerprint-first evidence model avoids recording raw content by default. It is not a data-loss-prevention product and does not infer whether arbitrary prompts, tool arguments, or provider responses contain PII. Classify data before releasing it to a provider and configure all application logging accordingly.

Content and component fingerprints are deterministic. A hash of a predictable prompt, identifier, version, filename, URL, or personal value can be guessed and must be treated as correlation—not anonymization, encryption, or proof that the underlying input is safe.

### Tamper-evident evidence is opt-in, partial, and bounded by key custody

`DatabaseEvidenceRecorder` (the usual choice when an application opts into evidence recording — `verdict.evidence.recorder` itself defaults to `NullEvidenceRecorder`, a no-op, so nothing is recorded unless explicitly configured) is an ordinary mutable audit store: not append-only, immutable, signed, or tamper-evident. A row recording a decision, context release, or provenance fact can be edited or deleted without detection. It must not be described as cryptographic proof.

`AttestEvidenceRecorder` (requires `composer require fissible/attest-laravel`) writes signed, hash-chained evidence via [`fissible/attest`](https://github.com/fissible/attest) instead. Even with it configured, several things remain true:

- **Only decisions and context releases are chained by default.** Provenance entries and derivations always go through the ordinary `DatabaseEvidenceRecorder` fallback (for read access — `provenanceFor()`/`derivationsFor()` have no chain-backed implementation) unless `verdict.evidence.attest.chain_provenance` is enabled, because provenance volume can be orders of magnitude larger than decision volume. An unchained provenance ledger is not covered by the chain's tamper-evidence guarantee — a team that assumes "Verdict has tamper-evident evidence" covers provenance will be wrong at exactly the wrong moment.
- **Approval receipts are not in the evidence layer at all, chained or otherwise.** Per [ADR 0007](adr/0007-evidence-layering.md), `verdict_approval_receipts` is *operational state* — an authoritative store that gates execution — not evidence, so no `EvidenceRecorder` writes it and no recorder chains it. Decision evidence records a fingerprint of the receipt that was consumed, and that fingerprint is chained; the receipt row itself remains an ordinary mutable row that can be edited or deleted without detection.
- **Chain topology is a required, explicit choice — there is no default.** Configure exactly one of `verdict.evidence.attest.chain` (a fixed chain id, correct only for genuinely single-tenant deployments — every chained write serializes behind that one chain's append lock) or `verdict.evidence.attest.chain_resolver` (a class implementing `Fissible\Verdict\Contracts\AttestChainResolver`, resolved fresh on every write, for per-tenant chains). Any chain-topology misconfiguration — neither set, both set, or a `chain_resolver` that does not exist, does not implement the contract, or is not instantiable — throws the first time Verdict resolves its evidence recorder, rather than picking a default. In practice that is the first time anything in the application actually needs to record evidence, not when the framework boots, and it can be triggered by any Laravel AI event Verdict listens to (`PromptingAgent`, `ToolInvoked`), not only by a Verdict-guarded capability action. The reason for throwing rather than defaulting: a chain's hash-linked history cannot be retroactively split by tenant, so this choice is not safely revisable after evidence has been written. `chain_resolver` supersedes binding a custom recorder via `$app->extend(EvidenceRecorder::class, ...)` for this specific need — that mechanism remains available for customization `chain_resolver` can't express, such as swapping the fallback recorder or varying `on_failure` per tenant. See the worked example in `config/verdict.php`.
- **Local integrity, not global integrity, by default.** A tamper-evident chain proves nothing was edited after the fact only once someone actually verifies it (`php artisan attest:verify`) — an unverified chain is tamper-evident only in retrospect. Verdict does not schedule this for you; see [#41](https://github.com/fissible/verdict/issues/41) for recommended cadence.
- **Truncation is possible and locally undetectable.** An attacker who controls the evidence store can truncate a chain to a chosen point and re-link it; a truncated chain still verifies as internally consistent. Anchoring (`php artisan attest:anchor`, via `fissible/attest-laravel`) is the mitigation — it publishes a Merkle root a rewritten chain cannot reproduce — but anchoring is `@experimental` in `fissible/attest` 1.x and confirms with a lag equal to the anchor interval, not immediately. Three further mitigations are the application's to adopt, and none of them wait on an anchor confirming:
    - **Publish each chain's head and entry count** so an auditor can record them out-of-band. A truncation that happens after a recorded head is detectable with no anchoring involved at all.
    - **Record monotonic per-chain sequence numbers**, so a missing range is visible even when the surviving chain re-links cleanly.
    - **Give the evidence connection its own database credentials**, distinct from the application's (`verdict.evidence.connection` and `verdict.evidence.attest.fallback_connection` both accept a dedicated connection). This does not stop a determined operator, but it removes SQL injection as a single-step path from the application to audit tampering.
- **"Verified" names five different claims, and a deployment must know which one it has.** `fissible/attest` reports verification at one of five levels — `local_only`, `pending`, `upgraded_no_headers`, `remote_header_confirmed`, and `bitcoin_verified` — and they are not equivalent: `remote_header_confirmed` puts a block explorer in the trust path, and `bitcoin_verified` does not. Pass `php artisan attest:verify --min-anchor=...` to fail verification below the level you actually require, rather than accepting whichever level a run happens to reach.
- **Tamper-evidence is bounded by key custody.** The chain is tamper-evident against anyone who can reach the evidence store but not the Ed25519 signing key (`ATTEST_SIGNING_KEY_SEED`). An attacker holding that key can rewrite the chain and re-sign it, and verification will pass. Application RCE implies the ability to forge history unless the key is held outside the application's own reach.
- **A failed chain write does not block the protected action.** Per [ADR 0007](adr/0007-evidence-layering.md), evidence is not an authorization gate. `AttestEvidenceRecorder` retries a failed write with backoff, then records a `chain_gap` marker row in the ordinary evidence table (naming the chain and attempt count) and raises an event the application can route to an alert — it does not fail the request unless explicitly configured with `on_failure: 'throw'`.

See the [`AttestEvidenceRecorder` source](../src/Evidence/AttestEvidenceRecorder.php) for the exact configuration surface.

## Provenance derivation is deliberately incomplete

Verdict records a derivation edge only when it observed a transformation directly, such as an application context release, or when an application explicitly declared one. It does not infer that retrieved content influenced a model output, tool request, or decision merely because the records share an invocation. Missing derivation edges mean "not observed or not declared," not "no influence occurred."

The evidence store may also contain highly sensitive information. Configurable evidence levels, retention, tenant isolation, access authorization, pruning, and encryption remain application responsibilities.

### No content moderation or factual review

Verdict does not establish factual correctness or provide general content moderation. It also does not buffer or inspect streamed model output: streaming output cannot be retracted after it has been sent, so sensitive response checks may need to happen before generation rather than after. See [ADR 0011](adr/0011-rejected-verdict-does-not-buffer-streamed-output.md).

### No universal security policy

The package cannot decide which actions require approval, what makes two business actions equivalent, or what a safe rate limit should be. Those decisions are encoded in capability configuration and the surrounding application.

## Operational responsibilities

Before protecting a consequential action, the application team should:

- write and test the Laravel policy and trusted target resolver;
- choose whether approval, replay prevention, and semantic limits are needed;
- include all material facts in approval and claim identities;
- add domain-level concurrency and idempotency controls;
- protect non-AI invocation paths consistently; and
- review data release, provider, logging, and retention practices.

These constraints are intentional. They keep Verdict focused on governance and security at the AI-to-application action boundary rather than pretending to replace the rest of a secure Laravel system.
