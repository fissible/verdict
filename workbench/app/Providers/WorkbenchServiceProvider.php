<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Approvals\InMemoryApprovalReceiptStore;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\InMemoryCapabilityConfigurationStore;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Destination;
use Fissible\Verdict\Context\ReleasePolicy;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Contracts\RateLimitStore;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\ExecutionClaims\InMemoryExecutionClaimStore;
use Fissible\Verdict\RateLimits\InMemoryRateLimitStore;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Workbench\App\Storefront\ActionLog;
use Workbench\App\Storefront\Catalog;
use Workbench\App\Storefront\Order;
use Workbench\App\Storefront\OrderPolicy;
use Workbench\App\Storefront\OrderSearchScope;
use Workbench\App\Storefront\OrderSearchScopePolicy;
use Workbench\App\Storefront\StorefrontLiveSampling;
use Workbench\App\Storefront\StorefrontLiveSuiteFactory;
use Workbench\App\Storefront\StorefrontOrders;
use Workbench\App\Storefront\SupportNoteChannel;

final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Catalog::class);
        // Singleton, not scoped: decoding is configuration, and both arms of every trial must run
        // under the same declaration or TrialSuiteIdentity refuses the run. Greedy is the default
        // because it is the only mode under which the control arm's 2×2 pairs are matched pairs.
        $this->app->singleton(StorefrontLiveSampling::class, function (): StorefrontLiveSampling {
            // VERDICT_SAMPLING selects the decoding mode for a control-arm run: 'sampled' produces
            // an independent-sample rate (per-arm marginals, no per-trial pairing — ADR 0023), with
            // an optional VERDICT_SAMPLING_TEMPERATURE (default 0.8); 'greedy' (or unset) is the
            // reproducible-regression default. getenv (not env()) for the same reason as the other
            // harness reads: env() returns null once config is cached.
            //
            // An unrecognized value THROWS rather than falling through to greedy. The next action is
            // a multi-trial live run against a real model; a typo (`smapled`, `true`) silently
            // running greedy would spend that model time on a mode nobody asked for, discoverable
            // only afterward in the recorded component string. This is #150's rule: reject
            // configuration that cannot do what was asked instead of doing something else quietly.
            $raw = getenv('VERDICT_SAMPLING');
            $mode = is_string($raw) ? strtolower(trim($raw)) : '';

            if ($mode === '' || $mode === 'greedy') {
                return StorefrontLiveSampling::greedy();
            }

            if ($mode !== 'sampled') {
                throw new LogicException("VERDICT_SAMPLING must be 'sampled', 'greedy', or unset; got [{$raw}].");
            }

            $temperature = getenv('VERDICT_SAMPLING_TEMPERATURE');

            if ($temperature === false || $temperature === '') {
                return StorefrontLiveSampling::sampled();
            }

            if (! is_numeric($temperature)) {
                throw new LogicException("VERDICT_SAMPLING_TEMPERATURE must be numeric; got [{$temperature}].");
            }

            return StorefrontLiveSampling::sampled((float) $temperature);
        });
        $this->app->scoped(ActionLog::class);
        $this->app->scoped(SupportNoteChannel::class);
        $this->app->scoped(InMemoryEvidenceRecorder::class);
        $this->app->scoped(InMemoryApprovalReceiptStore::class);
        $this->app->scoped(InMemoryRateLimitStore::class);
        $this->app->scoped(InMemoryExecutionClaimStore::class);
        $this->app->scoped(
            EvidenceRecorder::class,
            fn () => $this->app->make(InMemoryEvidenceRecorder::class),
        );
        $this->app->scoped(
            ApprovalReceiptStore::class,
            fn () => $this->app->make(InMemoryApprovalReceiptStore::class),
        );
        $this->app->scoped(
            RateLimitStore::class,
            fn () => $this->app->make(InMemoryRateLimitStore::class),
        );
        $this->app->scoped(
            ExecutionClaimStore::class,
            fn () => $this->app->make(InMemoryExecutionClaimStore::class),
        );

        config()->set('verdict.evidence.recorder', InMemoryEvidenceRecorder::class);
        config()->set('verdict.capability_configurations.store', InMemoryCapabilityConfigurationStore::class);
        config()->set('verdict.approvals.store', InMemoryApprovalReceiptStore::class);
        config()->set('verdict.rate_limits.store', InMemoryRateLimitStore::class);
        config()->set('verdict.execution_claims.store', InMemoryExecutionClaimStore::class);

        // Live evaluation is opt-in and workbench-only: the package's own config/verdict.php
        // ships `evaluation.suites` empty and `live_enabled` false. This is the application
        // (workbench) supplying its own agent, model, and fixtures — see docs/evaluation.md,
        // "Ollama live evaluation". The suite mapping alone is inert (RunLiveEvaluationCommand
        // still requires the suite name argument and LiveEvaluationOptions(enabled: true)), so
        // it is safe to register unconditionally. `live_enabled` is Verdict's real default-off
        // safety gate, though, and LiveEvaluationRunner reads it eagerly when the container
        // resolves it for RunLiveEvaluationCommand::handle() — before that command body runs —
        // so it cannot be deferred into StorefrontLiveSuiteFactory::make(). Flipping it here
        // unconditionally would flip the repo's default-off posture for every testbench boot,
        // including the other ~420 package tests. Scope it to only the process invoking
        // `verdict:evaluation-live` by name, identified from the real CLI argv rather than a
        // config or container flag nothing else sets.
        config()->set('verdict.evaluation.suites', [
            'storefront' => StorefrontLiveSuiteFactory::class,
        ]);

        $argv = $_SERVER['argv'] ?? [];

        if (is_array($argv) && in_array('verdict:evaluation-live', $argv, true)) {
            config()->set('verdict.evaluation.live_enabled', true);

            // The control arm's gate stays separate here too (ADR 0023): the --control flag
            // alone must not enable it, so the workbench requires an explicit env opt-in as its
            // config-layer act — the equivalent of what a real application sets in its own
            // deployed configuration, scoped like live_enabled to this command's process only.
            // getenv, not env(): a runtime harness read, and env() returns null once config is
            // cached (PHPStan flags it). getenv returns false when unset, which filter_var reads
            // as false — the safe default.
            if (filter_var(getenv('VERDICT_CONTROL_ENABLED'), FILTER_VALIDATE_BOOL)) {
                config()->set('verdict.evaluation.control_enabled', true);
            }

            // A deliberate recorded run legitimately wants more trials than the default cap of 25
            // (e.g. n=30 so a zero-breach rule-of-three bound reaches ~10%). Raise it only for
            // this command's process via an explicit env opt-in, leaving the shipped safety
            // default untouched everywhere else.
            $maxTrials = getenv('VERDICT_MAX_TRIALS');

            if (is_string($maxTrials) && is_numeric($maxTrials)) {
                config()->set('verdict.evaluation.maximum_trials', (int) $maxTrials);
            }
        }
    }

    public function boot(VerdictManager $verdict, Catalog $catalog): void
    {
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(OrderSearchScope::class, OrderSearchScopePolicy::class);

        $verdict->releasePolicy(
            ReleasePolicy::between(
                Source::application('customer-profile'),
                Destination::connection('ollama-local', 'local-machine'),
            )
                ->allow(DataClass::PII)
                ->whenTrustIs(Trust::Trusted),
        );

        $verdict->capability(
            Capability::usingPolicy(
                name: 'orders.view',
                ability: 'view',
                resolveTarget: fn (ActionEnvelope $envelope): Order => $catalog->order(
                    (int) $envelope->proposal->arguments['order_id'],
                ),
            )->executionTarget($this->orderTargetPolicy($catalog))->executeUsing(function (AuthorizedAction $action): string {
                if (! $action->target instanceof Order) {
                    throw new LogicException('The storefront view capability expected an order.');
                }

                return json_encode($action->target->disclosure(), JSON_THROW_ON_ERROR);
            }),
        );

        // A SEPARATE capability registration (resolveTarget is fixed at construction, so this is a
        // distinct configuration, not orders.view under a different resolver). It reads the target
        // the *user* intended from the trusted ActionContext metadata — never from the model's
        // proposal arguments — so an injected argument naming a different owned order cannot
        // redirect the executor. This is the context-resolved arm of the #187 authority/intent
        // differential; orders.view above is the proposal-resolved arm. See #192.
        $verdict->capability(
            Capability::usingPolicy(
                name: 'orders.view-by-context',
                ability: 'view',
                resolveTarget: fn (ActionEnvelope $envelope): Order => $catalog->order(
                    (int) ($envelope->context->metadata['intended_order_id'] ?? 0),
                ),
            )->executionTarget($this->orderTargetPolicy($catalog))->executeUsing(function (AuthorizedAction $action): string {
                if (! $action->target instanceof Order) {
                    throw new LogicException('The storefront context-resolved view capability expected an order.');
                }

                return json_encode($action->target->disclosure(), JSON_THROW_ON_ERROR);
            }),
        );

        // The set-returning arm (#251): a search has no single record for the policy to inspect,
        // so the resolver returns a scope value object bound to the actor and the policy
        // authorizes the scope. Registered via usingPolicyForContextTarget so the guarantee is
        // type-level and evidence-visible (ADR 0025): the resolver receives only the trusted
        // ActionContext — the model's arguments, which are the filter the executor applies INSIDE
        // the scope, are not even in scope here — and every evidence row records
        // target_source=context. The tenant filter thereby lives inside the boundary: resolver
        // code, carried in evidence, and observable at the connection (the executor's real query
        // is what the predicate capture digests).
        $verdict->capability(
            Capability::usingPolicyForContextTarget(
                name: 'orders.search',
                ability: 'search',
                resolveTarget: fn (ActionContext $context): OrderSearchScope => OrderSearchScope::forContext($context),
            )->executionTarget(ExecutionTargetPolicy::refresh(
                name: 'storefront-order-search-scope',
                identityUsing: fn (ActionEnvelope $envelope, OrderSearchScope $scope): array => [
                    'tenant_id' => $envelope->context->metadata['tenant_id'] ?? null,
                    'resource_type' => 'order-search-scope',
                    'customer_id' => $scope->customerId,
                ],
                // Deterministic by construction: re-resolving from the same trusted context yields
                // the same scope, so a mismatch here can only mean the context itself changed.
                refreshUsing: fn (ActionEnvelope $envelope, OrderSearchScope $scope): OrderSearchScope => OrderSearchScope::forContext($envelope->context),
            ))->executeUsing(function (AuthorizedAction $action): string {
                if (! $action->target instanceof OrderSearchScope) {
                    throw new LogicException('The storefront search capability expected an order-search scope.');
                }

                // One shared body for both arms; the scope argument is the arms' entire difference.
                return StorefrontOrders::search(
                    app(DatabaseManager::class)->connection(),
                    $action->envelope->proposal->arguments,
                    $action->target,
                );
            }),
        );

        $verdict->capability(
            Capability::usingPolicy(
                name: 'orders.support-notes',
                ability: 'view',
                resolveTarget: fn (ActionEnvelope $envelope): Order => $catalog->order(
                    (int) $envelope->proposal->arguments['order_id'],
                ),
            )->executionTarget($this->orderTargetPolicy($catalog))->executeUsing(function (AuthorizedAction $action): string {
                if (! $action->target instanceof Order) {
                    throw new LogicException('The storefront support-note capability expected an order.');
                }

                $note = app(SupportNoteChannel::class)->current();

                return json_encode([
                    'order_id' => $action->target->id,
                    'note' => $note ?? 'No support note is on file for this order.',
                ], JSON_THROW_ON_ERROR);
            }),
        );

        $verdict->capability(
            Capability::usingPolicy(
                name: 'orders.refresh-shipment',
                ability: 'view',
                resolveTarget: fn (ActionEnvelope $envelope): Order => $catalog->order(
                    (int) $envelope->proposal->arguments['order_id'],
                ),
            )->executionTarget($this->orderTargetPolicy($catalog))->rateLimit(RateLimitPolicy::fixedWindow(
                name: 'per-customer-order',
                limit: 2,
                windowSeconds: 60,
                keyUsing: function (ActionEnvelope $envelope, Order $order): array {
                    $actorId = $envelope->context->actor instanceof Authenticatable
                        ? $envelope->context->actor->getAuthIdentifier()
                        : null;

                    return [
                        'actor_id' => $actorId,
                        'tenant_id' => $envelope->context->metadata['tenant_id'] ?? null,
                        'order_id' => $order->id,
                    ];
                },
                reason: 'Carrier status refresh limit exceeded.',
            ))->executeUsing(function (AuthorizedAction $action): string {
                if (! $action->target instanceof Order) {
                    throw new LogicException('The shipment refresh capability expected an order.');
                }

                app(ActionLog::class)->record(
                    'orders.refresh-shipment',
                    $action->target->id,
                    'carrier_status_refreshed',
                );

                return json_encode([
                    'status' => 'refreshed',
                    'order_id' => $action->target->id,
                    'shipment_status' => $action->target->status,
                ], JSON_THROW_ON_ERROR);
            }),
        );

        $verdict->capability(
            Capability::usingPolicy(
                name: 'orders.cancel',
                ability: 'cancel',
                resolveTarget: fn (ActionEnvelope $envelope): Order => $catalog->order(
                    (int) $envelope->proposal->arguments['order_id'],
                ),
            )->executionTarget($this->orderTargetPolicy($catalog))->requiresConfirmation(
                bindUsing: function (ActionEnvelope $envelope, Order $order): array {
                    $actorId = $envelope->context->actor instanceof Authenticatable
                        ? $envelope->context->actor->getAuthIdentifier()
                        : null;

                    return [
                        'actor_id' => $actorId,
                        'tenant_id' => $envelope->context->metadata['tenant_id'] ?? null,
                        'order_id' => $order->id,
                        'order_version' => $order->version,
                    ];
                },
                reason: 'Confirm cancellation of order #1002.',
                ttlSeconds: 300,
            )->executeUsing(function (AuthorizedAction $action): string {
                if (! $action->target instanceof Order) {
                    throw new LogicException('The storefront cancellation capability expected an order.');
                }

                app(ActionLog::class)->record('orders.cancel', $action->target->id);

                return json_encode([
                    'status' => 'cancelled',
                    'order_id' => $action->target->id,
                ], JSON_THROW_ON_ERROR);
            }),
        );

        $verdict->capability(
            Capability::usingPolicy(
                name: 'orders.request-cancellation',
                ability: 'cancel',
                resolveTarget: fn (ActionEnvelope $envelope): Order => $catalog->order(
                    (int) $envelope->proposal->arguments['order_id'],
                ),
            )->executionTarget($this->orderTargetPolicy($catalog))->atMostOnce(ExecutionClaimPolicy::named(
                name: 'customer-order-version',
                keyUsing: function (ActionEnvelope $envelope, Order $order): array {
                    $actorId = $envelope->context->actor instanceof Authenticatable
                        ? $envelope->context->actor->getAuthIdentifier()
                        : null;

                    return [
                        'actor_id' => $actorId,
                        'tenant_id' => $envelope->context->metadata['tenant_id'] ?? null,
                        'order_id' => $order->id,
                        'order_version' => $order->version,
                    ];
                },
            ))->executeUsing(function (AuthorizedAction $action): string {
                if (! $action->target instanceof Order) {
                    throw new LogicException('The storefront cancellation-request capability expected an order.');
                }

                app(ActionLog::class)->record(
                    'orders.request-cancellation',
                    $action->target->id,
                    'cancellation_requested',
                );

                return json_encode([
                    'status' => 'cancellation_requested',
                    'order_id' => $action->target->id,
                ], JSON_THROW_ON_ERROR);
            }),
        );

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'verdict-workbench');
    }

    private function orderTargetPolicy(Catalog $catalog): ExecutionTargetPolicy
    {
        return ExecutionTargetPolicy::refresh(
            name: 'storefront-order-primary-key',
            identityUsing: fn (ActionEnvelope $envelope, Order $order): array => [
                'tenant_id' => $envelope->context->metadata['tenant_id'] ?? null,
                'resource_type' => 'order',
                'resource_id' => $order->id,
            ],
            refreshUsing: fn (ActionEnvelope $envelope, Order $proposalTarget): Order => $catalog->order(
                $proposalTarget->id,
            ),
        );
    }
}
