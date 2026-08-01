<?php

declare(strict_types=1);

namespace Workbench\App\Storefront;

final class ActionLog
{
    /** @var list<array{capability: string, order_id: int}> */
    private array $actions = [];

    public function record(string $capability, int $orderId): void
    {
        $this->actions[] = [
            'capability' => $capability,
            'order_id' => $orderId,
        ];
    }

    /** @return list<array{capability: string, order_id: int}> */
    public function all(): array
    {
        return $this->actions;
    }
}
