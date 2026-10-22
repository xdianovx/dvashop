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
        if (! $record->exists || $record->isDirty(['price', 'old_price', 'price_mode', 'part_type_id', 'product_type', 'product_id', 'status', 'is_default', 'is_active', 'options', 'catalog_configuration_id', 'publication_override', 'deleted_at'])) {
            $this->assertWritable();
        }
    }

    public function assertWritable(): void
    {
        $lock = $this->lock();
        if (($lock->safe_partial_run_id ?? null) !== null
            && (int) $lock->safe_partial_run_id !== CatalogImportContext::owner()) {
            throw ValidationException::withMessages(['price' => 'Каталог зарезервирован подтверждённым safe_partial.']);
        }
        if ($lock?->operation_id !== null || ($lock->catalog_action_id ?? null) !== null) {
            throw ValidationException::withMessages(['price' => 'Выполняется массовое изменение каталога. Дождитесь завершения или продолжите прерванную операцию.']);
        }
    }

    /** Existing catalog actions already validate their own reservation owner. */
    public function assertImportWritable(): void
    {
        $lock = $this->lock();
        if (($lock->safe_partial_run_id ?? null) !== null
            && (int) $lock->safe_partial_run_id !== CatalogImportContext::owner()) {
            throw ValidationException::withMessages(['price' => 'Каталог зарезервирован подтверждённым safe_partial.']);
        }
    }

    public function busy(): bool
    {
        return app(CatalogPricingSchema::class)->ready()
            && DB::table('catalog_price_locks')->where(fn ($q) => $q->whereNotNull('operation_id')->when(app(CatalogConfigurations::class)->ready(), fn ($q) => $q->orWhereNotNull('catalog_action_id')))->exists();
    }
}
