<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Fissible\Verdict\Approvals\ApproverAudience;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Approvals\StoreBackedApprovalStatusReader;
use Fissible\Verdict\Capabilities\CapabilityConfigurationStoreSelection;
use Fissible\Verdict\Capabilities\CapabilityDiscovery;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Capabilities\InMemoryCapabilityConfigurationStore;
use Fissible\Verdict\Capabilities\NullCapabilityConfigurationStore;
use Fissible\Verdict\Capabilities\UnaffirmedDefinition;
use Fissible\Verdict\Console\DatabaseTableStore;
use Fissible\Verdict\Console\SessionTimezoneAudit;
use Fissible\Verdict\Context\ReleasePolicyRegistry;
use Fissible\Verdict\Contracts\ActionIntentStore;
use Fissible\Verdict\Contracts\ApprovalDecisionAuthorizer;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\EffectiveEvidenceClass;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\ExecutionClaims\InMemoryExecutionClaimStore;
use Fissible\Verdict\Intents\ActionIntentManager;
use Fissible\Verdict\Intents\InMemoryActionIntentStore;
use Fissible\Verdict\RateLimits\InMemoryRateLimitStore;
use Fissible\Verdict\Targets\ExecutionTargetStrategy;
use Fissible\Verdict\Testing\AllowAllApprovalAuthorizer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Throwable;

final class ValidateVerdictCommand extends Command
{
    protected $signature = 'verdict:validate {--strict : Also fail on advisory warnings, not only on configuration that will fail at runtime}';

    protected $description = 'Audit registered Verdict capability wiring without executing actions';

