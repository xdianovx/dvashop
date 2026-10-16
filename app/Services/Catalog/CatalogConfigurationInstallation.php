<?php

namespace App\Services\Catalog;

use App\Models\CatalogConfiguration;

/** A reviewed CSV is authoritative for allowed combinations, but never for overriding an existing price. */
final class CatalogConfigurationInstallation
{
    public function preview(array $rows): array
    {
        $desired = [];
        foreach ($rows as $row) {
            $paths = [$row['type']];
            if ($row['type'] === 'porog') {
                $paths[] = $row['options']['profile'] === 'standard' ? 'porog/standartnyi' : 'porog/uvelichennyi';
            }
            foreach ($paths as $path) { $desired[$path.':'.$row['signature']] = $row; }
        }
        $current = app(CatalogConfigurations::class)->ready()
            ? CatalogConfiguration::with('partType')->orderBy('id')->get()
            : collect();
        $known = array_unique([...array_values(CatalogConfigurationSource::TYPES), 'porog/standartnyi', 'porog/uvelichennyi']);
        $current = $current->filter(fn ($row) => in_array($row->partType?->full_slug, $known, true));
        $report = ['added' => [], 'retired' => [], 'reactivated' => [], 'price_conflicts' => []];
        foreach ($current as $row) {
            $key = $row->partType->full_slug.':'.$row->signature;
            $source = $desired[$key] ?? null;
            if (! $source) {
                if ($row->is_active) { $report['retired'][] = ['id' => $row->id, 'type' => $row->partType->full_slug, 'label' => $row->label()]; }
                continue;
            }
            if (! $row->is_active) { $report['reactivated'][] = ['id' => $row->id, 'type' => $row->partType->full_slug, 'label' => $row->label()]; }
            if ($row->price !== $source['price']) {
                $report['price_conflicts'][] = ['id' => $row->id, 'type' => $row->partType->full_slug, 'label' => $row->label(), 'kept_price' => $row->price, 'csv_price' => $source['price']];
            }
            unset($desired[$key]);
        }
        foreach ($desired as $key => $row) { $report['added'][] = ['key' => $key, 'options' => $row['options'], 'price' => $row['price']]; }
        $report['requires_confirmation'] = $report['retired'] !== [] || $report['reactivated'] !== [] || $report['price_conflicts'] !== []
            || ($current->isNotEmpty() && $report['added'] !== []);
        $report['confirmation'] = hash('sha256', json_encode([$rows, $current->map(fn ($row) => $row->only(['id', 'part_type_id', 'signature', 'options', 'price', 'is_active']))->values()->all()], JSON_THROW_ON_ERROR));
        return $report;
    }
}
