<?php

namespace App\Filament\Resources\ProductOptionGroups\Pages;

use App\Filament\Resources\ProductOptionGroups\ProductOptionGroupResource;
use App\Models\ProductOptionGroup;
use App\Models\User;
use App\Services\Catalog\ProductOptionAdminService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProductOptionGroup extends EditRecord
{
    protected static string $resource = ProductOptionGroupResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();
        /** @var ProductOptionGroup $record */

        return app(ProductOptionAdminService::class)->updateGroup($actor, $record, $data);
    }
}
