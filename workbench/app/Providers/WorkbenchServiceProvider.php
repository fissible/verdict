<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Workbench\App\Storefront\ActionLog;
use Workbench\App\Storefront\Catalog;
use Workbench\App\Storefront\Order;
use Workbench\App\Storefront\OrderPolicy;

final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Catalog::class);
        $this->app->scoped(ActionLog::class);
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
    }

    public function boot(VerdictManager $verdict, Catalog $catalog): void
    {
        Gate::policy(Order::class, OrderPolicy::class);

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
