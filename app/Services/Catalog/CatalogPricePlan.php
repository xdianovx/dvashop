<?php

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/** The same fields and classification rules are used by preview and execution. */
final class CatalogPricePlan
{
    public function query(Builder $query, bool $lock = false): Builder
    {
        return $query->select(['id', 'part_type_id', 'price', 'old_price', 'last_import_price', 'price_mode', 'status'])
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->with(['variants' => fn ($q) => $q->select(['id', 'product_id', 'price', 'old_price', 'price_mode', 'is_default'])
                ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())]);
    }

    public function fingerprint(Product $product): string
    {
        return hash('sha256', json_encode($product->toArray(), JSON_THROW_ON_ERROR));
    }

    public function classification(Product $product, string $price): string
    {
        if ($product->price_mode === 'individual' || $product->variants->contains(fn ($v) => $v->price_mode === 'individual')) {
            return 'individual';
        }
        if ($product->price === null || $product->variants->isEmpty() || $product->variants->contains(fn ($v) => $v->price === null)) {
            return 'insufficient';
        }
        if ($product->variants->contains(fn ($v) => $v->price !== $product->price)) {
            return 'ambiguous';
        }

        return $product->price === $price ? 'matching' : 'deviation';
    }

    public function adoption(Product $product, string $price, bool $deviations, array $overrides, bool $adoptMatching = true): ?string
    {
        if (array_key_exists($product->id, $overrides) && ! $overrides[$product->id]) {
            return null;
        }
        if ($this->classification($product, $price) === 'matching') {
            return ($overrides[$product->id] ?? $adoptMatching) ? 'inherited' : null;
        }

        // Only unknown ownership is classified; explicit individual/inherited modes are never erased.
        return ($overrides[$product->id] ?? $deviations) ? 'individual' : null;
    }
}
