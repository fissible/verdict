<?php

declare(strict_types=1);

use Fissible\Verdict\Context\StructuredRedactor;

it('redacts exact and wildcard structured fields without changing sibling values', function (): void {
    $result = (new StructuredRedactor([
        'email',
        'orders.*.payment_token',
    ]))->transform([
        'first_name' => 'Avery',
        'email' => 'avery@example.com',
        'orders' => [
            ['number' => 1001, 'payment_token' => 'token-a'],
            ['number' => 1002, 'payment_token' => 'token-b'],
        ],
    ]);

    expect($result->payload)->toBe([
        'first_name' => 'Avery',
        'email' => '[REDACTED]',
        'orders' => [
            ['number' => 1001, 'payment_token' => '[REDACTED]'],
            ['number' => 1002, 'payment_token' => '[REDACTED]'],
        ],
    ])->and($result->transformedPaths)->toBe([
        'email',
        'orders.0.payment_token',
        'orders.1.payment_token',
    ]);
});

it('rejects an empty or ambiguous redaction configuration', function (array $paths): void {
    expect(fn (): StructuredRedactor => new StructuredRedactor($paths))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty paths' => [[]],
    'empty path' => [['']],
    'empty segment' => [['customer..email']],
]);
