<?php

declare(strict_types=1);

namespace Workbench\App\Storefront;

final class ActionLog
{
    /** @var list<array{sequence: int, capability: string, resource: string, resource_id: int, result: string}> */
    private array $actions = [];

    public function record(string $capability, int $orderId): void
    {
        $this->actions[] = [
            'sequence' => count($this->actions) + 1,
            'capability' => $capability,
            'resource' => 'order',
            'resource_id' => $orderId,
            'result' => 'cancelled',
        ];
    }

    /** @return list<array{sequence: int, capability: string, resource: string, resource_id: int, result: string}> */
    public function all(): array
    {
        return $this->actions;
    }
}
