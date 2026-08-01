<?php

declare(strict_types=1);

namespace Workbench\App\Storefront;

use Illuminate\Auth\Access\Response;

final class OrderPolicy
{
    public function view(Customer $customer, Order $order): Response
    {
        return $customer->id === $order->customerId
            ? Response::allow('Customer owns this order.')
            : Response::deny("Order belongs to customer {$order->customerId}.");
    }

    public function cancel(Customer $customer, Order $order): Response
    {
        if ($customer->id !== $order->customerId) {
            return Response::deny("Order belongs to customer {$order->customerId}.");
        }

        return $order->status === 'processing'
            ? Response::allow('Customer owns an order that is still processing.')
            : Response::deny('Only processing orders may be cancelled.');
    }
}
