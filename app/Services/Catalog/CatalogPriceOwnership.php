<?php

namespace App\Services\Catalog;

use App\Models\CatalogConfiguration;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CatalogPriceOwnership
{
    public static function options(): array
    {
        return ['unknown' => 'Историческая (источник неизвестен)', 'inherited' => 'Общая цена', 'individual' => 'Индивидуальная цена'];
    }

    public function fingerprint(Product $product): string
    {
        return hash('sha256', json_encode([
            DB::table('products')->where('id', $product->id)->first(['price', 'old_price', 'price_mode', 'part_type_id']),
            DB::table('product_variants')->where('product_id', $product->id)->orderBy('id')->get(['id', 'price', 'old_price', 'price_mode', 'is_default']),
        ], JSON_THROW_ON_ERROR));
    }

    public function prepare(Product|ProductVariant $record): void
    {
        if (! app(CatalogPricingSchema::class)->ready()) {
            return;
        }
        if ($record instanceof ProductVariant && ! $record->exists) {
            $owner = Product::query()->find($record->product_id);
            if ($owner && ($owner->import_source === 'catalog' || $owner->configuration_managed)) {
                throw ValidationException::withMessages(['configuration' => 'Импортные варианты создаются только синхронизацией CSV.']);
            }
        }
        if ($record->exists && $record->isDirty(['price', 'price_mode'])) {
            $product = $record instanceof Product
                ? $record
                : Product::query()->find($record->product_id);
            if ($product && ($product->import_source === 'catalog' || $product->configuration_managed)) {
                throw ValidationException::withMessages(['price' => 'Изменяйте цены импортируемых товаров через «Массовое управление товарами». Следующий импорт восстановит цены из CSV.']);
            }
        }
        $mode = $record->price_mode ?? 'unknown';
        if (! array_key_exists($mode, self::options())) {
            throw ValidationException::withMessages(['price_mode' => 'Недопустимый режим цены.']);
        }
        if ($record->exists && $record->isDirty('price') && (! $record->isDirty('price_mode') || $mode !== 'inherited')) {
            $mode = 'individual';
        }
        if ($mode === 'inherited' && (! $record->exists || $record->isDirty(['price_mode', 'part_type_id', 'product_id']))) {
            $price = $record instanceof Product
                ? PartType::query()->whereKey($record->part_type_id)->value('base_price')
                : Product::query()->whereKey($record->product_id)->value('price');
            $matrix = app(CatalogConfigurations::class);
            if ($record instanceof Product && $matrix->rows($record->part_type_id)->isNotEmpty()) {
                $price = $matrix->basePrice($record->part_type_id);
            } elseif ($record instanceof ProductVariant && $record->catalog_configuration_id) {
                $price = CatalogConfiguration::whereKey($record->catalog_configuration_id)->value('price');
            }
            if ($price === null || ($record instanceof Product && ! $record->isAutoPart())) {
                throw ValidationException::withMessages(['price_mode' => 'Для наследования сначала задайте общую цену типа / товара.']);
            }
            $record->price = $price;
        }
        $record->price_mode = $mode;
    }

    public function syncDependentVariants(Product $product): void
    {
        if ($product->import_source === 'catalog' || $product->configuration_managed) {
            return; // Matrix variants must never inherit one shared product amount.
        }
        if ($product->wasChanged(['price', 'price_mode'])) {
            if ($product->price === null && DB::table('product_variants')->where('product_id', $product->id)->where('price_mode', 'inherited')->exists()) {
                throw ValidationException::withMessages(['price' => 'Нельзя очистить цену товара с зависимыми вариантами.']);
            }
            DB::table('product_variants')->where('product_id', $product->id)->where('price_mode', 'inherited')
                ->when(app(CatalogConfigurations::class)->ready(), fn ($q) => $q->whereNull('catalog_configuration_id'))
                ->update(['price' => $product->price, 'updated_at' => now()]);
        }
    }
}
