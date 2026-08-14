<?php

namespace App\Services;

use App\Enums\ProductStatus;
use App\Enums\StockStatus;
use App\Models\Product;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class StorefrontProductAvailability
{
    public function products(Builder $query): Builder
    {
        return $query
            ->active()
            ->where(fn (Builder $categoryQuery): Builder => $categoryQuery
                ->whereNull('product_category_id')
                ->orWhereHas('category', fn (Builder $relationQuery): Builder => $relationQuery->where('is_active', true)))
            ->where(fn (Builder $partTypeQuery): Builder => $partTypeQuery
                ->whereNull('part_type_id')
                ->orWhereHas('partType', fn (Builder $relationQuery): Builder => $relationQuery->where('is_active', true)));
    }

    public function variants(Builder|Relation $query): Builder|Relation
    {
        return $query
            ->where('is_active', true)
            ->whereDoesntHave('optionValues', fn (Builder $valueQuery): Builder => $valueQuery
                ->where('is_active', false)
                ->orWhereHas('group', fn (Builder $groupQuery): Builder => $groupQuery->where('is_active', false)))
            ->whereHas('product', fn (Builder $productQuery): Builder => $this->products($productQuery));
    }

    public function isPurchasable(ProductVariant $variant, int $quantity = 1): bool
    {
        if (! $this->isPubliclyAvailable($variant) || ! $this->hasSellablePrice($variant)) {
            return false;
        }

        if ($variant->stock_status === StockStatus::OutOfStock) {
            return false;
        }

        return $variant->stock_status !== StockStatus::InStock
            || $variant->stock_quantity === null
            || $variant->stock_quantity >= max(1, $quantity);
    }

    public function effectivePrice(ProductVariant $variant): float
    {
        $variant->loadMissing('product');

        return round((float) ($variant->price ?? $variant->product?->price ?? 0), 2);
    }

    public function hasSellablePrice(ProductVariant $variant): bool
    {
        return $this->effectivePrice($variant) > 0;
    }

    public function isPubliclyAvailable(ProductVariant $variant): bool
    {
        $variant->loadMissing([
            'product.category',
            'product.partType',
            'optionValues.group',
        ]);

        $product = $variant->product;

        if (! $variant->is_active
            || ! $product instanceof Product
            || $product->trashed()
            || $product->status !== ProductStatus::Active) {
            return false;
        }

        if ($product->product_category_id !== null
            && (! $product->category || ! $product->category->is_active)) {
            return false;
        }

        if ($product->part_type_id !== null
            && (! $product->partType || ! $product->partType->is_active)) {
            return false;
        }

        return ! $variant->optionValues->contains(
            fn (ProductOptionValue $value): bool => ! $value->is_active || ! $value->group || ! $value->group->is_active
        );
    }
}
