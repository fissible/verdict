<?php

declare(strict_types=1);

/**
 * @contract-behaviour streaming-response-semantics
 *
 * @contract-fidelity constructed
 *
 * @contract-consequence Verdict's streaming gates and provenance wrappers rely on response work remaining lazy until iteration.
 */
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;

it('defers its generator until a streamable response is iterated', function (): void {
    $started = false;
    $response = new StreamableAgentResponse('stream-1', function () use (&$started): Generator {
        $started = true;
        yield from [];
    }, new Meta);

    expect($started)->toBeFalse();
    iterator_to_array($response);
    expect($started)->toBeTrue();
});
