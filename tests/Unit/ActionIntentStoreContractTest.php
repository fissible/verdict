<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\ActionIntentStore;

it('exposes no mutation beyond the write-once record', function (): void {
    $methods = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(ActionIntentStore::class))->getMethods(),
    );
    sort($methods);

    // An intent row is write-once by design: a mutable intent would be a weaker compliance
    // artifact, and an intent with no outcome referencing it IS the gap signal (#160). Any
    // update, delete, or status transition added to this contract defeats that design.
    expect($methods)->toBe(['find', 'record']);
});
