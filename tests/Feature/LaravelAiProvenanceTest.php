<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\ActionProposal;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Destination;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\ClassifiesToolResult;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Evidence\ContentFingerprint;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\InMemoryEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\LaravelAi\PromptProvenanceRegistry;
use Fissible\Verdict\LaravelAi\VerdictProvenanceMiddleware;
use Fissible\Verdict\VerdictManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Tools\Request;

final class ClassifiedProvenanceTool implements ClassifiesToolResult, Tool
{
    public function description(): Stringable|string
    {
        return 'Returns a classified external result.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'tool result';
    }

    /** @return array<string, Type> */
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

function laravelAiProvenancePrompt(
    string $prompt,
    ?string $invocationId = null,
    ?Decisions $approvalDecisions = null,
    ?Agent $agent = null,
): AgentPrompt {
    return new AgentPrompt(
        agent: $agent ?? Mockery::mock(Agent::class),
        prompt: $prompt,
        attachments: ['attachment.txt'],
        provider: Mockery::mock(TextProvider::class),
        model: 'test-model',
        invocationId: $invocationId,
        approvalDecisions: $approvalDecisions,
    );
}

function laravelAiProvenanceMiddleware(
    ?ProvenanceLedger $ledger = null,
    Trust $trust = Trust::Untrusted,
    DataClass $dataClass = DataClass::Internal,
    ?Source $source = null,
): VerdictProvenanceMiddleware {
    return new VerdictProvenanceMiddleware(
        provenance: $ledger ?? app(ProvenanceLedger::class),
        trust: $trust,
        dataClass: $dataClass,
        source: $source,
    );
}

it('records a prompt before passing the unchanged prompt to the next middleware', function (): void {
    $prompt = laravelAiProvenancePrompt('cancel order 123', 'invocation-123');
    $nextPrompt = null;

    $result = laravelAiProvenanceMiddleware(source: Source::user('customer-message'))->handle(
        $prompt,
        function (AgentPrompt $received) use (&$nextPrompt): string {
            $nextPrompt = $received;

            return 'next result';
        },
    );

    expect($result)->toBe('next result')
        ->and($nextPrompt)->toBe($prompt);

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        return;
    }

    $entries = $recorder->provenanceFor('invocation-123');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->channel)->toBe(ContextChannel::UserInput)
        ->and($entries[0]->source)->toEqual(Source::user('customer-message'))
        ->and($entries[0]->contentFingerprint)->toBe(ContentFingerprint::make('cancel order 123'))
        ->and(json_encode($entries[0], JSON_THROW_ON_ERROR))->not->toContain('cancel order 123');
});

it('correlates decision and context-release evidence with the Laravel AI invocation', function (): void {
    laravelAiProvenanceMiddleware(source: Source::user('agent-prompt'))->handle(
        laravelAiProvenancePrompt('inspect order', 'invocation-evidence'),
        function (): void {
            app(VerdictManager::class)->evaluate(ActionEnvelope::wrap(
                new ActionProposal('orders.inspect', []),
                new ActionContext('customer-72'),
            ));

            app(VerdictManager::class)->release(['email' => 'avery@example.com'])
                ->source(Source::application('customer-profile'))
                ->trust(Trust::Trusted)
                ->classify(DataClass::PII)
                ->only(['email'])
                ->to(Destination::connection('local-model', 'local-machine'));
        },
    );

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        return;
    }

    expect($recorder->provenanceFor('invocation-evidence'))->toHaveCount(1)
        ->and($recorder->all()[0]->invocationId)->toBe('invocation-evidence')
        ->and($recorder->releases()[0]->invocationId)->toBe('invocation-evidence');
});

it('uses the PromptingAgent invocation ID without fingerprinting a revised combined prompt', function (): void {
    $prompt = laravelAiProvenancePrompt('original user request');
    $middleware = laravelAiProvenanceMiddleware(source: Source::user('agent-prompt'));

    $middleware->handle($prompt, function (AgentPrompt $received): string {
        event(new PromptingAgent(
            invocationId: 'invocation-from-laravel-ai',
            prompt: $received->append('retrieved document content'),
        ));

        return 'next result';
    });

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        return;
    }

    $entries = $recorder->provenanceFor('invocation-from-laravel-ai');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->contentFingerprint)->toBe(ContentFingerprint::make('original user request'))
        ->and($entries[0]->contentFingerprint)->not->toBe(ContentFingerprint::make('original user request'.PHP_EOL.PHP_EOL.'retrieved document content'));
});

