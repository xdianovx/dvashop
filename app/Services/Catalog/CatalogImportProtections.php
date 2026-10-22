<?php

namespace App\Services\Catalog;

use App\Models\CatalogConfiguration;
use App\Models\PartType;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CatalogImportProtections
{
    public function ready(): bool
    {
        return app(CatalogPricingSchema::class)->has('catalog_import_protections');
    }

    public function ids(): array
    {
        return $this->ready() ? DB::table('catalog_import_protections')->pluck('product_id')->map(fn ($id) => (int) $id)->all() : [];
    }

    public function protects(int $id, bool $lock = false): bool
    {
        return $this->ready() && DB::table('catalog_import_protections')->where('product_id', $id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->exists();
    }

    public function protectsVehicle(string $level, int $id): bool
    {
        if (! $this->ready()) {
            return false;
        }
        $q = DB::table('catalog_import_protections as cp')->join('product_fitments as f', 'f.product_id', '=', 'cp.product_id');
        if ($level === 'generation') {
            return $q->where('f.vehicle_generation_id', $id)->exists();
        }
        $q->join('vehicle_generations as g', 'g.id', '=', 'f.vehicle_generation_id');
        if ($level === 'model') {
            return $q->where('g.vehicle_model_id', $id)->exists();
        }
        if ($level !== 'make') {
            throw new \InvalidArgumentException('Unknown vehicle level');
        }

        return $q->join('vehicle_models as m', 'm.id', '=', 'g.vehicle_model_id')->where('m.vehicle_make_id', $id)->exists();
    }

    /** No automatic replacement/removal: the first protection remains authoritative. */
    public function persist(int $runId, array $ids, int $version, string $hash): void
    {
        if (! $this->ready()) {
            throw ValidationException::withMessages(['safe_partial' => 'Не применена миграция постоянной защиты.']);
        }
        app(CatalogPriceGuard::class)->assertWritable();
        foreach (array_chunk($ids, 500, true) as $chunk) {
            $existing = DB::table('catalog_import_protections')->whereIn('product_id', array_keys($chunk))->pluck('product_id')->all();
            $products = DB::table('products')->whereIn('id', array_keys($chunk))->lockForUpdate()->get()->keyBy('id');
            $fitments = DB::table('product_fitments')->whereIn('product_id', array_keys($chunk))->orderBy('id')->lockForUpdate()->get()->groupBy('product_id');
            $rows = [];
            foreach ($chunk as $id => $reason) {
                if (! in_array((int) $id, $existing)) {
                    $rows[] = ['product_id' => $id, 'import_run_id' => $runId, 'plan_version' => $version, 'plan_hash' => $hash,
                        'reason' => $reason, 'control' => json_encode(['scope' => 'outside_confirmed_safe_partial',
                            'product_sha256' => hash('sha256', json_encode($products->get($id), JSON_THROW_ON_ERROR)),
                            'fitments' => $fitments->get($id, collect())->all()], JSON_THROW_ON_ERROR), 'created_at' => now()];
                }
            }
            if ($rows) {
                DB::table('catalog_import_protections')->insert($rows);
            }
        }
    }

    /** Hold the singleton through the caller's transaction, including file operations. */
    public function assertProductWritable(int $id): void
    {
        app(CatalogPriceGuard::class)->assertImportWritable();
        if ($this->ready() && DB::table('catalog_import_protections')->where('product_id', $id)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['product' => 'Товар имеет постоянную защиту safe_partial.']);
        }
    }

    public function assertGenerationWritable(int $id): void
    {
        app(CatalogPriceGuard::class)->assertImportWritable();
        if ($this->ready() && DB::table('catalog_import_protections as cp')
            ->join('product_fitments as f', 'f.product_id', '=', 'cp.product_id')->where('f.vehicle_generation_id', $id)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['vehicle' => 'Применимость имеет постоянную защиту safe_partial.']);
        }
    }

    /** Exact intended install writes, including option/template additions and positions. */
    public function installation(array $rows, array $extraProtectedIds = []): array
    {
        $targets = [];
        foreach ($rows as $row) {
            foreach (CatalogConfigurationSource::targetPaths($row) as $path) {
                $targets[$path][] = $row;
            }
        }
        foreach (CatalogConfiguration::where('is_active', true)->whereHas('template', fn ($q) => $q->where('slug', 'like', 'matrix_%'))->with('partType')->get() as $configuration) {
            $targets[$configuration->partType->full_slug] ??= [];
        }
        $types = PartType::all()->keyBy('full_slug');
        $templates = ProductOptionTemplate::with('items')->get()->keyBy('slug');
        $groups = ProductOptionGroup::with('values')->get()->keyBy('code');
        $configurations = CatalogConfiguration::all()->groupBy('part_type_id');
        $changed = [];
        foreach ($targets as $path => $desired) {
            $type = $types->get($path);
            $template = $templates->get('matrix_'.str_replace('/', '_', $path));
            $same = $type && $template && (int) $template->part_type_id === (int) $type->id;
            $old = $configurations->get($type?->id, collect());
            $signatures = [];
            foreach ($desired as $position => $row) {
                $signatures[] = $row['signature'];
                $match = $old->firstWhere('signature', $row['signature']);
                $same = $same && $match && $match->is_active && $match->price === $row['price']
                    && $this->sameOptions($match->options, $row['options']) && (int) $match->position === $position
                    && (int) $match->product_option_template_id === (int) $template?->id;
                foreach ($row['options'] as $code => $valueCode) {
                    $group = $groups->get($code);
                    $value = $group?->values->firstWhere('code', $valueCode);
                    $same = $same && $value && $template?->items->contains(fn ($i) => (int) $i->product_option_group_id === (int) $group->id && (int) $i->product_option_value_id === (int) $value->id);
                }
            }
            $same = $same && ! $old->contains(fn ($c) => $c->is_active && ! in_array($c->signature, $signatures));
            if (! $same) {
                $changed[$path] = $type?->id;
            }
        }
        $protectedIds = array_values(array_unique([...$this->ids(), ...$extraProtectedIds]));
        $protectedTypes = [];
        foreach (array_chunk($protectedIds, 500) as $ids) {
            $protectedTypes = [...$protectedTypes,
                ...DB::table('products')->whereIn('id', $ids)->pluck('part_type_id')->all(),
                ...DB::table('products as p')->join('product_option_templates as t', 't.id', '=', 'p.product_option_template_id')->whereIn('p.id', $ids)->pluck('t.part_type_id')->all(),
                ...DB::table('product_variants as v')->join('catalog_configurations as c', 'c.id', '=', 'v.catalog_configuration_id')->whereIn('v.product_id', $ids)->distinct()->pluck('c.part_type_id')->all()];
        }
        $blocked = array_keys(array_filter($changed, fn ($id) => $id !== null && in_array($id, $protectedTypes)));

        return ['equivalent' => $changed === [], 'changed_types' => array_keys($changed), 'blocked_types' => $blocked];
    }

    public function assertInstallation(array $rows, array $extraProtectedIds = []): array
    {
        $effect = $this->installation($rows, $extraProtectedIds);
        if ($effect['blocked_types']) {
            throw ValidationException::withMessages(['configuration' => 'CSV меняет общие структуры защищённых товаров: '.implode(', ', $effect['blocked_types'])]);
        }

        return $effect;
    }

    public function matrixFingerprint(bool $lock = false): string
    {
        $state = [];
        foreach (['catalog_configurations', 'catalog_matrix_sources', 'part_types', 'product_option_templates',
            'product_option_template_items', 'product_option_groups', 'product_option_values'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->all();
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function sameOptions(array $installed, array $incoming): bool
    {
        // MySQL JSON storage reorders object keys; their order has no meaning.
        ksort($installed);
        ksort($incoming);

        return $installed === $incoming;
    }
}
