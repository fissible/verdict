<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Approvals\ApproverAudience;
use Fissible\Verdict\Approvals\DatabaseApprovalReceiptStore;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityDiscovery;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Capabilities\DatabaseCapabilityConfigurationStore;
use Fissible\Verdict\Capabilities\InMemoryCapabilityConfigurationStore;
use Fissible\Verdict\Capabilities\NullCapabilityConfigurationStore;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Fissible\Verdict\Contracts\CapabilityConfigurationStore;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\Verdict\ExecutionClaims\DatabaseExecutionClaimStore;
use Fissible\Verdict\ExecutionClaims\InMemoryExecutionClaimStore;
use Fissible\Verdict\Facades\Verdict;
use Fissible\Verdict\Intents\DatabaseActionIntentStore;
use Fissible\Verdict\Intents\InMemoryActionIntentStore;
use Fissible\Verdict\RateLimits\DatabaseRateLimitStore;
use Fissible\Verdict\RateLimits\InMemoryRateLimitStore;
use Fissible\Verdict\RateLimits\RateLimitConsumption;
use Fissible\Verdict\RateLimits\RateLimitOutcome;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\Tests\Support\CustomStatusReaderTestStore;
use Fissible\Verdict\Tests\Support\DurableCustomEvidenceRecorder;
use Fissible\Verdict\Tests\Support\EvidenceTableSchema;
use Fissible\Verdict\Tests\Support\VolatileCustomEvidenceRecorder;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;

it('reports static wiring warnings without failing CI', function (): void {
    $targetResolutions = 0;

    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.legacy', 'view', function (ActionEnvelope $envelope) use (&$targetResolutions): int {
            $targetResolutions++;

            return 1;
        })
            ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
                name: 'legacy-snapshot',
                identityUsing: fn (ActionEnvelope $envelope, int $target): array => ['id' => $target],
            )),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('Capability [orders.legacy] has no executor')
        ->expectsOutputToContain('Capability [orders.legacy] deliberately accepts a stale')
        ->assertExitCode(0);

    expect($targetResolutions)->toBe(0);
});

it('fails CI when a configured database backing table is missing', function (): void {
    config()->set('verdict.rate_limits.store', DatabaseRateLimitStore::class);
    config()->set('verdict.rate_limits.table', 'missing_verdict_rate_limit_buckets');

    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.limited', 'view', fn (ActionEnvelope $envelope): int => 1)
            ->rateLimit(RateLimitPolicy::fixedWindow('orders-per-minute', 1, 60, fn (ActionEnvelope $envelope, int $target): array => ['actor' => 1])),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('Configured rate-limit store requires missing table [missing_verdict_rate_limit_buckets]')
        ->assertExitCode(1);
});

it('uses the runtime database store default when the store key is omitted', function (): void {
    config()->set('verdict.rate_limits', []);

    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.default-limited', 'view', fn (ActionEnvelope $envelope): int => 1)
            ->rateLimit(RateLimitPolicy::fixedWindow('orders-per-minute', 1, 60, fn (ActionEnvelope $envelope, int $target): array => ['actor' => 1])),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('Configured rate-limit store requires missing table [verdict_rate_limit_buckets]')
        ->assertExitCode(1);
});

it('fails CI when a configured custom store cannot be resolved', function (): void {
    config()->set('verdict.rate_limits.store', UnresolvableRateLimitStore::class);

    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.custom-limited', 'view', fn (ActionEnvelope $envelope): int => 1)
            ->rateLimit(RateLimitPolicy::fixedWindow('orders-per-minute', 1, 60, fn (ActionEnvelope $envelope, int $target): array => ['actor' => 1])),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('Configured rate-limit store could not be resolved.')
        ->assertExitCode(1);
});

interface UnresolvableRateLimitStoreDependency {}

final class UnresolvableRateLimitStore implements RateLimitStore
{
    public function __construct(UnresolvableRateLimitStoreDependency $dependency) {}

    public function consume(RateLimitConsumption $consumption): RateLimitOutcome
    {
        throw new RuntimeException('This store should never be consumed by the audit.');
    }
}

