<?php

namespace App\Services\Catalog;

use App\Models\CatalogConfiguration;
use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CatalogConfigurations
{
    private const REQUIRED_TABLES = ['catalog_configurations', 'catalog_actions', 'catalog_action_items', 'catalog_price_locks', 'catalog_matrix_sources', 'catalog_matrix_price_history'];

    private ?Collection $loadedRows = null;

    private array $loadedTypeIds = [];

    /** @var array<int, bool> */
    private array $typeHistory = [];

    public function prime(array $typeIds): void
    {
        $this->loadedTypeIds = $typeIds;
        $this->loadedRows = CatalogConfiguration::with('template.items.group', 'template.items.value')->whereIn('part_type_id', $typeIds)->where('is_active', true)->orderBy('position')->orderBy('id')->get();
    }

    public function clear(): void
    {
        $this->loadedRows = null;
        $this->typeHistory = [];
    }

    public function ready(): bool
    {
        return app(CatalogPricingSchema::class)->has('catalog_configurations');
    }

    public function assertReady(): void
    {
        foreach (self::REQUIRED_TABLES as $table) {
            if (! app(CatalogPricingSchema::class)->has($table)) {
                throw ValidationException::withMessages(['configuration' => 'Отсутствует таблица '.$table.'. Сначала отдельно примените миграции и установите исходный CSV. Импорт остановлен.']);
            }
        }
    }

    public function rows(?int $typeId): Collection
    {
        if ($this->loadedRows !== null && in_array($typeId, $this->loadedTypeIds)) {
            return $this->loadedRows->where('part_type_id', $typeId)->values();
        }

        return $this->ready() && $typeId !== null
            ? CatalogConfiguration::where('part_type_id', $typeId)->where('is_active', true)->orderBy('position')->orderBy('id')->get()
            : collect();
    }

    /** Includes inactive CSV rows, so a fully retired type cannot silently fall back to 24 old variants. */
    public function hasHistory(?int $typeId): bool
    {
        if (! $this->ready() || $typeId === null) {
            return false;
        }

        return $this->typeHistory[$typeId] ??= CatalogConfiguration::where('part_type_id', $typeId)->exists();
    }

    /** Never retire variants of a product that switched to an explicit custom template. */
    public function canRetireManagedProduct(Product $product): bool
    {
        if (! $product->configuration_managed || ! $this->hasHistory($product->part_type_id)) {
            return false;
        }
        if ($product->product_option_template_id === null) {
            return true;
        }

        $template = $product->optionTemplate;

        return $template?->slug === 'default_auto_part'
            || (str_starts_with((string) $template?->slug, 'matrix_')
                && (int) $template?->part_type_id === (int) $product->part_type_id);
    }

    public function basePrice(?int $typeId): ?string
    {
        return $this->rows($typeId)->sortBy(fn ($row) => (int) str_replace('.', '', $row->price))->first()?->price;
    }

    public function template(?int $typeId): ?ProductOptionTemplate
    {
        return $this->rows($typeId)->first()?->template;
    }

    /** Explicit, reviewed CSV installation, separate from migrations and product synchronization. */
    public function install(string $path, ?string $confirmation = null, ?int $authorizedImportId = null): array
    {
        $this->assertReady();
        $this->clear();
        $sourceSha256 = is_file($path) ? hash_file('sha256', $path) : false;
        $rows = app(CatalogConfigurationSource::class)->read($path);
        if (! is_string($sourceSha256) || ! hash_equals($sourceSha256, (string) hash_file('sha256', $path))) {
            throw ValidationException::withMessages(['configuration' => 'Невозможно проверить SHA-256 исходного CSV.']);
        }

        return DB::transaction(function () use ($rows, $confirmation, $sourceSha256, $authorizedImportId): array {
            app(CatalogPriceGuard::class)->assertWritable();
            if (DB::table('import_runs')->whereIn('status', ['running', 'running_rows', 'paused'])
                ->when($authorizedImportId !== null, fn ($q) => $q->where('id', '!=', $authorizedImportId))->exists()) {
                throw ValidationException::withMessages(['configuration' => 'Сначала завершите импорт.']);
            }
            $installation = app(CatalogConfigurationInstallation::class);
            $review = $installation->preview($rows);
            $isolation = app(CatalogImportProtections::class)->assertInstallation($rows);
            if ($authorizedImportId !== null) {
                $run = ImportRun::whereKey($authorizedImportId)->lockForUpdate()->firstOrFail();
                $expectedHash = in_array($run->import_mode, ['full', 'safe_partial'], true) ? $run->configuration_hash : $run->file_hash;
                if (! is_string($expectedHash) || ! hash_equals($expectedHash, $sourceSha256)) {
                    throw ValidationException::withMessages(['configurationReview' => 'CSV изменился после загрузки.']);
                }
                $confirmation = $run->import_mode === 'safe_partial'
                    ? $review['confirmation']
                    : $installation->assertImportConfirmation($run, $installation->bindReview($run, $review, $sourceSha256));
                if ($run->import_mode === 'safe_partial') {
                    app(\App\Services\Import\CatalogSafePartialImport::class)->assertInstallationOwner($run);
                }
            }
            if (($review['requires_confirmation'] && $confirmation === null)
                || ($confirmation !== null && ! hash_equals($review['confirmation'], $confirmation))) {
                throw ValidationException::withMessages(['configuration' => 'Изменён состав или цены CSV. Проверьте предпросмотр и подтвердите его актуальным кодом. После установки отдельная синхронизация приведёт ВСЕ текущие цены импортных товаров к CSV, с сохранением истории.']);
            }
            if ($isolation['equivalent']) {
                DB::table('catalog_matrix_sources')->updateOrInsert(['id' => 1], [
                    'sha256' => $sourceSha256, 'source_rows' => count($rows), 'installed_at' => now(),
                ]);
                $this->clear();
                return ['review' => $review, 'source_rows' => count($rows), 'source_sha256' => $sourceSha256,
                    'created_configurations' => 0, 'warnings' => [], 'structures_unchanged' => true];
            }
            $tree = app(PartTypeTreeService::class);
            $definitions = app(PartTypeDefinitionRegistry::class)->flattened($tree);
            $created = 0;
            $targets = [];
            foreach ($rows as $row) {
                foreach (CatalogConfigurationSource::targetPaths($row) as $target) {
                    $targets[$target][] = $row;
                }
            }
            foreach ($targets as $target => $groupRows) {
                $type = PartType::where('full_slug', $target)->first();
                if (! $type) {
                    $def = $definitions[$target];
                    $type = new PartType;
                    $type->forceFill(['title' => $def['title'], 'parent_id' => $def['parent_full_slug'] ? PartType::where('full_slug', $def['parent_full_slug'])->firstOrFail()->id : null,
                        'position' => $def['position'], 'is_active' => true]);
                    $tree->save($type);
                }
                $slug = 'matrix_'.str_replace('/', '_', $target);
                if (ProductOptionTemplate::where('part_type_id', $type->id)->where('is_default', true)->where('slug', '!=', $slug)->exists()) {
                    throw ValidationException::withMessages(['configuration' => 'У типа '.$target.' уже есть пользовательский шаблон. Требуется отдельное решение.']);
                }
                $template = ProductOptionTemplate::firstOrCreate(['slug' => $slug], ['title' => 'Конфигурации: '.$type->full_title,
                    'part_type_id' => $type->id, 'applies_to' => 'auto_part', 'is_active' => true, 'is_default' => true, 'position' => 0]);
                foreach ($groupRows as $position => $row) {
                    foreach ($row['options'] as $code => $valueCode) {
                        $group = ProductOptionGroup::where('code', $code)->firstOrFail();
                        $title = array_search($valueCode, CatalogConfigurationSource::VALUES[$code], true);
                        $value = ProductOptionValue::firstOrCreate(['product_option_group_id' => $group->id, 'code' => $valueCode],
                            ['slug' => $valueCode, 'title' => $title, 'position' => array_search($valueCode, array_values(CatalogConfigurationSource::VALUES[$code]), true) * 10, 'is_active' => true, 'is_default' => false]);
                        $template->items()->firstOrCreate(['product_option_group_id' => $group->id, 'product_option_value_id' => $value->id], ['position' => $group->position]);
                    }
                    $configuration = CatalogConfiguration::firstOrCreate(['part_type_id' => $type->id, 'signature' => $row['signature']],
                        ['product_option_template_id' => $template->id, 'options' => $row['options'], 'price' => $row['price'], 'position' => $position]);
                    $created += $configuration->wasRecentlyCreated ? 1 : 0;
                    $configuration->fill(['is_active' => true, 'price' => $row['price'], 'options' => $row['options'],
                        'position' => $position, 'product_option_template_id' => $template->id]);
                    if ($configuration->isDirty()) {
                        $configuration->save();
                    }
                }
            }

            CatalogConfiguration::whereIn('id', array_column($review['retired'], 'id'))->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('catalog_matrix_sources')->updateOrInsert(['id' => 1], [
                'sha256' => $sourceSha256, 'source_rows' => count($rows), 'installed_at' => now(),
            ]);
            $this->clear();
            return ['review' => $review, 'source_rows' => count($rows), 'source_sha256' => $sourceSha256,
                'created_configurations' => $created,
                'warnings' => ['Все управляемые CSV цены будут применены при синхронизации товаров с сохранением истории.']];
        });
    }

    public function combinations(ProductOptionTemplate $template): ?array
    {
        if (! str_starts_with((string) $template->slug, 'matrix_')) {
            return null;
        }
        $this->assertReady();
        $rows = CatalogConfiguration::where('product_option_template_id', $template->id)->where('is_active', true)->orderBy('position')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $values = $template->items()->with(['group', 'value.group'])->get()->filter(fn ($i) => $i->group?->is_active && $i->value?->is_active)
            ->mapWithKeys(fn ($i) => [$i->group->code.':'.$i->value->code => $i->value]);

        return $rows->map(function ($row) use ($values): array {
            $result = [];
            foreach ($row->options as $group => $value) {
                if (! isset($values[$group.':'.$value])) {
                    return [];
                }
                $result[] = $values[$group.':'.$value];
            }

            return $result;
        })->filter()->values()->all();
    }

    public function signature(ProductVariant $variant): string
    {
        return app(CatalogVariantDifference::class)->signature($variant);
    }

    /** Installed-matrix adapter; synchronize continues to use the same comparison. */
    public function changes(Product $product): array
    {
        $rows = $this->rows($product->part_type_id);

        return app(CatalogVariantDifference::class)->compare(
            $product, $rows, $rows->isEmpty() && $this->canRetireManagedProduct($product),
        );
    }

    /** Called inside a product transaction by import or the reserved, resumable operation. */
    public function synchronize(Product $product, string $operationRef = 'manual'): array
    {
        // Savepoints are used if a caller already holds a product/action transaction.
        // The audit and every price update must roll back together on any failure.
        return DB::transaction(fn (): array => $this->synchronizeWithinTransaction($product, $operationRef));
    }

    private function synchronizeWithinTransaction(Product $product, string $operationRef): array
    {
        app(CatalogPriceGuard::class)->assertImportWritable();
        if (app(CatalogImportProtections::class)->protects($product->id, lock: true)) {
            return ['changed' => false, 'protected' => 'Постоянная защита safe_partial', 'created_variants' => 0];
        }
        $this->assertReady();
        $selectedTemplate = $product->optionTemplate?->slug;
        if ($product->import_source !== 'catalog' && ! $product->configuration_managed
            && ! str_starts_with((string) $selectedTemplate, 'matrix_')) {
            return ['changed' => false, 'protected' => 'Товар не управляется CSV-матрицей.'];
        }
        $history = app(CatalogMatrixPriceHistory::class);
        $sourceSha256 = $history->sourceSha256();
        $rows = $this->rows($product->part_type_id);
        if ($rows->isEmpty()) {
            // A type with no remaining active CSV combinations must retire its managed offers.
            // Do not touch products that have never been adopted into matrix management.
            $difference = $this->changes($product);
            if (! $difference['changed']) {
                return $difference + ['skipped' => 1];
            }
            DB::table('product_variants')->where('product_id', $product->id)
                ->where('is_active', true)->update([
                    'is_active' => false,
                    'is_default' => false,
                    'configuration_auto_retired' => true,
                    'updated_at' => now(),
                ]);
            DB::table('product_variants')->where('product_id', $product->id)
                ->where('is_default', true)->update(['is_default' => false, 'updated_at' => now()]);

            return $difference;
        }
        $template = $rows->first()->template;
        if ($product->product_option_template_id !== null && ! in_array($product->optionTemplate?->slug, ['default_auto_part', $template->slug], true)) {
            return ['protected' => 1];
        }
        // Always lock fresh rows: relation caches from preview must not drive writes.
        $variants = $product->variants()->with('optionValues.group')->orderBy('id')->lockForUpdate()->get();
        $product->setRelation('variants', $variants);
        $difference = $this->changes($product);
        if (! $difference['changed']) { return $difference + ['retained_variants' => $rows->count()]; }
        $bySignature = $variants->groupBy(fn ($v) => $this->signature($v));
        foreach ($rows as $row) {
            if (($bySignature->get($row->signature)?->count() ?? 0) > 1) {
                throw ValidationException::withMessages(['configuration' => 'Несколько вариантов соответствуют одной конфигурации товара #'.$product->id]);
            }
            $match = $bySignature->get($row->signature)?->first();
            if ($match && $match->catalog_configuration_id !== null
                && (int) $match->catalog_configuration_id !== (int) $row->id) {
                throw ValidationException::withMessages(['configuration' => 'Вариант #'.$match->id.' уже связан с другой конфигурацией. Товар #'.$product->id.' требует ручной проверки.']);
            }
        }
        $values = $template->loadMissing('items.group', 'items.value')->items->mapWithKeys(fn ($i) => [$i->group->code.':'.$i->value->code => [$i->product_option_group_id, $i->product_option_value_id]]);
        $keep = [];
        $created = 0;
        $missing = $rows->filter(fn ($row) => ! $bySignature->has($row->signature));
        if ($missing->isNotEmpty()) {
            DB::table('product_variants')->insert($missing->map(fn ($row) => ['product_id' => $product->id, 'catalog_configuration_id' => $row->id,
                'title' => $row->label(), 'options' => json_encode($row->snapshot(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'price' => $row->price, 'price_mode' => 'inherited', 'stock_status' => $product->stock_status->value,
                'is_active' => true, 'is_default' => false, 'configuration_auto_retired' => false,
                'created_at' => now(), 'updated_at' => now()])->all());
        }
        $newIds = DB::table('product_variants')->where('product_id', $product->id)->whereIn('catalog_configuration_id', $missing->modelKeys())->pluck('id', 'catalog_configuration_id');
        $newPairs = [];
        foreach ($rows as $row) {
            $variant = $bySignature->get($row->signature)?->first();
            if ($variant) {
                $changes = ['catalog_configuration_id' => $row->id];
                if ($variant->configuration_auto_retired) {
                    // Only matrix-retired offers can return automatically. Manual exclusions remain off.
                    $changes['is_active'] = true;
                    $changes['configuration_auto_retired'] = false;
                }
                if (blank($variant->title)) { $changes['title'] = $row->label(); }
                if (empty($variant->options)) { $changes['options'] = json_encode($row->snapshot(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
                if ($variant->price !== $row->price || $variant->price_mode !== 'inherited') {
                    $history->record($product->id, $variant->id, $row->id, $variant->price,
                        $variant->price_mode, $row->price, 'inherited', $operationRef, $sourceSha256);
                    $changes['price'] = $row->price;
                    $changes['price_mode'] = 'inherited';
                }
                // Never update untouched rows (important for retry/idempotence).
                $changedFields = array_filter($changes, fn ($value, $key) => match ($key) {
                    'catalog_configuration_id' => (int) $variant->catalog_configuration_id !== (int) $value,
                    'is_active' => (bool) $variant->is_active !== (bool) $value,
                    'configuration_auto_retired' => (bool) $variant->configuration_auto_retired !== (bool) $value,
                    'title' => $variant->title !== $value,
                    'options' => true,
                    'price' => $variant->price !== $value,
                    'price_mode' => $variant->price_mode !== $value,
                    default => true,
                }, ARRAY_FILTER_USE_BOTH);
                if ($changedFields !== []) {
                    $changedFields['updated_at'] = now();
                    DB::table('product_variants')->where('id', $variant->id)->update($changedFields);
                }
                $id = $variant->id;
                if ($variant->optionValues->isEmpty()) {
                    foreach ($row->options as $group => $code) {
                        [$groupId, $valueId] = $values[$group.':'.$code];
                        $newPairs[] = ['product_variant_id' => $id, 'product_option_group_id' => $groupId, 'product_option_value_id' => $valueId, 'created_at' => now(), 'updated_at' => now()];
                    }
                }
            } else {
                $id = $newIds[$row->id];
                foreach ($row->options as $group => $code) {
                    [$groupId, $valueId] = $values[$group.':'.$code];
                    $newPairs[] = ['product_variant_id' => $id, 'product_option_group_id' => $groupId, 'product_option_value_id' => $valueId, 'created_at' => now(), 'updated_at' => now()];
                }
                $created++;
            }
            $keep[] = $id;
        }
        if ($newPairs !== []) {
            DB::table('product_variant_option_values')->insert($newPairs);
        }
        $retired = DB::table('product_variants')->where('product_id', $product->id)->whereNotIn('id', $keep)
            ->where('is_active', true)->update([
                'is_active' => false,
                'is_default' => false,
                'configuration_auto_retired' => true,
                'updated_at' => now(),
            ]);
        $default = DB::table('product_variants')->where('product_id', $product->id)->whereIn('id', $keep)->where('is_active', true)->orderByDesc('is_default')->orderBy('price')->orderBy('id')->first();
        DB::table('product_variants')->where('product_id', $product->id)
            ->where('is_default', true)->when($default, fn ($q) => $q->where('id', '!=', $default->id))
            ->update(['is_default' => false, 'updated_at' => now()]);
        if ($default) {
            DB::table('product_variants')->where('id', $default->id)->where('is_default', false)
                ->update(['is_default' => true, 'updated_at' => now()]);
        }
        $changes = [];
        if (! $product->configuration_managed) { $changes['configuration_managed'] = true; }
        if ((int) $product->product_option_template_id !== (int) $template->id) { $changes['product_option_template_id'] = $template->id; }
        if ($default) {
            if ($product->price !== $default->price || $product->price_mode !== 'inherited') {
                $history->record($product->id, null, $default->catalog_configuration_id, $product->price,
                    $product->price_mode, $default->price, 'inherited', $operationRef, $sourceSha256);
                $changes['price'] = $default->price;
                $changes['price_mode'] = 'inherited';
            }
        }
        if ($changes !== []) {
            $changes['updated_at'] = now();
            DB::table('products')->where('id', $product->id)->update($changes);
        }

        return ['changed' => true, 'price_exceptions' => $difference['price_exceptions'], 'prices_to_change' => $difference['prices_to_change'], 'product_prices_to_change' => $difference['product_prices_to_change'],
            'updated_variants' => $difference['updated_variants'], 'created_variants' => $created,
            'retired_variants' => $retired, 'retained_variants' => count($keep) - $created];
    }
}
