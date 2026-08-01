<?php

declare(strict_types=1);

namespace Workbench\App\Storefront;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;

final class Customer implements AuthenticatableContract
{
    use Authenticatable;

    public function __construct(
        public int $id,
        public string $name,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }
}