it('warns without failing when the shipped no-op evidence recorder is configured', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('no-op evidence recorder')
        ->assertExitCode(0);
});

it('fails under --strict when only advisory warnings are present', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);

    $this->artisan('verdict:validate', ['--strict' => true])
        ->expectsOutputToContain('no-op evidence recorder')
        ->assertExitCode(1);
});

it('warns when a confirmation-gated deployment pairs a custom store with no status reader', function (): void {
    config()->set('verdict.approvals.store', CustomStatusReaderTestStore::class);
    app()->forgetInstance(ApprovalStatusReader::class);
    app()->forgetInstance(ApprovalReceiptStore::class);
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.refund', 'update', fn (ActionEnvelope $envelope): int => 1)
            ->requiresConfirmation(fn (ActionEnvelope $envelope, int $target): array => ['order_id' => $target]),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('has no paired status reader')
        ->assertExitCode(0);
});

it('does not warn about status-reader pairing for a shipped store', function (): void {
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.refund', 'update', fn (ActionEnvelope $envelope): int => 1)
            ->requiresConfirmation(fn (ActionEnvelope $envelope, int $target): array => ['order_id' => $target]),
    );

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('has no paired status reader')
        ->assertExitCode(0);
});

it('warns when a confirmation-gated capability has no approver release policy registered', function (): void {
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.refund', 'update', fn (ActionEnvelope $envelope): int => 1)
            ->requiresConfirmation(fn (ActionEnvelope $envelope, int $target): array => ['order_id' => $target]),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('no context release policy is registered for the approver route')
        ->assertExitCode(0);
});

it('does not warn about the approver route once a policy is registered for it', function (): void {
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.refund', 'update', fn (ActionEnvelope $envelope): int => 1)
            ->requiresConfirmation(fn (ActionEnvelope $envelope, int $target): array => ['order_id' => $target]),
    );
    Verdict::releasePolicy(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(DataClass::Internal)
            ->whenTrustIs(Trust::Untrusted),
    );

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('approver route')
        ->assertExitCode(0);
});

it('does not warn about the approver route when no capability requires confirmation', function (): void {
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.view', 'view', fn (ActionEnvelope $envelope): int => 1),
    );

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('approver route')
        ->assertExitCode(0);
});

it('warns when a confirmation-gated capability has no approval decision authorizer configured', function (): void {
    config()->set('verdict.approvals.authorizer', null);
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.refund', 'update', fn (ActionEnvelope $envelope): int => 1)
            ->requiresConfirmation(fn (ActionEnvelope $envelope, int $target): array => ['order_id' => $target]),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('no approval decision authorizer is configured')
        ->assertExitCode(0);
});

it('does not warn about the approval authorizer once one is configured', function (): void {
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.refund', 'update', fn (ActionEnvelope $envelope): int => 1)
            ->requiresConfirmation(fn (ActionEnvelope $envelope, int $target): array => ['order_id' => $target]),
    );

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('approval decision authorizer')
        ->assertExitCode(0);
});

it('warns when the approval receipts table predates the approval_context column', function (): void {
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.refund', 'update', fn (ActionEnvelope $envelope): int => 1)
            ->requiresConfirmation(fn (ActionEnvelope $envelope, int $target): array => ['order_id' => $target]),
    );
    $schema = app(DatabaseManager::class)->connection()->getSchemaBuilder();
    $schema->dropIfExists(verdictTable('approvals'));
    $schema->create(verdictTable('approvals'), function (Blueprint $table): void {
        $table->string('id', 64)->primary();
        $table->string('tool_call_id');
        $table->text('provenance')->nullable();
    });

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('approval_context')
        ->assertExitCode(0);

    $schema->dropIfExists(verdictTable('approvals'));
});

it('warns when the test-only allow-all authorizer is configured outside local and testing', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.refund', 'update', fn (ActionEnvelope $envelope): int => 1)
            ->requiresConfirmation(fn (ActionEnvelope $envelope, int $target): array => ['order_id' => $target]),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('AllowAllApprovalAuthorizer')
        ->assertExitCode(0);
});

