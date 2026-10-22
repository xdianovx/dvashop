<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Pure comparison: callers supply rows and eagerly loaded variant/option relations. */
final class CatalogVariantDifference
{
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
    public function compare(Product $product, Collection $rows, bool $canRetire, bool $details = false): array
    {
        $updatedIds = [];
        $reactivated = 0;
        $result = ['changed' => false, 'created_variants' => 0, 'updated_variants' => 0, 'retired_variants' => 0, 'price_exceptions' => 0, 'prices_to_change' => 0, 'product_prices_to_change' => 0];
        if ($rows->isEmpty()) {
            // Last CSV combination was removed. Existing managed offers must leave sale as well.
            if ($canRetire) {
                $variants = $product->variants;
                $result['retired_variants'] = $variants->where('is_active', true)->count();
                $result['changed'] = $result['retired_variants'] > 0
                    || $variants->contains('is_default', true);
            }

            return $details ? $result + [
                'reactivated_variants' => 0,
                'variants_update' => $canRetire ? $product->variants->where('is_active', false)->where('is_default', true)->count() : 0,
                'unchanged_variants' => $product->variants->filter(fn ($v) => ! $canRetire || (! $v->is_active && ! $v->is_default))->count(),
                'default_changes' => $canRetire ? $product->variants->where('is_default', true)->count() : 0,
            ] : $result;
        }
        $variants = $product->variants;
        $signatures = $variants->groupBy(fn ($v) => $this->signature($v));
        // A historical variant linked to a CSV row but carrying a different set of
        // options is ambiguous. Do not overwrite that ID or collide with its unique key.
        foreach ($variants as $existing) {
            if ($existing->catalog_configuration_id !== null) {
                $linked = $rows->firstWhere('id', $existing->catalog_configuration_id);
                if ($linked && $this->signature($existing) !== $linked->signature) {
                    throw ValidationException::withMessages(['configuration' => 'Вариант #'.$existing->id.' содержит параметры, отличные от связанной конфигурации. Требуется ручная проверка.']);
                }
            }
        }
        $candidates = collect();
        foreach ($rows as $row) {
            $matches = $signatures->get($row->signature, collect());
            if ($matches->count() > 1) {
                throw ValidationException::withMessages(['configuration' => 'Несколько вариантов соответствуют одной конфигурации товара #'.$product->id]);
            }
            $variant = $matches->first();
            if ($variant && $variant->catalog_configuration_id !== null
                && (int) $variant->catalog_configuration_id !== (int) $row->id) {
                throw ValidationException::withMessages(['configuration' => 'Вариант #'.$variant->id.' привязан к иной конфигурации.']);
            }
            if (! $variant) {
                $result['created_variants']++;
                $candidates->push(['id' => null, 'default' => false, 'price' => $row->price]);

                continue;
            }
            // CSV wins even over previously individual/unknown prices of managed variants.
            $price = $row->price;
            if ($variant->price !== $price || $variant->price_mode !== 'inherited') {
                $result['prices_to_change']++;
                if ($variant->price_mode !== 'inherited') {
                    $result['price_exceptions']++;
                }
            }
            if ((int) $variant->catalog_configuration_id !== (int) $row->id || $variant->price !== $price
                || $variant->price_mode !== 'inherited'
                || $variant->configuration_auto_retired || blank($variant->title)
                || empty($variant->options) || $variant->optionValues->isEmpty()) {
                $result['updated_variants']++;
                $updatedIds[$variant->id] = true;
            }
            $reactivated += ! $variant->is_active && $variant->configuration_auto_retired ? 1 : 0;
            if ($variant->is_active || $variant->configuration_auto_retired) {
                $candidates->push(['id' => $variant->id, 'default' => $variant->is_default, 'price' => $price]);
            }
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
        $result['product_prices_to_change'] = $default && ($product->price !== $default['price'] || $product->price_mode !== 'inherited') ? 1 : 0;
        $result['changed'] = $result['created_variants'] + $result['updated_variants'] + $result['retired_variants'] > 0
            || $currentDefaults !== $desiredDefaults || ! $product->configuration_managed
            || (int) $product->product_option_template_id !== (int) $rows->first()->product_option_template_id
            || $result['product_prices_to_change'] > 0;
        if (! $details) {
            return $result;
        }
        $retiredIds = $variants->filter(fn ($v) => $v->is_active && ! $rows->contains('signature', $this->signature($v)))->modelKeys();
        $defaultChanges = 0;
        foreach ($variants as $variant) {
            if ((bool) $variant->is_default !== in_array($variant->id, $desiredDefaults, true)) {
                $defaultChanges++;
                if (! in_array($variant->id, $retiredIds, true)) {
                    $updatedIds[$variant->id] = true;
                }
            }
        }

        return $result + [
            'reactivated_variants' => $reactivated,
            'variants_update' => count($updatedIds),
            'unchanged_variants' => $variants->count() - count($updatedIds) - count($retiredIds),
            'default_changes' => $defaultChanges + ($default && $default['id'] === null ? 1 : 0),
        ];
    }
}
