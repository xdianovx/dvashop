<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;

class OrderInventoryService
{
    public function restoreForCancellation(Order $order): void
    {
        $items = OrderItem::query()
            ->where('order_id', $order->getKey())
            ->where('stock_was_decremented', true)
            ->whereNull('stock_restored_at')
            ->orderBy('product_variant_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $itemsByVariant = $items
            ->filter(fn (OrderItem $item): bool => $item->product_variant_id !== null)
            ->groupBy(fn (OrderItem $item): int => (int) $item->product_variant_id);
        $variantIds = $itemsByVariant->keys()->map(fn (mixed $id): int => (int) $id)->sort()->values();

        if ($variantIds->isEmpty()) {
            return;
        }

        $variants = ProductVariant::query()
            ->whereKey($variantIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (ProductVariant $variant): int => (int) $variant->getKey());
        $restoredAt = now();

        foreach ($variantIds as $variantId) {
            if (! $variants->has($variantId)) {
                continue;
            }

            $variantItems = $itemsByVariant->get($variantId, collect());
            $quantity = (int) $variantItems->sum('quantity');

            ProductVariant::query()->whereKey($variantId)->increment('stock_quantity', $quantity);
            OrderItem::query()
                ->whereKey($variantItems->modelKeys())
                ->whereNull('stock_restored_at')
                ->update(['stock_restored_at' => $restoredAt]);
        }
    }
}
