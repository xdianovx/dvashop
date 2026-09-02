<?php

namespace App\Filament\Resources\PromoCodes\Pages;

use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Models\PromoCode;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPromoCode extends ViewRecord
{
    protected static string $resource = PromoCodeResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PromoCode $promo */
        $promo = $this->record;

        return [
            ...$data,
            'product_ids' => $promo->products()->pluck('products.id')->all(),
            'product_category_ids' => $promo->productCategories()->pluck('product_categories.id')->all(),
            'part_type_ids' => $promo->partTypes()->pluck('part_types.id')->all(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
