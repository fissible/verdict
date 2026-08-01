<?php

declare(strict_types=1);

namespace Workbench\App\Storefront;

final readonly class Order
{
    public function __construct(
        public int $id,
        public int $customerId,
        public string $item,
        public string $status,
        public int $version,
    ) {}

    /** @return array{id: int, customer_id: int, item: string, status: string} */
    public function disclosure(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customerId,
            'item' => $this->item,
            'status' => $this->status,
        ];
    }
}
