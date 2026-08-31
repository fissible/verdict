<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\Intents\DatabaseActionIntentStore;
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;

return [
    'capabilities' => [
        // Discovers capability *definition classes* in your application — classes implementing
        // Fissible\Verdict\Contracts\DefinesCapability. Implementing that contract is what makes a
        // generated capability discoverable; until then it sits here inert, and verdict:validate
        // names it. Not to be confused with `capability_configurations` below, which is the durable
        // registry of recorded capability configuration. See ADR 0027.
        //
        // An empty paths array disables discovery entirely.
        'discovery' => [
            'paths' => [
                app_path('Capabilities'),
            ],
        ],
    ],

    'capability_configurations' => [
        // Not to be confused with `capabilities.discovery` above, which finds definition classes in
        // your application. This is the durable registry that expands a configuration fingerprint
        // back into the declared configuration that produced it.
        // This durable, content-addressed registry expands configuration_fingerprint values in
        // evidence into readable declared policy configuration. Null selects the database store
        // automatically for any recorder that implements the DurableEvidenceRecorder marker
        // contract (both shipped durable recorders do; a custom durable recorder opts in by
        // implementing it), and a no-op store otherwise — verdict:validate warns when a recorder
        // that declares nothing meets that no-op fall-through (#310).
        // Do not use a cache as the only durable store: Redis eviction
        // would orphan surviving evidence. Object storage is likewise not the default because
        // registration is on the authorization setup path. ADR 0017 explains those trade-offs.
        // This table is intentionally never pruned with evidence.
        'store' => null,
        'connection' => null,
        'table' => 'verdict_capability_configurations',
    ],

    'approvals' => [
        // Database security-state mutations fail if this connection is already inside an outer
        // transaction. Use a separately committed connection when wrapping Verdict itself.
        'store' => DatabaseApprovalReceiptStore::class,
        'connection' => null,
        'table' => 'verdict_approval_receipts',
        'ttl_seconds' => 900,
        // Class implementing Fissible\Verdict\Contracts\ApprovalDecisionAuthorizer, consulted by
        // ApprovalManager::approve()/reject() before any receipt is finalized. REQUIRED for
        // deciding receipts: approval decisions are fail-closed, so with this unset approve() and
        // reject() refuse rather than trusting the caller's approved_by string. The authorizer is
        // where the application makes approved_by mean something — verify the receipt's
        // approval_context (tenant, conversation, ...) against what the decision maker owns.
        // `php artisan verdict:make-approval-flow` publishes a working example.
        'authorizer' => null,
        // Deny a consequential proposal whose declared provenance is unknown, rather than asking a
        // human to approve an action whose origin nobody can describe. OFF BY DEFAULT AND MEANT TO
        // STAY OFF until an application's derivation declarations are thorough enough to trust:
        // derivation declaration is opt-in, so enabling this before adopting it converts a
        // documented incompleteness into a refusal at the worst possible moment, and the pressure
        // that creates is to declare something rather than to declare accurately. Verdict ships the
        // visibility; the adopter sets the policy. Enabling this without a registered approver
        // release policy is a contradiction and refuses at boot. See ADR 0026 §5.
        'strict_provenance' => false,
    ],

    'reviews' => [
        'store' => null,
        'connection' => null,
        'table' => 'verdict_review_requests',
        'ttl_seconds' => 900,
        'authorizer' => null,
    ],

    'evidence' => [
        // InMemoryEvidenceRecorder is only for tests and local development. Its unbounded,
        // process-local state is unsafe for production, Octane, and queue workers.
        //
        // The shipped default is NullEvidenceRecorder, a no-op: a fresh install records nothing
        // until you configure a durable recorder. This is deliberate (writing actor identities to
        // a table you did not choose is an imposition, and evidence is never an authorization gate
        // — ADR 0007), but its absence is not silent: Verdict dispatches ConsequentialActionUnrecorded
        // once per process when a confirmation- or at-most-once-gated capability runs under it, and
        // `verdict:validate` reports it. Neither blocks. See #194.
        'recorder' => NullEvidenceRecorder::class,

        // Pre-1.0 extension migration: `recorder` is the legacy mixed read/write contract.
        // New adapters may configure either narrow contract independently. If either is null,
        // Verdict uses the legacy recorder for that responsibility.
        'writer' => null,
        'ledger' => null,
        'connection' => null,
        'table' => 'verdict_evidence',
        // The provenance-derivations table the database recorder writes alongside evidence. Read
        // by both the recorder and the published migration stub, so renaming it — like every table
        // here — is a config change only (#290).
        'derivations_table' => 'verdict_provenance_derivations',
        'operations_table' => 'verdict_approval_operations',

        // Only consulted when 'recorder' is AttestEvidenceRecorder::class. Requires
        // fissible/attest-laravel (composer require fissible/attest-laravel) — see
        // docs/limitations.md, "Tamper-evident evidence is opt-in, partial, and bounded
        // by key custody".
        'attest' => [
            // Exactly one of 'chain' or 'chain_resolver' must be set — there is no default.
            // This choice is not safely changeable later: a chain's hash-linked history
            // cannot be retroactively split by tenant. See docs/limitations.md,
            // "Tamper-evident evidence is opt-in, partial, and bounded by key custody".
            //
            // 'chain': a fixed chain id. Every deployment writes every decision and context
            // release to this one chain. Only correct for genuinely single-tenant
            // deployments — every chained write serializes behind this one chain's append
            // lock.
            'chain' => env('VERDICT_ATTEST_CHAIN'),

            // 'chain_resolver': a class implementing
            // Fissible\Verdict\Contracts\AttestChainResolver, resolved through the
            // container fresh on every write (never cached), so a request-scoped or
            // tenant-scoped binding inside resolve() is re-evaluated each time. This is
            // the recommended path for per-tenant chains — it supersedes binding a custom
            // EvidenceRecorder via $app->extend() for this specific need.
            // $app->extend(EvidenceRecorder::class, ...) remains the right tool for
            // customization this can't express: swapping the fallback recorder, varying
            // on_failure per tenant, or replacing the whole EvidenceRecorder.
            //
            // Example:
            //   final class TenantChainResolver implements \Fissible\Verdict\Contracts\AttestChainResolver
            //   {
            //       public function resolve(): string
            //       {
            //           return 'tenant:'.CurrentTenant::id();
            //       }
            //   }
            //   // config/verdict.php:
            //   'chain_resolver' => \App\Support\TenantChainResolver::class,
            //
            //   // or, in .env — unquoted, so the backslashes are taken literally
            //   // (if you quote it, double them: "App\\Support\\TenantChainResolver"):
            //   VERDICT_ATTEST_CHAIN_RESOLVER=App\Support\TenantChainResolver
            'chain_resolver' => env('VERDICT_ATTEST_CHAIN_RESOLVER'),

            // The non-chained fallback recorder's connection/table. Provenance entries and
            // derivations are always readable through this table; decisions and context
            // releases are not (they exist only in the attest chain, plus a "chain_gap"
            // marker row here if a chained write ever exhausts its retries).
            'fallback_connection' => null,
            'fallback_table' => 'verdict_evidence',

            // Off by default: provenance volume scales with retrieved context, which can be
            // orders of magnitude larger than decisions, and chaining it by default would
            // make throughput unrepresentative of what most deployments need.
            'chain_provenance' => false,

            // 'alert' (default) never blocks the protected action — ADR 0007 already
            // decided evidence is not an authorization gate. 'throw' is for deployments
            // whose compliance regime requires fail-closed on evidence-write failure.
            //
            // Boundary with the `intents` lever below (#160): this key governs the
            // evidence layer — records that already exist gaining attestation, including
            // the intent's fail-open evidence mirror. The pre-mutation operational intent
            // write is governed by `verdict.intents.required`; the two never express the
            // same preference.
            'on_failure' => 'alert',
            'max_attempts' => 3,
            'base_delay_ms' => 50,
        ],
    ],

    'rate_limits' => [
        // InMemoryRateLimitStore is only for tests and local development. Its process-local
        // counters do not coordinate across requests, workers, or application nodes.
        // Database consumption also requires an independently committed connection.
        'store' => DatabaseRateLimitStore::class,
        'connection' => null,
        'table' => 'verdict_rate_limit_buckets',
    ],

    'execution_claims' => [
        // InMemoryExecutionClaimStore is only for tests and local development. Its process-local
        // state cannot prevent duplicate execution across workers or application nodes.
        // Database claim transitions also require an independently committed connection.
        'store' => DatabaseExecutionClaimStore::class,
        'connection' => null,
        'table' => 'verdict_execution_claims',
    ],

    'intents' => [
        // The fail-closed lever for deployments that must not act unrecorded (#160). With the
        // lever effective for a capability, Verdict commits one write-ahead intent record —
        // operational security state, on this store's connection — after every non-mutating gate
        // has passed and before the first mutating gate runs. If that write fails, the action is
        // denied with nothing consumed: no rate-limit unit, no approval receipt, no execution
        // claim. The default is off, preserving ADR 0007's posture exactly.
        //
        // Boundary with `evidence.attest.on_failure`: that key governs the *evidence layer* — a
        // record that already exists gaining attestation, including the fail-open evidence mirror
        // of this intent row. This lever governs the *pre-mutation operational write*. They never
        // express the same preference; neither replaces the other.
        //
        // What this cannot cover, stated plainly: outcome evidence written after a committed
        // mutation stays fail-open (EvidenceWriteFailed, ADR 0007 #153), and nothing can fail
        // closed after a successful executor — the side effect has happened. The intent row makes
        // both gaps detectable (an intent id no outcome row references) rather than silent.
        'required' => false,
        // Per-capability override in either direction: ->requiresIntentRecord() / (false).
        //
        // Cost, stated plainly: every attempt that reaches the intent gate commits one durable
        // row plus a fail-open evidence mirror — including attempts a later gate denies. Storage
        // grows with attempts, not successes, and no pruning command ships (see the retention
        // note below).
        //
        // InMemoryActionIntentStore is only for tests and local development: process-local state
        // cannot make the durability promise this lever exists to give.
        // Database intent writes require an independently committed connection.
        'store' => DatabaseActionIntentStore::class,
        'connection' => null,
        'table' => 'verdict_action_intents',
        // Retention is an application-owned compliance decision (ADR 0009's reasoning applies):
        // intent rows ARE the record a fail-closed deployment exists to keep. Verdict ships no
        // pruning command for them; archive before removing, on the window your regime requires.
    ],

    'ai' => [
        'denied_message' => 'This action was not authorized.',
    ],

    'evaluation' => [
        // Live evaluation makes real provider calls. It requires this configuration opt-in and
        // LiveEvaluationOptions(enabled: true) at the call site.
        'live_enabled' => false,
        'maximum_trials' => 25,
        // Thresholds the command passes to LiveEvaluationOptions. A live run fails when the measured
        // rate falls below these, when nothing could be evaluated, or when coverage is inadequate.
        'minimum_security_pass_rate' => 1.0,
        'minimum_utility_pass_rate' => 0.8,
        // An absolute floor on evaluated observations per purpose. Zero disables it. This is your
        // sample-size policy — how many observations you consider enough to act on.
        //
        // It is separate from, and additional to, the coverage rule Verdict always applies: a
        // purpose whose measurable-but-unmeasured outcomes outnumber its evaluated ones reports
        // INSUFFICIENT regardless of this setting. Coverage asks how much of what could have been
        // measured was; this asks how much is enough. Neither is a statistical confidence claim.
        'minimum_observations' => 0,
        // The over-restriction gate (#280). A filtered-permit attack case passes its security
        // oracle while missing its utility one when the guard held and the model under-delivered
        // — scored as passed with an over_restricted tally (#276). This is the highest such rate,
        // per filtered-permit case over its evaluated trials (inclusive), before the run fails.
        // 1.0 allows any rate: the gate is reported but never fails a run until you lower it.
        // Ignored by suites with no filtered-permit case.
        'maximum_over_restriction_rate' => 1.0,
        // THE CONTROL ARM DELIBERATELY LETS ATTACKS SUCCEED. With --control, every attack case
        // also runs against the same agent with Verdict's tool wrapping absent, so the dangerous
        // capability actually executes — a real refund, a real cancellation, whatever the tools
        // do. Synthetic, reversible data is a precondition, not advice. This gate is additional
        // to the two live-evaluation opt-ins above and defaults off; the factory must also
        // implement Fissible\Verdict\Contracts\LiveEvaluationControlArmFactory. See ADR 0023.
        'control_enabled' => false,
        // Map a suite name to a class implementing
        // Fissible\Verdict\Contracts\LiveEvaluationSuiteFactory. The application owns
        // its agent, model, tools, fixtures, and provider credentials.
        'suites' => [
            // 'storefront' => App\Evaluation\StorefrontLiveSuiteFactory::class,
        ],
    ],
];
