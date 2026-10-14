<?php

namespace App\Services\Import;

use App\Models\PartType;

final class CatalogImportPriceResolver
{
    /** @return array{price: string, old_price: string|null}|null */
    public function resolve(PartType|string $partType): ?array
    {
        // Query current persisted values: callers may hold a cached PartType.
        $query = PartType::query();
        $type = ($partType instanceof PartType
            ? $query->whereKey($partType->getKey())
            : $query->where('full_slug', $partType))->first(['base_price', 'base_old_price']);

        return $type?->base_price === null ? null : [
            'price' => $type->base_price, 'old_price' => $type->base_old_price,
        ];
    }
}
