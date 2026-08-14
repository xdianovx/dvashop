<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final readonly class PublicVehicleCatalogVisibility
{
    public function __construct(
        private StorefrontProductAvailability $availability,
    ) {}

    public function makes(Builder|Relation $query): Builder|Relation
    {
        return $query
            ->active()
            ->whereHas('models', fn (Builder $modelQuery): Builder => $modelQuery
                ->active()
                ->whereHas('generations', fn (Builder $generationQuery): Builder => $this->generationWithPublicProduct($generationQuery)));
    }

    public function models(Builder|Relation $query): Builder|Relation
    {
        return $query
            ->active()
            ->whereHas('make', fn (Builder $makeQuery): Builder => $makeQuery->active())
            ->whereHas('generations', fn (Builder $generationQuery): Builder => $this->generationWithPublicProduct($generationQuery));
    }

    public function generations(Builder|Relation $query): Builder|Relation
    {
        return $this->generationWithPublicProduct($query)
            ->whereHas('model', fn (Builder $modelQuery): Builder => $modelQuery
                ->active()
                ->whereHas('make', fn (Builder $makeQuery): Builder => $makeQuery->active()));
    }

    private function generationWithPublicProduct(Builder|Relation $query): Builder|Relation
    {
        return $query
            ->active()
            ->whereHas('fitments', fn (Builder $fitmentQuery): Builder => $fitmentQuery
                ->whereHas('product', fn (Builder $productQuery): Builder => $this->availability
                    ->products($productQuery)
                    ->whereHas('variants', fn (Builder $variantQuery): Builder => $this->availability->variants($variantQuery))));
    }
}