it('does not warn about the allow-all authorizer in the testing environment', function (): void {
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.refund', 'update', fn (ActionEnvelope $envelope): int => 1)
            ->requiresConfirmation(fn (ActionEnvelope $envelope, int $target): array => ['order_id' => $target]),
    );

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('AllowAllApprovalAuthorizer')
        ->assertExitCode(0);
});

it('errors when the configured approval authorizer class does not exist', function (): void {
    config()->set('verdict.approvals.authorizer', 'App\\Nope\\MissingAuthorizer');

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('does not exist')
        ->assertExitCode(1);
});

it('errors when the configured approval authorizer does not implement the contract', function (): void {
    config()->set('verdict.approvals.authorizer', stdClass::class);

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('must implement')
        ->assertExitCode(1);
});

it('does not warn about the approval authorizer when no capability requires confirmation', function (): void {
    config()->set('verdict.approvals.authorizer', null);
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.view', 'view', fn (ActionEnvelope $envelope): int => 1),
    );

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('approval decision authorizer')
        ->assertExitCode(0);
});

it('does not warn about the recorder when a real one is configured', function (): void {
    config()->set('verdict.evidence.recorder', InMemoryEvidenceRecorder::class);

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('no-op evidence recorder')
        ->assertExitCode(0);
});

function bindUnaffirmedDiscovery(string $directory): void
{
    app()->instance(CapabilityDiscovery::class, new CapabilityDiscovery(
        rootPath: dirname(__DIR__).'/Fixtures',
        rootNamespace: 'Fissible\\Verdict\\Tests\\Fixtures\\',
        paths: [dirname(__DIR__).'/Fixtures/'.$directory],
    ));
}

/**
 * Inert is safe; silent is not legible. A generated capability sitting in the discovery path,
 * finished or unfinished but never affirmed, is the one state discovery leaves invisible — so it
 * prints on every run, the way the no-op recorder warning does.
 */
it('names a capability class that never affirmed the contract, without being asked twice', function (): void {
    bindUnaffirmedDiscovery('LegacyCapabilities');

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('LegacyOrderCapability')
        ->expectsOutputToContain('never affirmed')
        ->assertExitCode(0);
});

it('lets --strict fail on an unaffirmed class without changing what is printed', function (): void {
    bindUnaffirmedDiscovery('LegacyCapabilities');

    $this->artisan('verdict:validate', ['--strict' => true])
        ->expectsOutputToContain('LegacyOrderCapability')
        ->assertExitCode(1);
});

it('names an abstract class in a discovery path as never registerable', function (): void {
    bindUnaffirmedDiscovery('Capabilities');

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('AbstractAffirmedCapability')
        ->expectsOutputToContain('abstract')
        ->assertExitCode(0);
});

/**
 * #146. `config/verdict.php` warns in comments that the in-memory adapters are unsafe outside local
 * development, and nothing checked. These are advisory: an ephemeral preview environment or a smoke test
 * may legitimately run one, so the exit code must not move. `--strict` is the opt-in for CI that wants to
 * block.
 */
it('warns without failing when a non-durable rate-limit store is configured outside local', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config()->set('verdict.rate_limits.store', InMemoryRateLimitStore::class);

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('InMemoryRateLimitStore')
        ->expectsOutputToContain('verdict.rate_limits.store')
        ->assertExitCode(0);
});

it('warns for every non-durable adapter, naming the config key that selects each one', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config()->set('verdict.evidence.recorder', InMemoryEvidenceRecorder::class);
    config()->set('verdict.approvals.store', InMemoryApprovalReceiptStore::class);
    config()->set('verdict.rate_limits.store', InMemoryRateLimitStore::class);
    config()->set('verdict.execution_claims.store', InMemoryExecutionClaimStore::class);
    config()->set('verdict.capability_configurations.store', InMemoryCapabilityConfigurationStore::class);

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('verdict.evidence.recorder')
        ->expectsOutputToContain('verdict.approvals.store')
        ->expectsOutputToContain('verdict.rate_limits.store')
        ->expectsOutputToContain('verdict.execution_claims.store')
        ->expectsOutputToContain('verdict.capability_configurations.store')
        ->assertExitCode(0);
});

