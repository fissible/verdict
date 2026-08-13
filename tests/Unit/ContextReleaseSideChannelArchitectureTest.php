<?php

declare(strict_types=1);

use Fissible\Verdict\Context\ContextReleaseManager;
use Fissible\Verdict\Context\ContextReleaseResult;

/**
 * A projected, redacted payload must leave ContextReleaseManager only through the
 * ContextReleaseResult it returns.
 *
 * That property is why an evidence-write failure inside release() is safe: the caller never
 * receives the payload, so the throw genuinely prevents the release rather than misreporting
 * one that already happened. It is the reason ContextReleaseManager is not an instance of the
 * hazard ADR 0007's Update (#149) and issue #153 describe. Add a notification, callback, or
 * any other emission to this path and that reasoning silently stops holding, so it is asserted
 * here rather than left to a reviewer to rediscover.
 */
it('emits a released payload only through the returned result', function (): void {
    $emitters = [
        'event(',
        'Event::',
        'broadcast(',
        'dispatch(',
        '->dispatch(',
        'Http::',
        'Mail::',
        'Notification::',
        'Queue::',
        'Bus::',
        'Log::',
        'logger(',
        'file_put_contents(',
    ];

    $source = file_get_contents((string) (new ReflectionClass(ContextReleaseManager::class))->getFileName());
    $found = array_values(array_filter(
        $emitters,
        static fn (string $emitter): bool => str_contains((string) $source, $emitter),
    ));

    expect($found)->toBe([]);
});

it('returns released payloads in an inert value object', function (): void {
    $result = new ReflectionClass(ContextReleaseResult::class);

    // A value object cannot transmit what it only holds. Behavior added here would be a side
    // channel the source scan above cannot see, because it would live in a different file.
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $result->getMethods(),
    );

    sort($methods);

    expect($result->isReadOnly())->toBeTrue()
        ->and($methods)->toBe(['__construct', 'denied', 'permitted'])
        ->and($result->getMethod('__construct')->isPrivate())->toBeTrue();
});
