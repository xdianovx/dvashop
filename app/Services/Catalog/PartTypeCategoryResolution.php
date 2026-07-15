<?php

namespace App\Services\Catalog;

use App\Models\ProductCategory;

final readonly class PartTypeCategoryResolution
{
    public function __construct(
        public ProductCategory $category,
        public bool $usedFallback,
    ) {}
}
