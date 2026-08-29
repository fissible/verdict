<?php

declare(strict_types=1);

use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\ClassifiesToolResult;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\Exceptions\ToolResultProvenanceUnrecordable;
use Fissible\Verdict\LaravelAi\RecordToolResultProvenance;
use Fissible\Verdict\Tests\Support\FrozenClock;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\Request;

/**
 * #311 item 5 (the safe half) — a classified tool whose result cannot be canonicalized into a
 * provenance fingerprint currently halts the turn (correct, fail-closed) but with an opaque
 * CanonicalJson error naming only the value's type, surfacing far from the tool at fault. This
 * relocates the failure: it must STILL halt, now with a ToolResultProvenanceUnrecordable naming the
 * tool and invocation and chaining the original error. The fail-closed behavior is unchanged.
 */
final class UnrecordableResultTool implements ClassifiesToolResult, Tool
{
    public function description(): Stringable|string
    {
        return 'A tool whose result may not be scalarizable.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'unused in these tests';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function provenanceSource(): Source
    {
        return Source::external('catalog-service');
    }

    public function provenanceTrust(): Trust
    {
        return Trust::Untrusted;
    }

    public function provenanceDataClass(): DataClass
    {
        return DataClass::Internal;
    }
}

function toolResultListener(InMemoryEvidenceRecorder $recorder): RecordToolResultProvenance
{
    return new RecordToolResultProvenance(new ProvenanceLedger($recorder, $recorder, new FrozenClock));
}

function toolInvokedWith(mixed $result, Tool $tool): ToolInvoked
{
    return new ToolInvoked(
        invocationId: 'inv-42',
        toolInvocationId: 'call-1',
        agent: Mockery::mock(Agent::class),
        tool: $tool,
        arguments: [],
        result: $result,
        time: 1.0,
    );
}

it('halts with a tool-named error when a classified tool result cannot be recorded as provenance', function (): void {
    $recorder = new InMemoryEvidenceRecorder;
    $listener = toolResultListener($recorder);

    expect(fn (): mixed => $listener->handle(toolInvokedWith(new stdClass, new UnrecordableResultTool)))
        ->toThrow(ToolResultProvenanceUnrecordable::class, UnrecordableResultTool::class);
});

it('names the invocation and points at provenance in the relocated error', function (): void {
    $recorder = new InMemoryEvidenceRecorder;
    $listener = toolResultListener($recorder);

    try {
        $listener->handle(toolInvokedWith(new stdClass, new UnrecordableResultTool));
        $this->fail('Expected the unrecordable tool result to halt the turn.');
    } catch (ToolResultProvenanceUnrecordable $e) {
        expect($e->getMessage())
            ->toContain('inv-42')
            ->toContain('provenance')
            // The opaque canonicalization error is preserved as the cause, not discarded.
            ->and($e->getPrevious())->toBeInstanceOf(InvalidArgumentException::class);
    }
});

it('records nothing new when the result is unrecordable, leaving prior provenance intact', function (): void {
    $recorder = new InMemoryEvidenceRecorder;
    $listener = toolResultListener($recorder);

    // A prior good record under the same invocation, so 'empty' cannot pass by never recording at all.
    $listener->handle(toolInvokedWith('first good result', new UnrecordableResultTool));
    expect($recorder->provenanceFor('inv-42'))->toHaveCount(1);

    $threw = false;
    try {
        $listener->handle(toolInvokedWith(new stdClass, new UnrecordableResultTool));
    } catch (ToolResultProvenanceUnrecordable) {
        $threw = true;
    }

    // The halt must be a real throw (not a swallow), and it must add nothing — the prior entry stands.
    expect($threw)->toBeTrue()
        ->and($recorder->provenanceFor('inv-42'))->toHaveCount(1);
});

it('does not relabel an invalid invocation id as a result problem (precise translation)', function (): void {
    $recorder = new InMemoryEvidenceRecorder;
    $listener = toolResultListener($recorder);

    // The RESULT is a recordable scalar; the invocation id is what is invalid. The failure must stay
    // the original InvalidArgumentException, never be dressed up as an unrecordable-result error.
    $event = new ToolInvoked(
        invocationId: 'invalid id!',
        toolInvocationId: 'call-1',
        agent: Mockery::mock(Agent::class),
        tool: new UnrecordableResultTool,
        arguments: [],
        result: 'a recordable scalar',
        time: 1.0,
    );

    try {
        $listener->handle($event);
        $this->fail('Expected the invalid invocation id to fail.');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(InvalidArgumentException::class)
            ->and($e)->not->toBeInstanceOf(ToolResultProvenanceUnrecordable::class);
    }
});

it('records provenance normally for a scalar tool result', function (): void {
    $recorder = new InMemoryEvidenceRecorder;
    $listener = toolResultListener($recorder);

    $listener->handle(toolInvokedWith('a plain string result', new UnrecordableResultTool));

    $entries = $recorder->provenanceFor('inv-42');
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->channel)->toBe(ContextChannel::ToolResult);
});

it('records provenance normally for an array result whose leaves are all scalar', function (): void {
    $recorder = new InMemoryEvidenceRecorder;
    $listener = toolResultListener($recorder);

    $listener->handle(toolInvokedWith(['id' => 7, 'tags' => ['a', 'b'], 'ok' => true], new UnrecordableResultTool));

    expect($recorder->provenanceFor('inv-42'))->toHaveCount(1);
});

it('ignores a tool that does not classify its result, recording nothing and throwing nothing', function (): void {
    $recorder = new InMemoryEvidenceRecorder;
    $listener = toolResultListener($recorder);
    $unclassified = Mockery::mock(Tool::class);

    // Even a non-scalarizable result must be ignored when the tool opts out of classification.
    $listener->handle(toolInvokedWith(new stdClass, $unclassified));

    expect($recorder->provenanceFor('inv-42'))->toBe([]);
});
