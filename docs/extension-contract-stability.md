# Extension-contract stability

This inventory identifies the interfaces that Verdict intentionally exposes for
application adapters. It records the package-provided implementations and the
kind of extension each contract supports.

`Stable` contracts are intended to remain compatible through Verdict 1.0.
`Experimental` contracts are public for feedback and extension, but may change
before then. Neither label makes Laravel framework classes that happen to be
used by an implementation part of Verdict's public API.

## Contract inventory

| Contract | Stability | Built-in adapters | Application use |
| --- | --- | --- | --- |
| `ApprovalReceiptStore` | Stable | `DatabaseApprovalReceiptStore`, `InMemoryApprovalReceiptStore` | Store approval receipts in a durable or external backend. |
| `AttackPack` | Experimental | `AccountRecoveryAttackPack`, `RagBorneInjectionAttackPack`, `StorefrontAttackPack`, `ToolIntegrityAttackPack` | Define deterministic evaluation cases for a capability. |
| `AttestChainResolver` | Stable | None; applications configure the resolver class | Resolve a tenant- or deployment-specific attestation chain at runtime. |
| `CapabilityAuthorizer` | Stable | `LaravelPolicyAuthorizer` | Adapt a project's authorization system to capability decisions. |
| `CapabilityConfigurationStore` | Experimental | `DatabaseCapabilityConfigurationStore`, `InMemoryCapabilityConfigurationStore`, `NullCapabilityConfigurationStore` | Record capability configuration in an application-specific registry. |
| `ClassifiesToolResult` | Stable | None | Add provenance classification to an application's Laravel AI tool result. |
| `Clock` | Stable | `SystemClock` | Supply deterministic or deployment-specific time. |
| `ContextTransformer` | Stable | `StructuredRedactor` | Transform data before a release or other context-sensitive operation. |
| `DatabaseTableStore` | Experimental | `DatabaseApprovalReceiptStore`, `DatabaseExecutionClaimStore`, `DatabaseRateLimitStore` | Advertise tables to `verdict:validate`; it is not required for a primary store implementation. See [#88](https://github.com/fissible/verdict/issues/88). |
| `EvidenceRecorder` | Stable | `AttestEvidenceRecorder`, `DatabaseEvidenceRecorder`, `InMemoryEvidenceRecorder`, `NullEvidenceRecorder` | Send evidence to a project-specific durable, attestable, or external backend. |
| `ExecutionClaimStore` | Stable | `DatabaseExecutionClaimStore`, `InMemoryExecutionClaimStore` | Store exactly-once execution claims in a durable or external backend. |
| `ObservationAssertion` | Experimental | `CallbackAssertion` | Define an assertion for a capability evaluation observation. |
| `PrunableRateLimitStore` | Stable | `DatabaseRateLimitStore`, `InMemoryRateLimitStore` | Opt a `RateLimitStore` into expired-bucket cleanup. |
| `RateLimitStore` | Stable | `DatabaseRateLimitStore`, `InMemoryRateLimitStore` | Store rate-limit consumption in a durable or external backend. |

## Follow-up

`DatabaseTableStore` exists solely to let the deployment audit inspect the
tables used by Verdict's built-in database stores. Its public marker could be
the wrong extension boundary for custom stores, which do not need to expose a
table name. [#88](https://github.com/fissible/verdict/issues/88) tracks whether
to retain it as an opt-in public contract, make it internal, or replace it with
a narrower audit-specific extension point.
