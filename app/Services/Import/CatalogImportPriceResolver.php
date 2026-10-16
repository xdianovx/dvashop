<?php

namespace App\Services\Import;

use App\Models\PartType;
use App\Services\Catalog\CatalogConfigurations;

final class CatalogImportPriceResolver
{
    /** @return array{price: string, old_price: string|null}|null */
    public function resolve(PartType|string $partType): ?array
    {
        $typeId = $partType instanceof PartType ? $partType->id : PartType::where('full_slug', $partType)->value('id');
        $rows = app(CatalogConfigurations::class)->rows($typeId);
        if ($rows->isNotEmpty()) {
            $price = app(CatalogConfigurations::class)->basePrice($typeId);

            return ['price' => $price, 'old_price' => null];
        }
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
