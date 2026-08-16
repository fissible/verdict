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
            // A second order the actor (72) also owns, deliberately distinct from 1002 in every
            // disclosed field so the authority/intent differential (#187) can assert on which
            // record was acted on rather than on the argument fingerprint, which is identical
            // across its two arms by construction.
            1003 => new Order(1003, 72, 'Wireless travel mouse', 'delivered', 2),
        ];
    }

    public function order(int $id): Order
    {
        return $this->orders[$id] ?? throw TargetNotResolvable::make();
    }
}