it('discards deferred provenance when downstream middleware fails before the invocation event', function (): void {
    $agent = Mockery::mock(Agent::class);
    $middleware = laravelAiProvenanceMiddleware(source: Source::user('agent-prompt'));

    expect(fn (): mixed => $middleware->handle(
        laravelAiProvenancePrompt('failed request', agent: $agent),
        fn (): never => throw new RuntimeException('Downstream middleware failed.'),
    ))->toThrow(RuntimeException::class, 'Downstream middleware failed.');

    $middleware->handle(
        laravelAiProvenancePrompt('later request', agent: $agent),
        function (AgentPrompt $received): string {
            event(new PromptingAgent('later-invocation', $received));

            return 'next result';
        },
    );

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        return;
    }

    $entries = $recorder->provenanceFor('later-invocation');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->contentFingerprint)->toBe(ContentFingerprint::make('later request'))
        ->and($entries[0]->contentFingerprint)->not->toBe(ContentFingerprint::make('failed request'));
});

it('fails closed instead of ambiguously correlating overlapping prompts for one agent', function (): void {
    $agent = Mockery::mock(Agent::class);
    $middleware = laravelAiProvenanceMiddleware(source: Source::user('agent-prompt'));
    $overlappingNextCalled = false;

    $middleware->handle(
        laravelAiProvenancePrompt('first request', agent: $agent),
        function (AgentPrompt $received) use ($agent, $middleware, &$overlappingNextCalled): string {
            expect(fn (): mixed => $middleware->handle(
                laravelAiProvenancePrompt('overlapping request', agent: $agent),
                function () use (&$overlappingNextCalled): string {
                    $overlappingNextCalled = true;

                    return 'should not run';
                },
            ))->toThrow(LogicException::class, 'Prompt provenance is already pending for this agent instance.');

            event(new PromptingAgent('first-invocation', $received));

            return 'next result';
        },
    );

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class)
        ->and($overlappingNextCalled)->toBeFalse();

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        return;
    }

    $entries = $recorder->provenanceFor('first-invocation');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->contentFingerprint)->toBe(ContentFingerprint::make('first request'))
        ->and($entries[0]->contentFingerprint)->not->toBe(ContentFingerprint::make('overlapping request'));
});

it('does not let an earlier cleanup token discard a later registration', function (): void {
    $agent = Mockery::mock(Agent::class);
    $registry = new PromptProvenanceRegistry;

    $firstRegistration = $registry->remember(
        $agent,
        'first request',
        Source::user('agent-prompt'),
        Trust::Untrusted,
        DataClass::Internal,
    );

    expect($registry->consume($agent)['prompt'] ?? null)->toBe('first request');

    $registry->remember(
        $agent,
        'second request',
        Source::user('agent-prompt'),
        Trust::Untrusted,
        DataClass::Internal,
    );

    $registry->forgetIfPending($agent, $firstRegistration);

    expect($registry->consume($agent)['prompt'] ?? null)->toBe('second request');
});

it('does not let approval resumption consume another prompt registration', function (): void {
    $agent = Mockery::mock(Agent::class);
    $middleware = laravelAiProvenanceMiddleware(source: Source::user('agent-prompt'));

    $middleware->handle(
        laravelAiProvenancePrompt('original request', agent: $agent),
        function (AgentPrompt $received) use ($agent): string {
            $approval = laravelAiProvenancePrompt(
                'approve the pending action',
                'approval-invocation',
                Decisions::from(['tool-call-1' => true]),
                $agent,
            );

            event(new PromptingAgent('approval-event', $approval));
            event(new PromptingAgent('original-invocation', $received));

            return 'next result';
        },
    );

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        return;
    }

    $entries = $recorder->provenanceFor('original-invocation');

    expect($recorder->provenanceFor('approval-invocation'))->toBe([])
        ->and($recorder->provenanceFor('approval-event'))->toBe([])
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]->contentFingerprint)->toBe(ContentFingerprint::make('original request'));
});

