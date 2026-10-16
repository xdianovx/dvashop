<?php

namespace App\Services\Catalog;

use App\Models\CatalogConfiguration;
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

    /** Explicit installation, separate from migrations. Existing administrative prices are never overwritten. */
    public function install(string $path, ?string $confirmation = null): array
    {
        $this->clear();
        $rows = app(CatalogConfigurationSource::class)->read($path);

        return DB::transaction(function () use ($rows, $confirmation): array {
            app(CatalogPriceGuard::class)->assertWritable();
            if (DB::table('import_runs')->whereIn('status', ['running', 'running_rows', 'paused'])->exists()) {
                throw ValidationException::withMessages(['configuration' => 'Сначала завершите импорт.']);
            }
            $review = app(CatalogConfigurationInstallation::class)->preview($rows);
            if (($review['requires_confirmation'] && $confirmation === null)
                || ($confirmation !== null && ! hash_equals($review['confirmation'], $confirmation))) {
                throw ValidationException::withMessages(['configuration' => 'Изменён состав или цены CSV. Проверьте предпросмотр и подтвердите его актуальным кодом. Существующие цены будут сохранены.']);
            }
            $tree = app(PartTypeTreeService::class);
            $definitions = app(PartTypeDefinitionRegistry::class)->flattened($tree);
            $created = 0;
            foreach (collect($rows)->groupBy('type') as $path => $groupRows) {
                $paths = [$path];
                if ($path === 'porog') {
                    $paths = ['porog', 'porog/standartnyi', 'porog/uvelichennyi'];
                }
                foreach ($paths as $target) {
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
                        if ($target === 'porog/standartnyi' && $row['options']['profile'] !== 'standard') {
                            continue;
                        }
                        if ($target === 'porog/uvelichennyi' && $row['options']['profile'] !== 'increased') {
                            continue;
                        }
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
                        if (! $configuration->is_active) { $configuration->update(['is_active' => true]); }
                    }
                }
            }

            CatalogConfiguration::whereIn('id', array_column($review['retired'], 'id'))->update(['is_active' => false, 'updated_at' => now()]);
            $this->clear();
            return ['review' => $review, 'source_rows' => count($rows), 'created_configurations' => $created, 'warnings' => ['Арка / Карман задняя: нового набора конфигураций нет; сохранено прежнее поведение.']];
        });
    }

    public function combinations(ProductOptionTemplate $template): ?array
    {
        if (! str_starts_with((string) $template->slug, 'matrix_') || ! $this->ready()) {
            return null;
        }
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
        if ($variant->optionValues->isEmpty()) {
            $snapshot = ProductVariant::optionsWithoutManagementMetadata($variant->options) ?? [];
            if ($snapshot === []) {
                return '';
            }
            $options = [];
            foreach ($snapshot as $group => $value) {
                if (! isset(CatalogConfigurationSource::VALUES[$group])) {
                    return '';
                }
                $value = is_array($value) ? ($value['value'] ?? null) : $value;
                $allowed = CatalogConfigurationSource::VALUES[$group];
                $code = $allowed[$value ?? ''] ?? (in_array($value, $allowed, true) ? $value : null);
                if ($code === null) {
                    return '';
                }
                $options[$group] = $code;
            }

            return CatalogConfigurationSource::signature($options);
        }

        return CatalogConfigurationSource::signature($variant->optionValues->mapWithKeys(fn ($v) => [$v->group->code => $v->code])->all());
    }

    /** Same comparison used by preview and application; ignores timestamps and historical order snapshots. */
    public function changes(Product $product): array
    {
        $rows = $this->rows($product->part_type_id);
        $result = ['changed' => false, 'created_variants' => 0, 'updated_variants' => 0, 'retired_variants' => 0];
        if ($rows->isEmpty()) {
            // Last CSV combination was removed. Existing managed offers must leave sale as well.
            if ($this->canRetireManagedProduct($product)) {
                $variants = $product->variants;
                $result['retired_variants'] = $variants->where('is_active', true)->count();
                $result['changed'] = $result['retired_variants'] > 0
                    || $variants->contains('is_default', true);
            }

            return $result;
        }
        $variants = $product->variants;
        $signatures = $variants->groupBy(fn ($v) => $this->signature($v));
        $candidates = collect();
        foreach ($rows as $row) {
            $matches = $signatures->get($row->signature, collect());
            if ($matches->count() > 1) { throw ValidationException::withMessages(['configuration' => 'Несколько вариантов соответствуют одной конфигурации товара #'.$product->id]); }
            $variant = $matches->first();
            if (! $variant) {
                $result['created_variants']++;
                $candidates->push(['id' => null, 'default' => false, 'price' => $row->price]);
                continue;
            }
            $price = $variant->price_mode === 'inherited' ? $row->price : $variant->price;
            if ((int) $variant->catalog_configuration_id !== (int) $row->id || $variant->price !== $price
                || $variant->configuration_auto_retired || blank($variant->title)
                || empty($variant->options) || $variant->optionValues->isEmpty()) {
                $result['updated_variants']++;
            }
            if ($variant->is_active) { $candidates->push(['id' => $variant->id, 'default' => $variant->is_default, 'price' => $price]); }
        }
        $result['retired_variants'] = $variants->filter(fn ($v) => $v->is_active && ! $rows->contains('signature', $this->signature($v)))->count();
        $default = $candidates->sort(fn ($a, $b) => ($b['default'] <=> $a['default'])
            ?: ((int) str_replace('.', '', (string) $a['price']) <=> (int) str_replace('.', '', (string) $b['price']))
            ?: (($a['id'] ?? PHP_INT_MAX) <=> ($b['id'] ?? PHP_INT_MAX)))->first();
        // Eloquent's modelKeys() can preserve the original collection offsets.
        // A valid default after retired legacy offers might be keyed [24 => id],
        // while the expected list is [0 => id]. Compare IDs, not collection keys.
        $currentDefaults = $variants->where('is_default', true)->values()->modelKeys();
        $desiredDefaults = $default && $default['id'] !== null ? [$default['id']] : [];
        $result['changed'] = $result['created_variants'] + $result['updated_variants'] + $result['retired_variants'] > 0
            || $currentDefaults !== $desiredDefaults || ! $product->configuration_managed
            || (int) $product->product_option_template_id !== (int) $rows->first()->product_option_template_id
            || ($product->price_mode === 'inherited' && $default && $product->price !== $default['price']);
        return $result;
    }

    /** Called inside a product transaction by import or the reserved, resumable operation. */
    public function synchronize(Product $product): array
    {
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
        $variants = $product->relationLoaded('variants') ? $product->variants : $product->variants()->with('optionValues.group')->orderBy('id')->lockForUpdate()->get();
        $product->setRelation('variants', $variants);
        $difference = $this->changes($product);
        if (! $difference['changed']) { return $difference + ['retained_variants' => $rows->count()]; }
        $bySignature = $variants->groupBy(fn ($v) => $this->signature($v));
        foreach ($rows as $row) {
            if (($bySignature->get($row->signature)?->count() ?? 0) > 1) {
                throw ValidationException::withMessages(['configuration' => 'Несколько вариантов соответствуют одной конфигурации товара #'.$product->id]);
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
                if ($variant->price_mode === 'inherited') {
                    $changes['price'] = $row->price;
                }
                DB::table('product_variants')->where('id', $variant->id)->update($changes);
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
        DB::table('product_variants')->where('product_id', $product->id)->update(['is_default' => false]);
        if ($default) {
            DB::table('product_variants')->where('id', $default->id)->update(['is_default' => true]);
        }
        $changes = ['configuration_managed' => true, 'product_option_template_id' => $template->id];
        if ($product->price_mode === 'inherited' && $default) {
            $changes['price'] = $default->price;
        }
        DB::table('products')->where('id', $product->id)->update($changes);

        return ['changed' => true, 'updated_variants' => $difference['updated_variants'], 'created_variants' => $created, 'retired_variants' => $retired, 'retained_variants' => count($keep) - $created];
    }
}