/**
 * The hazard is per-adapter and the wording has to say which one, because the remedy differs. A
 * process-local rate limit multiplies the configured bound by the worker count; a process-local claim
 * store degrades at-most-once to at-most-once-per-process. An operator who reads one generic sentence
 * five times learns neither.
 */
it('states the specific failure mode of the adapter it names', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config()->set('verdict.execution_claims.store', InMemoryExecutionClaimStore::class);

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('duplicate execution')
        ->assertExitCode(0);
});

it('does not warn about non-durable adapters in the local environment', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');
    config()->set('verdict.rate_limits.store', InMemoryRateLimitStore::class);

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('InMemoryRateLimitStore')
        ->assertExitCode(0);
});

it('does not warn about non-durable adapters in the testing environment', function (): void {
    config()->set('verdict.rate_limits.store', InMemoryRateLimitStore::class);

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('InMemoryRateLimitStore')
        ->assertExitCode(0);
});

/**
 * Every adapter must be overridden here, not just the interesting one: TestCase::defineEnvironment binds
 * all six to their in-memory implementations, so a partial override leaves the others warning and the
 * assertion passes or fails for the wrong reason.
 */
it('does not warn about durable adapters configured in production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    config()->set('verdict.rate_limits.store', DatabaseRateLimitStore::class);
    config()->set('verdict.execution_claims.store', DatabaseExecutionClaimStore::class);
    config()->set('verdict.capability_configurations.store', DatabaseCapabilityConfigurationStore::class);
    config()->set('verdict.intents.store', DatabaseActionIntentStore::class);

    // A declared database evidence recorder needs its tables to be a valid deployment (#356, and
    // the derivations table since #363); the audit errors without them. That is unrelated to what
    // this test asserts, so the fixture provides them rather than passing by dodging the check.
    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('non-durable')
        ->assertExitCode(0);

    EvidenceTableSchema::drop();
});

it('lets --strict fail on a non-durable adapter without changing what is printed', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config()->set('verdict.rate_limits.store', InMemoryRateLimitStore::class);

    $this->artisan('verdict:validate', ['--strict' => true])
        ->expectsOutputToContain('InMemoryRateLimitStore')
        ->assertExitCode(1);
});

/**
 * The stated scope, pinned. `docs/architecture.md` and the command's own comment tell an operator that a
 * clean run means "nothing declared in configuration is non-durable", not "every store this application
 * resolves is durable" — and an under-claim nothing guards can quietly become false. If someone improves
 * this check to resolve container bindings, that is a real improvement and this test is where they learn
 * both statements of the limitation need deleting.
 */
it('reads configuration rather than resolved container bindings, and says so', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    config()->set('verdict.approvals.store', DatabaseApprovalReceiptStore::class);
    config()->set('verdict.rate_limits.store', DatabaseRateLimitStore::class);
    config()->set('verdict.execution_claims.store', DatabaseExecutionClaimStore::class);
    config()->set('verdict.capability_configurations.store', DatabaseCapabilityConfigurationStore::class);

    config()->set('verdict.intents.store', DatabaseActionIntentStore::class);

    // Declared durable, resolved non-durable. The audit reads the declaration.
    $this->app->instance(RateLimitStore::class, new InMemoryRateLimitStore);

    // See #356 above: the declared evidence recorder needs its tables for this to be a valid
    // deployment, which is not what this test is about.
    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('non-durable')
        ->assertExitCode(0);
});

/**
 * #230. A capability that asks for confirmation and declares no execution-target policy never pauses:
 * `VerdictManager::requestConfirmation()` returns null without one, so no approval is requested and no
 * human is ever asked. It still fails closed — the action is denied — so this is advisory, but it is the
 * trap that cost a wrong defect issue and a reverted docs change before it was found.
 */
