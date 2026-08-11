<?php

declare(strict_types=1);

use Fissible\AttestLaravel\Support\AttestRegistry;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

it('resolves the attest registry once the package is installed', function (): void {
    expect(app(AttestRegistry::class))->toBeInstanceOf(AttestRegistry::class);
});
