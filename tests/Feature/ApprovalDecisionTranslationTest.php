<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Approvals\ApprovedToolCalls;
use Fissible\Verdict\Exceptions\UnsupportedApprovalDecision;
use Fissible\Verdict\LaravelAi\VerdictApprovalMiddleware;
use Laravel\Ai\Approvals\Decision as AiDecision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * ADR 0033 §2: the kernel no longer reads `Decisions`, so the translation from upstream's approval
 * vocabulary to Verdict's `ApprovedToolCalls` happens exactly once, in the adapter. These pin the
 * two exclusions the kernel used to perform itself — they are the security-bearing half of the
 * move, and a translation is precisely where such behaviour goes missing.
 *
 * Driven through the real middleware rather than a named helper: which class or method performs
 * the mapping is the implementer's choice, but the mapping has to happen on the path a resumed
 * prompt actually takes.
 */
function translatingPrompt(?Decisions $decisions): AgentPrompt
{
    return new AgentPrompt(
        agent: Mockery::mock(Agent::class),
        prompt: 'resume',
        attachments: [],
        provider: Mockery::mock(TextProvider::class),
        model: 'gpt-test',
        approvalDecisions: $decisions,
    );
}

it('carries an approved tool call across the boundary', function (): void {
    $context = new ApprovalExecutionContext;
    $seen = null;

    (new VerdictApprovalMiddleware($context))->handle(
        translatingPrompt(Decisions::from(['call-1' => AiDecision::approve()])),
        function () use ($context, &$seen): string {
            $seen = $context->allows('call-1');

            return 'done';
        },
    );

    expect($seen)->toBeTrue()
        ->and($context->allows('call-1'))->toBeFalse();
});

it('does not carry a rejected decision across as an approval', function (): void {
    $context = new ApprovalExecutionContext;
    $seen = null;

    (new VerdictApprovalMiddleware($context))->handle(
        translatingPrompt(Decisions::from(['call-rejected' => AiDecision::reject()])),
        function () use ($context, &$seen): string {
            $seen = $context->allows('call-rejected');

            return 'done';
        },
    );

    expect($seen)->toBeFalse();
});

it('does not carry the wildcard across as a tool-call id', function (): void {
    // The one with a security consequence: '*' means "approve everything" upstream. Translated as
    // an id it would authorize a call nobody approved, and translated carelessly it could reach
    // ApprovedToolCalls, which refuses it. Neither may happen — the adapter drops it.
    $context = new ApprovalExecutionContext;
    $seen = [];

    (new VerdictApprovalMiddleware($context))->handle(
        translatingPrompt(Decisions::from([
            '*' => AiDecision::approve(),
            'call-1' => AiDecision::approve(),
        ])),
        function () use ($context, &$seen): string {
            $seen = [
                'wildcard' => $context->allows('*'),
                'unrelated' => $context->allows('call-unrelated'),
                'named' => $context->allows('call-1'),
            ];

            return 'done';
        },
    );

    expect($seen['wildcard'])->toBeFalse()
        ->and($seen['unrelated'])->toBeFalse()
        ->and($seen['named'])->toBeTrue();
});

it('pushes no frame at all when the prompt carries no decisions', function (): void {
    // "No frame" and "an empty frame" are different, and only an OUTER frame can tell them apart:
    // an empty inner frame would mask the outer approval, so a run that legitimately resumed would
    // find its approved call disallowed. Asserting allows()===false on a bare context cannot see
    // the difference; this can.
    $context = new ApprovalExecutionContext;
    $context->push(ApprovedToolCalls::of(['call-outer']));
    $seen = null;

    (new VerdictApprovalMiddleware($context))->handle(
        translatingPrompt(null),
        function () use ($context, &$seen): array {
            $seen = ['outer' => $context->allows('call-outer'), 'unrelated' => $context->allows('call-1')];

            return $seen;
        },
    );

    expect($seen['outer'])->toBeTrue()
        ->and($seen['unrelated'])->toBeFalse();

    $context->pop();
});

it('unwinds its frame when the downstream callback throws', function (): void {
    // The middleware pops in its catch before rethrowing. If a translation refactor loses that,
    // a failed resumption leaves an approval frame on the stack for the rest of the request —
    // visible to unrelated later checks, which is an authorization leak rather than a tidiness bug.
    $context = new ApprovalExecutionContext;

    expect(fn () => (new VerdictApprovalMiddleware($context))->handle(
        translatingPrompt(Decisions::from(['call-1' => AiDecision::approve()])),
        fn (): never => throw new RuntimeException('downstream boom'),
    ))->toThrow(RuntimeException::class, 'downstream boom');

    expect($context->allows('call-1'))->toBeFalse();
});

it('throws on an edited decision rather than translating it', function (Decisions $decisions): void {
    // Unlike the wildcard, an edit() is distinguishable and appears in no resume flow. It asks the
    // framework to execute *different* arguments (TextGenerationLoop substitutes Decision::$arguments)
    // while a Verdict receipt binds the original proposal, so a silent drop would hide a real
    // incompatibility. It is refused loudly: the downstream tool run never begins, and an edit
    // anywhere in the map refuses the whole translation rather than partially approving the rest.
    $context = new ApprovalExecutionContext;
    $ran = false;

    expect(fn () => (new VerdictApprovalMiddleware($context))->handle(
        translatingPrompt($decisions),
        function () use (&$ran): string {
            $ran = true;

            return 'done';
        },
    ))->toThrow(UnsupportedApprovalDecision::class);

    expect($ran)->toBeFalse()
        ->and($context->allows('call-1'))->toBeFalse();
})->with([
    'edit alone' => fn (): Decisions => Decisions::from(['call-1' => AiDecision::edit(['order_id' => 7])]),
    'edit mixed with an explicit approval' => fn (): Decisions => Decisions::from([
        'call-1' => AiDecision::approve(),
        'call-2' => AiDecision::edit(['order_id' => 7]),
    ]),
]);
