<?php

namespace App\Services\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Lock order: this singleton, then domain rows. Always called inside a transaction. */
final class CatalogPriceGuard
{
    public function lock(): ?object
    {
        // Allows deployment before the additive migration and historical migration replay.
        if (! app(CatalogPricingSchema::class)->ready()) {
            return null;
        }

        return DB::table('catalog_price_locks')->where('id', 1)->lockForUpdate()->first();
    }

    public function saving(Model $record): void
    {
        if (! $record->exists || $record->isDirty(['price', 'old_price', 'price_mode', 'part_type_id', 'product_type', 'product_id', 'status', 'is_default', 'deleted_at'])) {
            $this->assertWritable();
        }
    }

    public function assertWritable(): void
    {
        if ($this->lock()?->operation_id !== null) {
            throw ValidationException::withMessages(['price' => 'Выполняется массовое изменение цен. Дождитесь завершения или продолжите прерванную операцию.']);
        }
    }

    public function busy(): bool
    {
        return app(CatalogPricingSchema::class)->ready()
            && DB::table('catalog_price_locks')->whereNotNull('operation_id')->exists();
    }
}
