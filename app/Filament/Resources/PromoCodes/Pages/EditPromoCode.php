<?php

namespace App\Filament\Resources\PromoCodes\Pages;

use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Models\PromoCode;
use App\Services\Promotions\PromoCodeAdminService;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPromoCode extends EditRecord
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

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PromoCode $record */
        return app(PromoCodeAdminService::class)->update(auth()->user(), $record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Архивировать')
                ->using(fn (PromoCode $record) => app(PromoCodeAdminService::class)->archive(auth()->user(), $record)),
            RestoreAction::make()
                ->using(fn (PromoCode $record) => app(PromoCodeAdminService::class)->restore(auth()->user(), $record)),
        ];
    }
}
