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
| `CapabilityConfigurationStore` | Experimental | `DatabaseCapabilityConfigurationStore`, `InMemoryCapabilityConfigurationStore`, `NullCapabilityConfigurationStore` | Record capability configuration in an application-specific registry. See [#91](https://github.com/fissible/verdict/issues/91). |
| `ClassifiesToolResult` | Stable | None | Add provenance classification to an application's Laravel AI tool result. |
| `Clock` | Stable | `SystemClock` | Supply deterministic or deployment-specific time. |
| `ContextTransformer` | Stable | `StructuredRedactor` | Transform data before a release or other context-sensitive operation. |
| `EvidenceRecorder` | Experimental | `AttestEvidenceRecorder`, `DatabaseEvidenceRecorder`, `InMemoryEvidenceRecorder`, `NullEvidenceRecorder` | Send evidence to a project-specific durable, attestable, or external backend. See [#90](https://github.com/fissible/verdict/issues/90). |
| `ExecutionClaimStore` | Stable | `DatabaseExecutionClaimStore`, `InMemoryExecutionClaimStore` | Store exactly-once execution claims in a durable or external backend. |
| `ObservationAssertion` | Experimental | `CallbackAssertion` | Define an assertion for a capability evaluation observation. |
| `PrunableRateLimitStore` | Stable | `DatabaseRateLimitStore`, `InMemoryRateLimitStore` | Opt a `RateLimitStore` into expired-bucket cleanup. |
| `RateLimitStore` | Stable | `DatabaseRateLimitStore`, `InMemoryRateLimitStore` | Store rate-limit consumption in a durable or external backend. |

## Experimental contract follow-ups

`EvidenceRecorder` combines append and query responsibilities; [#90](https://github.com/fissible/verdict/issues/90)
tracks the pre-1.0 split into narrower write and ledger-query contracts.

`CapabilityConfigurationStore` currently receives a runtime `Capability`,
although a registry only needs declared, materialized configuration. [#91](https://github.com/fissible/verdict/issues/91)
tracks the closure-free value object for that boundary.
