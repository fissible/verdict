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

### No provider-internal inspection

Verdict does not inspect model weights, hidden reasoning, provider-side tool behavior, or arbitrary provider telemetry. Its Laravel AI integrations observe the package-supported application lifecycle, not every detail of a provider implementation.

### No PII inference

Verdict’s fingerprint-first evidence model avoids recording raw content by default. It is not a data-loss-prevention product and does not infer whether arbitrary prompts, tool arguments, or provider responses contain PII. Classify data before releasing it to a provider and configure all application logging accordingly.

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
