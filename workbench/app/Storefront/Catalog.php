<?php

declare(strict_types=1);

namespace Workbench\App\Storefront;

use Fissible\Verdict\Exceptions\TargetNotResolvable;

final readonly class Catalog
{
    /** @var array<int, Order> */
    private array $orders;

    public function __construct()
    {
        $this->orders = [
            1001 => new Order(1001, 91, 'Mechanical keyboard', 'shipped', 7),
            1002 => new Order(1002, 72, 'Canvas weekender bag', 'processing', 4),
        ];
    }

    public function order(int $id): Order
    {
        return $this->orders[$id] ?? throw TargetNotResolvable::make();
    }
}