it('warns when a confirmation-gated capability has no execution-target policy', function (): void {
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.ungated-confirm', 'update', fn (ActionEnvelope $envelope): int => 1)
            ->executeUsing(fn (AuthorizedAction $action): string => 'done')
            ->requiresConfirmation(fn (ActionEnvelope $envelope, int $target): array => ['order_id' => $target]),
    );

    $this->artisan('verdict:validate')
        // One expectation per rendered line: the component line carries the capability, the detail line
        // carries the remedy. Two phrases from the same line cannot both match.
        ->expectsOutputToContain('orders.ungated-confirm')
        ->expectsOutputToContain('executionTarget')
        ->assertExitCode(0);
});

it('does not warn when a confirmation-gated capability declares an execution-target policy', function (): void {
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.gated-confirm', 'update', fn (ActionEnvelope $envelope): int => 1)
            ->executeUsing(fn (AuthorizedAction $action): string => 'done')
            ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
                name: 'gated-confirm-target',
                identityUsing: fn (ActionEnvelope $envelope, int $target): array => ['id' => $target],
            ))
            ->requiresConfirmation(fn (ActionEnvelope $envelope, int $target): array => ['order_id' => $target]),
    );

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('never requests approval')
        ->assertExitCode(0);
});

it('does not warn about pausing for a capability that does not require confirmation', function (): void {
    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.no-confirm', 'view', fn (ActionEnvelope $envelope): int => 1)
            ->executeUsing(fn (AuthorizedAction $action): string => 'done'),
    );

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('never requests approval')
        ->assertExitCode(0);
});

/**
 * The approver-route warning also fires for any confirmation-gated capability, so without registering a
 * release policy this would fail under --strict whether or not the new finding participates — passing for
 * the wrong reason and pinning nothing.
 */
it('lets --strict fail on a confirmation gate that can never pause', function (): void {
    Verdict::releasePolicy(
        ReleasePolicy::between(ApproverAudience::source(), ApproverAudience::destination())
            ->allow(DataClass::Internal)
            ->whenTrustIs(Trust::Untrusted),
    );

    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.ungated-strict', 'update', fn (ActionEnvelope $envelope): int => 1)
            ->executeUsing(fn (AuthorizedAction $action): string => 'done')
            ->requiresConfirmation(fn (ActionEnvelope $envelope, int $target): array => ['order_id' => $target]),
    );

    $this->artisan('verdict:validate', ['--strict' => true])
        ->expectsOutputToContain('orders.ungated-strict')
        ->assertExitCode(1);
});

it('fails CI when the capability-configuration table is missing', function (): void {
    config()->set('verdict.capability_configurations.store', DatabaseCapabilityConfigurationStore::class);

    // The provider already booted and resolved the registry against the default (Null) store; the
    // rebind below is the same test-only lifetime the discovery tests document.
    app()->forgetInstance(CapabilityConfigurationStore::class);
    app()->forgetInstance(CapabilityRegistry::class);

    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.recorded', 'view', fn (ActionEnvelope $envelope): int => 1),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('Configured capability-configuration store requires missing table [verdict_capability_configurations]')
        ->assertExitCode(1);
});

it('reports an unreachable capability-configuration database as uninspectable, not missing', function (): void {
    config()->set('database.connections.missing-sqlite-file', [
        'driver' => 'sqlite',
        'database' => sys_get_temp_dir().'/verdict-nonexistent-dir/missing.sqlite',
        'prefix' => '',
    ]);
    config()->set('verdict.capability_configurations.store', DatabaseCapabilityConfigurationStore::class);
    config()->set('verdict.capability_configurations.connection', 'missing-sqlite-file');

    app()->forgetInstance(CapabilityConfigurationStore::class);
    app()->forgetInstance(CapabilityRegistry::class);

    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.recorded', 'view', fn (ActionEnvelope $envelope): int => 1),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('Configured capability-configuration store could not inspect its table.')
        ->doesntExpectOutputToContain('requires missing table')
        ->assertExitCode(1);
});

