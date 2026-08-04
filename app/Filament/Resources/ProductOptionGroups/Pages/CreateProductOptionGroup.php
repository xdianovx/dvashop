<?php

namespace App\Filament\Resources\ProductOptionGroups\Pages;

use App\Filament\Resources\ProductOptionGroups\ProductOptionGroupResource;
use App\Models\User;
use App\Services\Catalog\ProductOptionAdminService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProductOptionGroup extends CreateRecord
{
    protected static string $resource = ProductOptionGroupResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        return app(ProductOptionAdminService::class)->createGroup($actor, $data);
    }
}
