<?php

declare(strict_types=1);

use Fissible\Verdict\Context\FieldProjector;

it('projects only explicitly allowed structured fields including wildcard children', function (): void {
    $payload = [
        'first_name' => 'Avery',
        'locale' => 'en-US',
        'email' => 'avery@example.com',
        'ssn' => '111-22-3333',
        'medical_notes' => 'Do not release',
        'orders' => [
            [
                'number' => 1001,
                'status' => 'shipped',
                'payment_token' => 'secret-a',
            ],
            [
                'number' => 1002,
                'status' => 'processing',
                'payment_token' => 'secret-b',
            ],
        ],
    ];

    $projected = (new FieldProjector)->project($payload, [
        'first_name',
        'locale',
        'orders.*.number',
        'orders.*.status',
    ]);

    expect($projected)->toBe([
        'first_name' => 'Avery',
        'locale' => 'en-US',
        'orders' => [
            ['number' => 1001, 'status' => 'shipped'],
            ['number' => 1002, 'status' => 'processing'],
        ],
    ])->not->toHaveKey('email')
        ->not->toHaveKey('ssn')
        ->not->toHaveKey('medical_notes');
});

it('does not release new or unmatched fields implicitly', function (): void {
    $projector = new FieldProjector;

    expect($projector->project([
        'first_name' => 'Avery',
        'new_sensitive_column' => 'must remain private',
    ], ['first_name', 'missing_field']))->toBe([
        'first_name' => 'Avery',
    ]);
});

it('requires a non-empty valid field allowlist', function (array $paths): void {
    expect(fn (): array => (new FieldProjector)->project(['name' => 'Avery'], $paths))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty allowlist' => [[]],
    'empty path' => [['']],
    'empty segment' => [['customer..name']],
]);
