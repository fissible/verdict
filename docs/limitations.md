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

### No tamper-evident evidence

The database evidence adapter is an ordinary mutable audit store. It is not append-only, immutable, signed, or tamper-evident, and it must not be described as cryptographic proof. A row recording a decision, approval, or provenance fact can be edited or deleted without detection. A tamper-evident adapter may be offered separately in the future; see [ADR 0007](adr/0007-evidence-layering.md).

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