it('correlates sequential prompts from the same agent independently', function (): void {
    $agent = Mockery::mock(Agent::class);
    $middleware = laravelAiProvenanceMiddleware(source: Source::user('agent-prompt'));

    foreach (['first-invocation' => 'first request', 'second-invocation' => 'second request'] as $invocationId => $content) {
        $middleware->handle(
            laravelAiProvenancePrompt($content, agent: $agent),
            function (AgentPrompt $received) use ($invocationId): string {
                event(new PromptingAgent($invocationId, $received));

                return 'next result';
            },
        );
    }

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        return;
    }

    expect($recorder->provenanceFor('first-invocation'))->toHaveCount(1)
        ->and($recorder->provenanceFor('first-invocation')[0]->contentFingerprint)->toBe(ContentFingerprint::make('first request'))
        ->and($recorder->provenanceFor('second-invocation'))->toHaveCount(1)
        ->and($recorder->provenanceFor('second-invocation')[0]->contentFingerprint)->toBe(ContentFingerprint::make('second request'));
});

it('does not duplicate user provenance on approval resumption', function (): void {
    $prompt = laravelAiProvenancePrompt(
        'approve the pending action',
        'approval-invocation',
        Decisions::from(['tool-call-1' => true]),
    );

    laravelAiProvenanceMiddleware()->handle($prompt, fn (AgentPrompt $received): string => 'resumed');
    event(new PromptingAgent('approval-event', $prompt));

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        return;
    }

    expect($recorder->provenanceFor('approval-invocation'))->toBe([])
        ->and($recorder->provenanceFor('approval-event'))->toBe([]);
});

it('provides an explicit retrieved-document helper', function (): void {
    $entry = laravelAiProvenanceMiddleware()->recordRetrievedDocument(
        correlationId: 'retrieval-invocation',
        document: ['body' => 'untrusted document', 'rank' => 1],
        source: Source::external('search-index'),
        trust: Trust::Untrusted,
        dataClass: DataClass::Internal,
        componentLabel: 'retriever',
        componentVersion: 'v1',
    );

    expect($entry->channel)->toBe(ContextChannel::RetrievedDocument)
        ->and($entry->componentLabel)->toBe('retriever')
        ->and($entry->componentFingerprint)->toBe(ContentFingerprint::make('v1'));
});

it('records only classified tool results and correlates them to the invocation', function (): void {
    $classified = new ClassifiedProvenanceTool;
    $unclassified = Mockery::mock(Tool::class);
    $agent = Mockery::mock(Agent::class);

    event(new ToolInvoked(
        invocationId: 'tool-invocation',
        toolInvocationId: 'tool-call-1',
        agent: $agent,
        tool: $classified,
        arguments: ['secret' => 'do-not-record'],
        result: 'classified tool result',
    ));
    event(new ToolInvoked(
        invocationId: 'tool-invocation',
        toolInvocationId: 'tool-call-2',
        agent: $agent,
        tool: $unclassified,
        arguments: [],
        result: 'unclassified result',
    ));

    $recorder = app(EvidenceRecorder::class);
    expect($recorder)->toBeInstanceOf(InMemoryEvidenceRecorder::class);

    if (! $recorder instanceof InMemoryEvidenceRecorder) {
        return;
    }

    $entries = $recorder->provenanceFor('tool-invocation');

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->channel)->toBe(ContextChannel::ToolResult)
        ->and($entries[0]->source)->toEqual(Source::external('catalog-service'))
        ->and($entries[0]->contentFingerprint)->toBe(ContentFingerprint::make('classified tool result'))
        ->and(json_encode($entries[0], JSON_THROW_ON_ERROR))->not->toContain('classified tool result')
        ->and(json_encode($entries[0], JSON_THROW_ON_ERROR))->not->toContain('do-not-record');
});

it('propagates recorder failure and does not call the next middleware', function (): void {
    $ledger = new ProvenanceLedger(
        new class implements EvidenceRecorder
        {
            public function record(DecisionEvidence $evidence): void {}

            public function recordRelease(ContextReleaseEvidence $evidence): void {}

            public function recordProvenance(ProvenanceEntry $entry): void
            {
                throw new RuntimeException('Recorder unavailable.');
            }

            public function provenanceFor(string $correlationId): array
            {
                return [];
            }
        },
        app(Clock::class),
    );
    $nextCalled = false;

    expect(fn (): mixed => laravelAiProvenanceMiddleware($ledger)->handle(
        laravelAiProvenancePrompt('record me', 'recorder-failure'),
        function () use (&$nextCalled): string {
            $nextCalled = true;

            return 'should not run';
        },
    ))->toThrow(RuntimeException::class, 'Recorder unavailable.')
        ->and($nextCalled)->toBeFalse();
});
