<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evaluation\LiveToolCapture;
use Fissible\Verdict\Evaluation\ResourceCheckpointCapture;
use Fissible\Verdict\Evaluation\ResourceIdentity;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\Container\Container;

/**
 * The captured resource identity is whatever the capability's policy says it is (#295).
 *
 * `tests/Workbench/StorefrontCheckToUseSwapTest.php` derives its expected identity from the
 * registered policy, which stops the test from restating the fields — but it cannot show that the
 * CAPTURE consults the policy, because the storefront's declaration is the only one in play. A
 * capture hard-coded to a tenant/resource_type/resource_id shape would agree with it on every run.
 *
 * So this file declares a policy with deliberately DIFFERENT identity keys and asserts the captured
 * identity follows those instead. It is a focused differential rather than a second workbench
 * capability: the point is one contradiction, not another fixture for everything else to maintain.
 */
function differentialEnvelope(): ActionEnvelope
{
    return ActionEnvelope::wrap(
        new ActionProposal('records.differential', ['record_id' => 55]),
        new ActionContext(actor: 72, metadata: ['tenant_id' => 'tenant-a', 'region' => 'eu-west']),
    );
}

function permitDifferentialAuthorization(Container $app): void
{
    $app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit();
        }
    });
}

it('derives the captured identity from the policy declaration, whatever its keys are', function (): void {
    permitDifferentialAuthorization($this->app);

    // Two distinct targets that share an IDENTITY and differ in CONTENT. The identity must match:
    // ADR 0003 §7 denies an execution whose refreshed target identifies a different record, and
    // VerdictManager enforces it — so a differential built on differing identities asks for a state
    // the boundary forbids and could never execute. Content is where they differ, which is also the
    // premise of this whole issue: same record, different bytes.
    $proposalTarget = (object) ['ref' => 'REC-55', 'region' => 'eu-west', 'body' => 'as-proposed'];
    $record = (object) ['ref' => 'REC-55', 'region' => 'eu-west', 'body' => 'as-refreshed'];

    // Nothing here resembles the storefront's tenant/resource_type/resource_id shape: different key
    // names, a different order, and a value drawn from context rather than from the record. A
    // capture that hard-coded the storefront shape cannot produce this.
    $policy = ExecutionTargetPolicy::refresh(
        name: 'differential-identity',
        identityUsing: fn (ActionEnvelope $envelope, object $target): array => [
            'archive' => 'ledger-b',
            'record_ref' => $target->ref,
            'region' => $envelope->context->metadata['region'] ?? null,
        ],
        refreshUsing: fn (ActionEnvelope $envelope, object $target): object => $record,
    );

    $verdict = app(VerdictManager::class);
    $verdict->capability(
        Capability::usingPolicy('records.differential', 'view', fn (): object => $proposalTarget)
            ->executionTarget($policy)
            ->executeUsing(fn (AuthorizedAction $action): string => 'read'),
    );

    $sink = new LiveToolCapture;
    $this->app->instance(ResourceCheckpointCapture::class, new ResourceCheckpointCapture($sink, 'record-body'));

    $envelope = differentialEnvelope();
    $result = $verdict->runBound($envelope);

    expect($result->executed)->toBeTrue()
        ->and($sink->resources())->toHaveCount(1);

    // The expectation comes from the policy itself, so this asserts agreement with the declaration
    // rather than with a shape written twice.
    $expected = ResourceIdentity::for(
        $policy->identity($envelope, $record),
    );

    expect($sink->resources()[0]->resourceIdentity)->toBe($expected);

    // And it is genuinely different from the storefront shape, so agreement above cannot be
    // coincidence between two declarations that happen to look alike.
    expect($expected)->not->toBe(ResourceIdentity::for([
        'tenant_id' => 'tenant-a',
        'resource_type' => 'record',
        'resource_id' => 55,
    ]));

    // The capture must describe the REFRESHED target, which ADR 0003 makes the one the executor acts
    // on. Identity cannot show that here — both targets identify the same record by necessity — so
    // the content does: a second run whose refresh returns different bytes must produce a different
    // digest. An implementation that captured the proposal target, or skipped the refresh, would
    // report the same digest twice.
    $capturedRefreshed = $sink->resources()[0]->digest;

    $alternate = (object) ['ref' => 'REC-55', 'region' => 'eu-west', 'body' => 'refreshed-differently'];
    $secondSink = new LiveToolCapture;

    $verdict->capability(
        Capability::usingPolicy('records.differential-alternate', 'view', fn (): object => $proposalTarget)
            ->executionTarget(ExecutionTargetPolicy::refresh(
                name: 'differential-identity-alternate',
                identityUsing: fn (ActionEnvelope $envelope, object $target): array => [
                    'archive' => 'ledger-b',
                    'record_ref' => $target->ref,
                    'region' => $envelope->context->metadata['region'] ?? null,
                ],
                refreshUsing: fn (ActionEnvelope $envelope, object $target): object => $alternate,
            ))
            ->executeUsing(fn (AuthorizedAction $action): string => 'read'),
    );

    $this->app->instance(ResourceCheckpointCapture::class, new ResourceCheckpointCapture($secondSink, 'record-body'));

    $second = $verdict->runBound(ActionEnvelope::wrap(
        new ActionProposal('records.differential-alternate', ['record_id' => 55]),
        new ActionContext(actor: 72, metadata: ['tenant_id' => 'tenant-a', 'region' => 'eu-west']),
    ));

    expect($second->executed)->toBeTrue()
        ->and($secondSink->resources())->toHaveCount(1)
        ->and($secondSink->resources()[0]->digest)->not->toBe($capturedRefreshed);
});
