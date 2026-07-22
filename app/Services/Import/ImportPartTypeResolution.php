<?php

namespace App\Services\Import;

use App\Models\PartType;
use App\Models\ProductCategory;

final readonly class ImportPartTypeResolution
{
    public function __construct(
        public PartType $partType,
        public ProductCategory $productCategory,
        public bool $usedFallback,
        public bool $wasCreated,
        public bool $wasRestored,
    ) {}
}
