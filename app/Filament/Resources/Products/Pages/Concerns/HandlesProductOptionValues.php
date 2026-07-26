<?php

namespace App\Filament\Resources\Products\Pages\Concerns;

use App\Models\Product;
use App\Models\ProductVariant;

trait HandlesProductOptionValues
{
    protected function finishProductOptionSave(): void
    {
        /** @var Product $product */
        $product = $this->record->refresh();

        $product->variants()
            ->with('optionValues.group')
            ->each(function (ProductVariant $variant): void {
                if ($variant->optionValues->isNotEmpty()) {
                    $variant->syncOptionsSnapshotFromValues();
                }
            });

        $product->unsetRelation('variants');
    }
}