/**
 * #310, second half: the silent-mismatch case named at deploy time. A recorder that retains
 * evidence while the no-op configuration store is selected leaves configuration fingerprints on
 * that evidence permanently unexpandable — legal, but almost certainly unintended, so advisory.
 */
it('warns when evidence is recorded but configuration fingerprints go to the no-op store', function (): void {
    config()->set('verdict.evidence.recorder', VolatileCustomEvidenceRecorder::class);
    config()->set('verdict.capability_configurations.store', null);

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('permanently unexpandable. If the recorder retains evidence, implement the DurableEvidenceRecorder contract')
        ->assertExitCode(0);
});

it('does not warn about unexpandable fingerprints when the recorder declares durability', function (): void {
    config()->set('verdict.evidence.recorder', DurableCustomEvidenceRecorder::class);
    config()->set('verdict.capability_configurations.store', null);

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('permanently unexpandable')
        ->assertExitCode(0);
});

it('does not warn about unexpandable fingerprints under the shipped no-op recorder default', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.capability_configurations.store', null);

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('permanently unexpandable')
        ->assertExitCode(0);
});

/**
 * Review of #319: an explicitly-set no-op store is a declared choice, not the silent fall-through
 * this warning exists for — and the warning's remedy ("set the store explicitly") would be
 * nonsensical advice for a deployment that already did. All three documentation locations
 * describe only the unset case; this pins the code to them.
 */
it('does not warn about unexpandable fingerprints when the no-op store is an explicit choice', function (): void {
    config()->set('verdict.evidence.recorder', VolatileCustomEvidenceRecorder::class);
    config()->set('verdict.capability_configurations.store', NullCapabilityConfigurationStore::class);

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('permanently unexpandable')
        ->assertExitCode(0);
});

it('fails CI when a capability requires an intent record and the intent table is missing', function (): void {
    config()->set('verdict.intents.store', DatabaseActionIntentStore::class);
    config()->set('verdict.intents.table', 'missing_verdict_action_intents');

    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.recorded', 'cancel', fn (ActionEnvelope $envelope): int => 1)
            ->requiresIntentRecord(),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('Configured action-intent store requires missing table [missing_verdict_action_intents]')
        ->assertExitCode(1);
});

it('audits the intent store under the global lever even when no capability declares a posture', function (): void {
    config()->set('verdict.intents.required', true);
    config()->set('verdict.intents.store', DatabaseActionIntentStore::class);
    config()->set('verdict.intents.table', 'missing_verdict_action_intents');

    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.plain', 'view', fn (ActionEnvelope $envelope): int => 1),
    );

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('Configured action-intent store requires missing table [missing_verdict_action_intents]')
        ->assertExitCode(1);
});

it('does not audit the intent store when no capability needs an intent record', function (): void {
    config()->set('verdict.intents.store', DatabaseActionIntentStore::class);
    config()->set('verdict.intents.table', 'missing_verdict_action_intents');

    app(CapabilityRegistry::class)->register(
        Capability::usingPolicy('orders.plain', 'view', fn (ActionEnvelope $envelope): int => 1),
    );

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('Configured action-intent store')
        ->assertExitCode(0);
});

it('warns that an in-memory intent store cannot make the durability promise', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config()->set('verdict.intents.store', InMemoryActionIntentStore::class);

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('verdict.intents.store')
        ->assertExitCode(0);
});

/**
 * #356: verdict:validate audited every DatabaseTableStore and the approvals approval_context
 * column, but never looked at the evidence table — so the tool meant to catch a lagging schema
 * stayed green while the recorder wrote a truncated row on every decision.
 *
 * These are errors, not warnings, and that is a deliberate departure from the approval_context
 * precedent. A missing approval_context is self-announcing: a fail-closed authorizer refuses the
 * action, so an operator finds out. A missing evidence column is the opposite — the action
 * proceeds and the record is silently short. For a compliance record, "your evidence is
 * incomplete and nothing will tell you" is a failed audit, not an advisory.
 *
 * These assert against captured output rather than expectsOutputToContain(), which consumes one
 * expectation per output line and so cannot assert two column names printed on the same line.
 */
