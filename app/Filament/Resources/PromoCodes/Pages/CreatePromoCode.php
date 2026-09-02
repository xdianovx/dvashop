<?php

namespace App\Filament\Resources\PromoCodes\Pages;

use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Services\Promotions\PromoCodeAdminService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePromoCode extends CreateRecord
{
    protected static string $resource = PromoCodeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(PromoCodeAdminService::class)->create(auth()->user(), $data);
    }
}
