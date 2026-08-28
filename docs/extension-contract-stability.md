# Extension-contract stability

This inventory identifies the interfaces Verdict intentionally exposes for
application adapters, the package-provided implementations, and the kind of
extension each contract supports.

`Stable` contracts are intended to remain compatible through Verdict 1.0.
`Experimental` contracts are public for feedback and extension, but may change
before then. Neither label makes Laravel framework classes that happen to be
used by an implementation part of Verdict's public API.

## Contract inventory

| Contract | Stability | Built-in adapters | Application use |
| --- | --- | --- | --- |
| `ActionIntentStore` | Experimental | `DatabaseActionIntentStore`, `InMemoryActionIntentStore` | Store write-ahead intent records for the fail-closed lever (#160): write-once `record()` plus `find()`, no update or delete by design. A custom store must treat a write failure as a throw — the pipeline converts it into a denial with nothing consumed. |
| `ApprovalReceiptStore` | Stable | `DatabaseApprovalReceiptStore`, `InMemoryApprovalReceiptStore` | Store approval receipts in a durable or external backend. |
| `ApprovalStatusReader` | Experimental | `DatabaseApprovalStatusReader`, `InMemoryApprovalStatusReader`, `StoreBackedApprovalStatusReader` | Observational reads of approval-receipt status (ADR 0031): per-receipt status plus pending enumeration scoped by `approval_context`, for reviewer queues and dashboards. A custom receipt store adds enumeration by implementing this contract for its backend. |
| `AttackPack` | Experimental | `AccountRecoveryAttackPack`, `RagBorneInjectionAttackPack`, `StorefrontAttackPack`, `ToolIntegrityAttackPack`, `DelegationConfusionAttackPack` | Define deterministic evaluation cases for a capability. |
| `AttestChainResolver` | Stable | None; applications configure the resolver class | Resolve a tenant- or deployment-specific attestation chain at runtime. |
| `CapabilityAuthorizer` | Stable | `LaravelPolicyAuthorizer` | Adapt a project's authorization system to capability decisions. |
| `CapabilityConfigurationStore` | Experimental | `DatabaseCapabilityConfigurationStore`, `InMemoryCapabilityConfigurationStore`, `NullCapabilityConfigurationStore` | Record a closure-free materialized capability configuration in an application-specific registry. |
| `ClassifiesToolResult` | Stable | None | Add provenance classification to an application's Laravel AI tool result. |
| `Clock` | Stable | `SystemClock` | Supply deterministic or deployment-specific time. |
| `ContextTransformer` | Stable | `StructuredRedactor` | Transform data before a release or other context-sensitive operation. |
| `DeclaresExpressibleToolShapes` | Experimental | `AccountRecoveryAttackPack`, `RagBorneInjectionAttackPack`, `StorefrontAttackPack`, `ToolIntegrityAttackPack`, `DelegationConfusionAttackPack` | Declare the tool shapes an attack pack can express — the coverage manifest surfaced in run output beside coverage reporting. |
| `DurableEvidenceRecorder` | Experimental | `AttestEvidenceRecorder`, `DatabaseEvidenceRecorder` | Marker, no methods: declare that a recorder retains evidence, so an unset `verdict.capability_configurations.store` falls through to the durable configuration store instead of the no-op one (#310). |
| `EvidenceWriter` | Experimental | `AttestEvidenceRecorder`, `DatabaseEvidenceRecorder`, `InMemoryEvidenceRecorder`, `NullEvidenceRecorder` | Send Verdict evidence to a project-specific durable, attestable, or external backend. |
| `ProvenanceLedgerStore` | Experimental | `AttestEvidenceRecorder`, `DatabaseEvidenceRecorder`, `InMemoryEvidenceRecorder`, `NullEvidenceRecorder` | Query a provenance and derivation ledger without taking on evidence writes. |
| `EvidenceRecorder` | Experimental, deprecated pre-1.0 | `AttestEvidenceRecorder`, `DatabaseEvidenceRecorder`, `InMemoryEvidenceRecorder`, `NullEvidenceRecorder` | Legacy mixed write/query compatibility bridge; use `EvidenceWriter` and/or `ProvenanceLedgerStore` for new adapters. |
| `ExecutionClaimStore` | Stable | `DatabaseExecutionClaimStore`, `InMemoryExecutionClaimStore` | Store exactly-once execution claims in a durable or external backend. |
| `ExecutionWindow` | Experimental | `ConnectionPredicateCapture` | Observe capability executor invocations — the seam `VerdictManager` opens around exactly the executor call, used by the evaluation harness's predicate capture. Implementations must return the execution's result unchanged and let its exceptions propagate. |
| `ObservationAssertion` | Experimental | `CallbackAssertion` | Define an assertion for a capability evaluation observation. |
| `RegistersSecrets` | Experimental | `StorefrontAttackPack` | Declare the canary tokens an attack pack plants, so an argument scan can be armed with them (ADR 0032). Labels are persisted; values never are. |
| `PrunableRateLimitStore` | Stable | `DatabaseRateLimitStore`, `InMemoryRateLimitStore` | Opt a `RateLimitStore` into expired-bucket cleanup. |
| `RateLimitStore` | Stable | `DatabaseRateLimitStore`, `InMemoryRateLimitStore` | Store rate-limit consumption in a durable or external backend. |

## Experimental contract follow-ups

`EvidenceRecorder` was split by [#90](https://github.com/fissible/verdict/issues/90) into narrower
`EvidenceWriter` and `ProvenanceLedgerStore` contracts. It remains only as a pre-1.0 compatibility bridge.

`CapabilityConfigurationStore` receives `CapabilityConfiguration`, a closure-free value object
containing only the fingerprint and declared configuration a registry may retain. [#91](https://github.com/fissible/verdict/issues/91)
made this boundary explicit before 1.0.