it('names every missing evidence column and fails', function (string $migration): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    EvidenceTableSchema::createWithoutMigration($migration);

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    // The expected set is measured off the built table, not taken from the same map that decided
    // which migration to skip — otherwise a wrong migration-to-column map would agree with itself.
    $absent = EvidenceTableSchema::absentColumns();

    expect($absent)->not->toBe([]);

    // Every absent column, not just the first: an audit that named one and stopped would leave an
    // operator migrating in circles. Datasets cover every additive migration, so a validator that
    // knows about intent_id and ignores the tool-description or provenance columns fails here.
    foreach ($absent as $column) {
        expect($output)->toContain($column);
    }

    // And only those. An audit that noticed a lag and then listed every additive column would
    // pass the loop above while telling operators that evidence they still have is missing.
    // Word-boundary matched, so naming invocation_tool_description_fingerprint does not read as
    // naming tool_description_fingerprint.
    foreach (array_diff(EvidenceTableSchema::additiveColumns(), $absent) as $column) {
        expect(preg_match('/\\b'.preg_quote($column, '/').'\\b/', $output))
            ->toBe(0, "present column [{$column}] was reported missing");
    }

    // The exit code from the same invocation that produced the output, so a stateful
    // implementation cannot print the right audit on one call and fail only on the next.
    expect($exitCode)->toBe(1);

    EvidenceTableSchema::drop();
})->with(EvidenceTableSchema::additiveMigrationNames());

it('does not report evidence columns when the table is current', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    foreach (EvidenceTableSchema::additiveColumns() as $column) {
        expect($output)->not->toContain($column);
    }

    // A current table must also leave the audit green, from the same invocation: silence is not
    // enough if something else in the evidence check is failing the command.
    expect($exitCode)->toBe(0);

    EvidenceTableSchema::drop();
});

it('errors when the evidence table is missing entirely', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.table', 'missing_verdict_evidence');
    EvidenceTableSchema::drop('missing_verdict_evidence');

    $this->artisan('verdict:validate')
        ->expectsOutputToContain('missing_verdict_evidence')
        ->assertExitCode(1);
});

it('does not audit the evidence table when evidence is not going to the database recorder', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    config()->set('verdict.evidence.table', 'missing_verdict_evidence');

    $this->artisan('verdict:validate')
        ->doesntExpectOutputToContain('missing_verdict_evidence')
        ->assertExitCode(0);
});

/**
 * #363: the evidence audit added in #356 never looked at the derivations table, so the asymmetry
 * that issue opened with survived in miniature — a table the recorder writes on every provenance
 * edge, checked by nothing. Same terms as evidence: a missing table or missing columns are errors,
 * because a short derivation row is written silently while the action proceeds.
 */
it('errors when the derivations table is missing', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::dropDerivations();

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    expect($output)->toContain(verdictTable('derivations'))
        ->and($exitCode)->toBe(1);

    EvidenceTableSchema::drop();
});

it('errors naming a missing derivations column', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivationsWithout(['recorded_at']);

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    // Named, and only it: an audit that listed columns the table still has would send an operator
    // migrating in circles, exactly as for evidence.
    expect($output)->toContain('recorded_at')
        ->and($output)->not->toContain('child_content_fingerprint')
        ->and($exitCode)->toBe(1);

    EvidenceTableSchema::dropDerivations();
    EvidenceTableSchema::drop();
});

it('does not report the derivations table when it is current', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    expect($output)->not->toContain(verdictTable('derivations'))
        ->and($exitCode)->toBe(0);

    EvidenceTableSchema::dropDerivations();
    EvidenceTableSchema::drop();
});

it('does not audit the derivations table when evidence is not going to the database recorder', function (): void {
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);
    EvidenceTableSchema::dropDerivations();

    $exitCode = Artisan::call('verdict:validate');
    $output = Artisan::output();

    expect($output)->not->toContain(verdictTable('derivations'))
        ->and($exitCode)->toBe(0);
});
