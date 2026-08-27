<?php

declare(strict_types=1);

/**
 * @contract-behaviour live-observer-response-taxonomy
 *
 * @contract-fidelity constructed
 *
 * @contract-consequence Live evaluation must classify Laravel AI's response variants consistently instead of recording the wrong trial outcome.
 */
use Fissible\Verdict\Evaluation\LiveAgentObserver;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;

it('keeps the Laravel AI response taxonomy named by the live observer', function (): void {
    $source = (string) file_get_contents((new ReflectionClass(LiveAgentObserver::class))->getFileName());

    expect(str_contains($source, AgentResponse::class))->toBeTrue()
        ->and(str_contains($source, StreamableAgentResponse::class))->toBeTrue()
        ->and(str_contains($source, StructuredAgentResponse::class))->toBeTrue();
});
