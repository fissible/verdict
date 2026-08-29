<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Tests\Support\EvidenceTableSchema;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\DatabaseManager;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * #391, the consequence rather than the mechanism.
 *
 * Refusing an unreadable write is only half a claim. `VerdictManager::record()` calls the recorder
 * with nothing between them, so a throw there leaves the guarded action, and this is where that is
 * either acceptable or not. Two things have to be true at once, and neither is provable from the
 * recorder in isolation:
 *
 *   - On a broken table the action does not execute. Not "it also errors" — the side effect must
 *     not happen, because a tool that ran while its decision went unrecorded is precisely the
 *     outcome evidence exists to make impossible.
 *   - On a table that is merely incomplete the action does execute. This is the compatibility
 *     claim the release rests on, and it is why the required set was chosen to hold only columns
 *     that have existed for the life of the table: no published migration path reaches the first
 *     case, so the blast radius of the change is the set of deployments that were already
 *     silently losing evidence.
 */
final class RequiredColumnProbeTool implements Tool
{
    public int $invocations = 0;

    public function description(): Stringable|string
    {
        return 'Probe.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->invocations++;

        return 'ran';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

beforeEach(function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    app()->forgetInstance(EvidenceRecorder::class);
    app()->forgetInstance(VerdictManager::class);

    // Permit everything, so what the test observes is the evidence write and not a denial.
    app()->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('Permitted.');
        }
    });

    app(VerdictManager::class)->capability(Capability::usingPolicy(
        name: 'probe.run',
        ability: 'run',
        resolveTarget: fn (ActionEnvelope $envelope): string => 'the-target',
    ));
});

afterEach(function (): void {
    EvidenceTableSchema::drop();
    EvidenceTableSchema::dropDerivations();
});

function requiredColumnGuardedRows(): int
{
    return app(DatabaseManager::class)->connection()->table(verdictTable('evidence'))->count();
}

it('binds the database recorder for these tests', function (): void {
    // The premise, checked rather than assumed. TestCase::defineEnvironment binds the in-memory
    // recorder, and against that one every assertion below would pass while proving nothing.
    expect(app(EvidenceRecorder::class))->toBeInstanceOf(DatabaseEvidenceRecorder::class);
});

it('does not execute the action when the evidence table cannot record the decision', function (): void {
    EvidenceTableSchema::createWithoutColumns(['record_type']);
    EvidenceTableSchema::createDerivations();

    $tool = new RequiredColumnProbeTool;
    $guarded = app(VerdictManager::class)->guard($tool, 'probe.run', new ActionContext('actor-391'));

    $thrown = null;

    try {
        $guarded->handle(new Request([], 'call-391'));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    // Fail closed, and fail before the side effect. An implementation that recorded after
    // executing, or that swallowed the error and carried on, would leave the action done and the
    // decision unrecorded — the state #391 makes unreachable.
    expect($thrown)->not->toBeNull('a decision that cannot be recorded must not authorize an action');
    expect($tool->invocations)->toBe(0)
        ->and(requiredColumnGuardedRows())->toBe(0);
});

it('executes the action when the evidence table is merely incomplete', function (): void {
    // A column no reader depends on, absent. The compatibility claim: an install that is behind
    // keeps working, and only the retained detail is reduced.
    EvidenceTableSchema::createWithoutColumns(['reason']);
    EvidenceTableSchema::createDerivations();

    $tool = new RequiredColumnProbeTool;
    $guarded = app(VerdictManager::class)->guard($tool, 'probe.run', new ActionContext('actor-391'));

    $guarded->handle(new Request([], 'call-391'));

    expect($tool->invocations)->toBe(1)
        ->and(requiredColumnGuardedRows())->toBeGreaterThan(0);
});

it('executes the action when the evidence table is current', function (): void {
    EvidenceTableSchema::createComplete();
    EvidenceTableSchema::createDerivations();

    $tool = new RequiredColumnProbeTool;
    $guarded = app(VerdictManager::class)->guard($tool, 'probe.run', new ActionContext('actor-391'));

    $guarded->handle(new Request([], 'call-391'));

    // The unconditional control: whatever the two cases above prove, they prove nothing unless a
    // fully migrated table runs the action and records it.
    expect($tool->invocations)->toBe(1)
        ->and(requiredColumnGuardedRows())->toBeGreaterThan(0);
});
