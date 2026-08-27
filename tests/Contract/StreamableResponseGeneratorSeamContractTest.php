<?php

declare(strict_types=1);

/**
 * @contract-behaviour streamable-response-generator-seam
 *
 * @contract-fidelity real
 *
 * @contract-consequence Verdict must wrap Laravel AI's real deferred generator to retain approval and provenance context during lazy iteration.
 */
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;

it('stores a closure generator on the real StreamableAgentResponse', function (): void {
    $response = new StreamableAgentResponse('stream-1', static function (): Generator {
        yield from [];
    }, new Meta);
    $generator = (new ReflectionProperty(StreamableAgentResponse::class, 'generator'))->getValue($response);

    expect($generator instanceof Closure)->toBeTrue();
});