    /**
     * Exit-code contract: verdict:validate returns non-zero only for configuration that will
     * **fail at runtime** (a store whose backing table is missing, an unresolvable custom store).
     * Configuration that is legal but probably not intended — a no-op evidence recorder, a
     * non-durable store outside local (#146) — is **advisory** and warns without failing. `--strict`
     * opts into failing on advisory findings too, for adopters who want CI to block on them. This
     * is a single rule for every check rather than a per-check decision.
     */
    public function handle(
        CapabilityRegistry $capabilities,
        Container $container,
        ReleasePolicyRegistry $releasePolicies,
        CapabilityDiscovery $discovery,
    ): int {
        $errors = [];
        $warnings = [];
        $information = [];
        $unpausable = [];
        $needsApprovals = false;
        $needsRateLimits = false;
        $needsExecutionClaims = false;
        $needsIntents = false;
        $intents = $container->make(ActionIntentManager::class);

        foreach ($capabilities->all() as $capability) {
            if (! $capability->isExecutable()) {
                $warnings[] = "Capability [{$capability->name}] has no executor; this is valid only for GuardedTool-style use.";
            }

            $needsApprovals = $needsApprovals || $capability->confirmationRequired();
            $needsRateLimits = $needsRateLimits || $capability->rateLimitPolicy() !== null;
            $needsExecutionClaims = $needsExecutionClaims || $capability->executionClaimPolicy() !== null;
            $needsIntents = $needsIntents || $intents->required($capability);

            // Advisory (#230): requestConfirmation() returns null without an execution-target policy, so
            // this capability asks for confirmation and never pauses — no human is ever shown the
            // proposal. It still fails closed, because execution denies without a receipt, which is why
            // this warns rather than errors. The guards mirror requestConfirmation()'s own, so this warns
            // exactly when that method would decline to issue — a broader guard would fire this detail
            // text at capabilities where the mechanism differs, and a warning whose remedy is wrong for
            // some recipients teaches people to skim warnings.
            //
            // The mirror is an invariant, not a coincidence: StreamedApprovalResumptionTest (#229) pins
            // the product side of it by asserting shouldRequestApproval() returns null for exactly this
            // combination. If requestConfirmation()'s guard ever changes, that test fails first and points
            // whoever changed it at this check.
            if ($capability->confirmationRequired()
                && $capability->isExecutable()
                && $capability->executionTargetPolicy() === null) {
                $unpausable[] = [
                    'capability' => $capability->name,
                    'detail' => 'Add ->executionTarget(...): without one Verdict never requests approval, so the '
                        .'action is denied at execution and no human is ever asked.',
                ];
            }

            if ($capability->executionTargetPolicy()?->strategy === ExecutionTargetStrategy::AcceptStaleSnapshot) {
                $information[] = "Capability [{$capability->name}] deliberately accepts a stale execution-target snapshot.";
            }
        }

        foreach ([
            [
                'needed' => $needsApprovals,
                'contract' => ApprovalReceiptStore::class,
                'label' => 'approval receipt',
            ],
            [
                'needed' => $needsRateLimits,
                'contract' => RateLimitStore::class,
                'label' => 'rate-limit',
            ],
            [
                'needed' => $needsExecutionClaims,
                'contract' => ExecutionClaimStore::class,
                'label' => 'execution-claim',
            ],
            [
                // With the intent lever effective for any capability, a missing intent table
                // denies every guarded action at gate 9.5 — fail-closed, but for a reason this
                // audit can name before the first denial pages anyone (#160).
                'needed' => $needsIntents,
                'contract' => ActionIntentStore::class,
                'label' => 'action-intent',
            ],
            [
                // Registration records a configuration fingerprint for every registered capability,
                // and a missing table is a silent permanent skip rather than a boot failure (#240) —
                // this audit is the loud signal that replaces the crash.
                'needed' => $capabilities->all() !== [],
                'contract' => CapabilityConfigurationStore::class,
                'label' => 'capability-configuration',
            ],
        ] as $store) {
            if (! $store['needed']) {
                continue;
            }

            $this->auditStore(
                errors: $errors,
                warnings: $warnings,
                container: $container,
                contract: $store['contract'],
                label: $store['label'],
            );
        }

        // Advisory: a class sitting in a discovery path that never affirmed the contract is inert,
        // which is safe, and invisible, which is not legible. Verdict cannot tell "unfinished" from
        // "finished and never affirmed" — it cannot see inside the closures (ADR 0017) — so it names
        // the class and lets a human tell the difference. Prints on every run; --strict changes only
        // the exit code.
        //
        // Rendered as a short component line plus a detail line, because components truncate to the
        // terminal width and the reason is the half an operator needs.
        $unaffirmed = [];

        foreach ($discovery->discover()->unaffirmed as $definition) {
            $unaffirmed[] = [
                'class' => $definition->class,
                'detail' => match ($definition->reason) {
                    UnaffirmedDefinition::NO_CONTRACT => 'It does not implement DefinesCapability: unfinished, or finished but never affirmed. '
                        .'Verdict will not register it. Add the contract once every TODO in it is replaced.',
                    UnaffirmedDefinition::NOT_INSTANTIABLE => 'It is abstract or otherwise not instantiable, so it can never be registered. '
                        .'A base class or interface does not belong in a discovery path.',
                    UnaffirmedDefinition::NO_CLASS => 'It does not declare the class its path implies, so it cannot be autoloaded. '
                        .'Check the namespace against the file location.',
                    default => 'It cannot be registered.',
                },
            ];
        }

        // Advisory: a confirmation gate with no approver release policy asks a human to authorize an
        // action while telling them nothing about where the proposal came from. It is legal — Verdict
        // ships no default policy, because a default would be Verdict authorizing a release on the
        // application's behalf (ADR 0026 §1) — and it must stay legal at runtime, so the gap surfaces
        // here at the wiring audit rather than as a failure at challenge creation.
        if ($needsApprovals && ! $releasePolicies->hasRoute(ApproverAudience::source(), ApproverAudience::destination())) {
            $warnings[] = 'Capabilities require confirmation but no context release policy is registered for the approver route '
                .'('.ApproverAudience::source()->identity().' -> '.ApproverAudience::destination()->identity().'); '
                .'approvers are shown no provenance for the proposals they authorize. '
                .'Register a ReleasePolicy for that route to disclose declared upstream sources.';
        }

        // Errors, not advisories: a configured-but-invalid authorizer will throw on every
        // decision, and nothing before that moment would have said so — the manager resolves it
        // lazily precisely so issue()/consume() paths survive, which makes this audit the only
        // pre-decision surface that can catch the typo.
        $configuredAuthorizer = config('verdict.approvals.authorizer');
        if (is_string($configuredAuthorizer) && $configuredAuthorizer !== '') {
            if (! class_exists($configuredAuthorizer)) {
                $errors[] = "The approval decision authorizer [{$configuredAuthorizer}] configured in verdict.approvals.authorizer does not exist.";
            } elseif (! is_a($configuredAuthorizer, ApprovalDecisionAuthorizer::class, true)) {
                $errors[] = "The approval decision authorizer [{$configuredAuthorizer}] must implement ".ApprovalDecisionAuthorizer::class.'.';
            }
        }

        // Advisory: the shipped AllowAllApprovalAuthorizer authorizes every decision — correct in a
        // test suite, and the removal of per-receipt authorization anywhere else. Gated on the
        // framework's own local and testing determination, like the non-durable adapter advisories.
        if ($needsApprovals
            && $configuredAuthorizer === AllowAllApprovalAuthorizer::class
            && ! $this->laravel->environment(['local', 'testing'])) {
            $warnings[] = 'verdict.approvals.authorizer is the test-only AllowAllApprovalAuthorizer, which authorizes every '
                .'decision; outside local/testing this removes per-receipt authorization entirely. Configure the '
                ."application's own ApprovalDecisionAuthorizer.";
        }

        // Advisory here, but an error at decision time: approve()/reject() are fail-closed and
        // refuse without a configured authorizer (#305). Surfacing it at the wiring audit makes the
        // refusal a deploy-time discovery instead of a surprise in the approval controller.
        if ($needsApprovals && (! is_string($configuredAuthorizer) || $configuredAuthorizer === '')) {
            $warnings[] = 'Capabilities require confirmation but no approval decision authorizer is configured; '
                .'approve() and reject() will refuse every decision (fail-closed). Set verdict.approvals.authorizer '
                .'to a class implementing ApprovalDecisionAuthorizer that verifies the receipt belongs to a '
                .'conversation the decision maker may decide; verdict:make-approval-flow publishes a working example.';
        }

        // Advisory here, LogicException at call time: a custom receipt store without a paired
        // status reader serves the two status reads but refuses pendingWithin() (ADR 0031 §2).
        // Legal — enumeration is optional and the join path stands — so it warns, naming the
        // pairing at deploy time instead of the first reviewer-queue request.
        if ($needsApprovals && $this->pairedApprovalStatusReader() instanceof StoreBackedApprovalStatusReader) {
            $store = config('verdict.approvals.store');
            $warnings[] = 'The configured approval receipt store ['.(is_string($store) ? $store : 'unknown')
                .'] has no paired status reader: per-receipt status reads work, but pendingWithin() will refuse '
                .'enumeration with a LogicException. Implement ApprovalStatusReader for this store (or bind one in '
                .'the container) if a reviewer queue will enumerate pending approvals; the application-owned '
                .'tool_call_id join remains the alternative.';
        }

        // Advisory: the shipped default records nothing. It is legal (correct for tests and for
        // applications routing evidence through a custom writer), so this warns rather than errors.
        // The runtime once-per-process warning (ConsequentialActionUnrecorded) is the louder,
        // action-scoped signal; this is the deploy-time one. See #194.
        $recorder = config('verdict.evidence.recorder', NullEvidenceRecorder::class);
        $effectiveEvidenceClass = EffectiveEvidenceClass::resolve();
        if ($effectiveEvidenceClass === NullEvidenceRecorder::class) {
            $warnings[] = 'Evidence is going to a no-op evidence recorder (NullEvidenceRecorder), the shipped default; '
                .'consequential decisions — confirmations and at-most-once claims — are recorded nowhere. '
                .'Configure a durable recorder via verdict.evidence.recorder to retain an audit trail.';
        }

        // `writer` and `ledger` are independent narrow roles. Each falls back to the
        // legacy recorder only when it is unset, so audit the tables only when an
        // effective role opens them. This remains a declared-config audit: do not
        // resolve either binding here.
        $writer = config('verdict.evidence.writer');
        $ledger = config('verdict.evidence.ledger');
        $effectiveRoles = [$writer ?? $recorder, $ledger ?? $recorder];

        if (in_array(DatabaseEvidenceRecorder::class, $effectiveRoles, true)) {
            $this->auditEvidenceRecorder($errors, $container);
        }

        if ($recorder === AttestEvidenceRecorder::class && ($writer === null || $ledger === null)) {
            $this->auditEvidenceRecorder(
                errors: $errors,
                container: $container,
                connectionKey: 'verdict.evidence.attest.fallback_connection',
                tableKey: 'verdict.evidence.attest.fallback_table',
            );
        }

        $this->auditSessionTimeZones($errors, $container);

        // Advisory: the silent-mismatch case of #310. With the store key unset, Verdict selects the
        // capability-configuration store by the recorder's declared capability (the
        // DurableEvidenceRecorder marker) — a recorder that declares nothing gets the no-op store,
        // and configuration fingerprints on whatever evidence it retains become permanently
        // unexpandable. Legal (the recorder may genuinely retain nothing), so this warns rather
        // than errors, and it mirrors the provider's fall-through by reading configuration, not
        // resolved bindings, like every check in this command. Scoped to the unset store key on
        // purpose: an explicitly configured no-op store is a declared choice, not this silent
        // fall-through, and the remedy below would be nonsensical advice for it.
        if ($effectiveEvidenceClass !== NullEvidenceRecorder::class
            && config('verdict.capability_configurations.store') === null
            && CapabilityConfigurationStoreSelection::forRecorder($effectiveEvidenceClass) === NullCapabilityConfigurationStore::class) {
            $warnings[] = 'Evidence is being recorded, but capability configuration fingerprints are going to the '
                .'no-op configuration store: fingerprints on retained evidence will be permanently unexpandable. '
                .'If the recorder retains evidence, implement the DurableEvidenceRecorder contract on it '
                .'or set verdict.capability_configurations.store explicitly.';
        }

        // Advisory: config/verdict.php states in comments that the in-memory adapters are unsafe
        // outside local development, and until #146 nothing checked — a comment in a published file
        // is read once, at vendor:publish, and never again. Gated on the framework's own local and
        // testing determination rather than on a list of production-looking environment names, so an
        // environment called anything else is covered by default. Advisory rather than fatal because
        // Verdict does not decide deployment topology: an ephemeral preview environment or a smoke
        // test may legitimately run one, and --strict is the opt-in for CI that wants to block.
        //
        // Rendered as a short component line plus a detail line, for the same reason the unaffirmed
        // findings above are: components truncate to the terminal width, and the config key an
        // operator has to change would be the half that gets cut.
        //
        // This compares configuration, not resolved container bindings, and that is the scope rather
        // than an oversight: a read-only wiring audit reads what the deployment declared. An
        // application that leaves config durable and rebinds the contract to a non-durable
        // implementation in a service provider is invisible here, in both directions, and so is a
        // custom store of the application's own that happens not to be durable. Verdict cannot judge
        // the durability of an implementation it did not write.
        $nonDurable = [];

        if (! $this->laravel->environment(['local', 'testing'])) {
            foreach ($this->nonDurableAdapters() as $adapter) {
                if (config($adapter['key']) !== $adapter['class']) {
                    continue;
                }

                $nonDurable[] = [
                    'class' => class_basename($adapter['class']),
                    'detail' => $adapter['detail']." Set {$adapter['key']} to a durable implementation.",
                ];
            }
        }

        foreach ($errors as $error) {
            $this->components->error($error);
        }

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        foreach ($unaffirmed as $finding) {
            $this->components->warn("[{$finding['class']}] is an unaffirmed capability definition.");
            $this->line("   {$finding['detail']}");
        }

        foreach ($unpausable as $finding) {
            $this->components->warn("Capability [{$finding['capability']}] can never pause.");
            $this->line("   {$finding['detail']}");
        }

        foreach ($nonDurable as $finding) {
            $this->components->warn("[{$finding['class']}] is a non-durable adapter configured outside local development.");
            $this->line("   {$finding['detail']}");
        }

        foreach ($information as $item) {
            $this->components->info($item);
        }

        if ($errors === [] && $warnings === [] && $unaffirmed === [] && $unpausable === [] && $nonDurable === [] && $information === []) {
            $this->components->info('Verdict wiring audit found no applicable capability configuration.');
        }

        $failed = $errors !== []
            || ((bool) $this->option('strict') && ($warnings !== [] || $unaffirmed !== [] || $unpausable !== [] || $nonDurable !== []));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The adapters whose own config comments call them unsafe outside local development, each with the
     * consequence that is specific to it. One generic sentence repeated five times would tell an operator
     * which key to change and nothing about why it matters, and the remedies differ in urgency: a
     * process-local rate limit multiplies a security bound by the worker count, while a process-local
     * configuration registry only makes retained evidence unreadable later.
     *
     * @return list<array{key: string, class: class-string, detail: string}>
     */
    private function nonDurableAdapters(): array
    {
        return [
            [
                'key' => 'verdict.evidence.recorder',
                'class' => InMemoryEvidenceRecorder::class,
                'detail' => 'Its state is process-local and unbounded: no record survives the process that wrote it, '
                    .'and a long-lived one (Octane, a queue worker) grows until it restarts.',
            ],
            [
                'key' => 'verdict.approvals.store',
                'class' => InMemoryApprovalReceiptStore::class,
                'detail' => 'A receipt issued in one process is invisible to every other, so a human approval cannot '
                    .'be consumed by the process that executes the action.',
            ],
            [
                'key' => 'verdict.rate_limits.store',
                'class' => InMemoryRateLimitStore::class,
                'detail' => 'Counters do not coordinate across requests, workers, or nodes, so the configured limit '
                    .'binds per process: N workers admit up to N times the limit you configured.',
            ],
            [
                'key' => 'verdict.execution_claims.store',
                'class' => InMemoryExecutionClaimStore::class,
                'detail' => 'Claims are process-local, so at-most-once degrades to at-most-once-per-process and '
                    .'cannot prevent duplicate execution across workers or nodes.',
            ],
            [
                'key' => 'verdict.intents.store',
                'class' => InMemoryActionIntentStore::class,
                'detail' => 'Intent rows are the durable proof the fail-closed lever exists to give a compliance '
                    .'deployment; a process-local row dies with the process that wrote it, so the guarantee is not made.',
            ],
            [
                'key' => 'verdict.capability_configurations.store',
                'class' => InMemoryCapabilityConfigurationStore::class,
                'detail' => 'Configuration fingerprints recorded in evidence cannot be expanded back into the '
                    .'configuration that produced them once the process ends, leaving retained evidence unreadable.',
            ],
        ];
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function auditStore(
        array &$errors,
        array &$warnings,
        Container $container,
        string $contract,
        string $label,
    ): void {
        try {
            $store = $container->make($contract);
        } catch (Throwable) {
            $errors[] = "Configured {$label} store could not be resolved.";

            return;
        }

        if (! is_a($store, $contract) || ! $store instanceof DatabaseTableStore) {
            return;
        }

        try {
            if (! $store->hasTable()) {
                $table = $store->table();
                $errors[] = "Configured {$label} store requires missing table [{$table}]. Publish and run Verdict's migrations.";
            } elseif ($store instanceof DatabaseApprovalReceiptStore && ! $store->hasApprovalContextColumn()) {
                // Advisory: the store degrades deliberately — writes omit the column and receipts
                // hydrate as never-captured, which a fail-closed authorizer refuses — so nothing
                // fails at runtime, but every decision on a new receipt will be refused for a
                // reason this audit can name and the approval controller cannot.
                $warnings[] = "The [{$store->table()}] table predates the approval_context column, so new receipts record no binding "
                    .'context and a fail-closed authorizer will refuse them. Publish and run the '
                    .'add_approval_context_to_verdict_approval_receipts_table migration.';
            }
        } catch (Throwable) {
            $errors[] = "Configured {$label} store could not inspect its table.";
        }
    }

    /** @param list<string> $errors */
    private function auditEvidenceRecorder(
        array &$errors,
        Container $container,
        string $connectionKey = 'verdict.evidence.connection',
        string $tableKey = 'verdict.evidence.table',
    ): void {
        try {
            // Deliberately reconstruct the configured recorder rather than resolving the
            // EvidenceRecorder binding: this command audits declared deployment wiring, and a
            // previously-resolved singleton can describe an earlier configuration.
            $connection = config($connectionKey);
            $table = config($tableKey, 'verdict_evidence');
            $derivations = config('verdict.evidence.derivations_table', 'verdict_provenance_derivations');

            $recorder = new DatabaseEvidenceRecorder(
                connection: $container->make(DatabaseManager::class)->connection(is_string($connection) ? $connection : null),
                table: is_string($table) ? $table : 'verdict_evidence',
                derivationsTable: is_string($derivations) ? $derivations : 'verdict_provenance_derivations',
            );
        } catch (Throwable) {
            $errors[] = 'Configured database evidence recorder could not be constructed.';

            return;
        }

        try {
            if (! $recorder->hasTable()) {
                $configuration = $tableKey === 'verdict.evidence.attest.fallback_table'
                    ? ' (configured by verdict.evidence.attest.fallback_table)'
                    : '';
                $errors[] = "Configured evidence recorder requires missing table [{$recorder->table()}]{$configuration}. Publish and run Verdict's migrations.";
            } else {
                $missing = $recorder->missingColumns();

                if ($missing !== []) {
                    $errors[] = "The [{$recorder->table()}] evidence table is missing columns: ".implode(', ', $missing)
                        .". Publish and run Verdict's evidence migrations.";
                }
            }

            // The derivations table on the same terms (#363). The recorder writes it on every
            // provenance edge, and until now nothing audited it — the asymmetry #356 opened with,
            // one table over. It reports nothing today because that table has no additive
            // migrations; the point is that the first one cannot land unnoticed.
            if (! $recorder->hasDerivationsTable()) {
                $errors[] = "Configured evidence recorder requires missing table [{$recorder->derivationsTable()}]. Publish and run Verdict's migrations.";

                return;
            }

            $missingDerivations = $recorder->missingDerivationsColumns();

            if ($missingDerivations !== []) {
                $errors[] = "The [{$recorder->derivationsTable()}] derivations table is missing columns: "
                    .implode(', ', $missingDerivations).". Publish and run Verdict's evidence migrations.";
            }
        } catch (Throwable) {
            $errors[] = 'Configured evidence recorder could not inspect its table.';
        }
    }

    /** @param list<string> $errors */
    private function auditSessionTimeZones(array &$errors, Container $container): void
    {
        foreach (SessionTimezoneAudit::auditable($container) as $name => $connection) {
            try {
                $driver = $connection->getDriverName();

                if (! in_array($driver, ['mysql', 'mariadb'], true)) {
                    continue;
                }

                $row = $connection->selectOne('select @@session.time_zone as tz');
                $timeZone = isset($row->tz) ? (string) $row->tz : null;

                if (SessionTimezoneAudit::rejects($driver, $timeZone)) {
                    $errors[] = "Database connection [{$name}] has session time zone [{$timeZone}], not the required UTC session time zone.";
                }
            } catch (Throwable) {
                // A failed connection inspection is already covered by the relevant store
                // audit. Continue so an independent connection can still be diagnosed.
            }
        }
    }

    /**
     * Resolved through a declared-interface seam so the audit reasons about the pairing rule,
     * not about whichever concrete reader this process happens to have bound.
     */
    private function pairedApprovalStatusReader(): ApprovalStatusReader
    {
        return $this->laravel->make(ApprovalStatusReader::class);
    }
}
