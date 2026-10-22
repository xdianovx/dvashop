<?php

namespace App\Services\Import;

use App\Models\PartType;
use App\Models\Product;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Services\Catalog\CatalogConfigurationInstallation;
use App\Services\Catalog\CatalogConfigurationSource;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\SpreadsheetReader;
use App\Support\CatalogText;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Scope and evidence only; all option/price calculations use CatalogImportVariantPlan. */
final class CatalogSafePartialPlan
{
    public const VERSION = 2;

    /** The bounded product forecast exceeds the normal short web-request budget. */
    public static function calculationBudget(): void
    {
        set_time_limit(600);
        $memory = ini_parse_quantity(ini_get('memory_limit'));
        if ($memory > 0 && $memory < 1536 * 1024 * 1024) {
            ini_set('memory_limit', '1536M');
        }
    }

    public function build(string $xlsx, string $csv): array
    {
        self::calculationBudget();
        $assortment = app(CatalogImportProductPlan::class)->plan($xlsx);
        $reader = app(SpreadsheetReader::class);
        $rows = [];
        foreach ($reader->catalogRows($xlsx) as $entry) {
            if ($entry['row'] !== null) {
                $rows[$entry['number']] = $entry['row'] + ['_row_number' => $entry['number']];
            }
        }
        $raw = $reader->readHeaderRows($xlsx, 10000);
        $headers = $reader->readHeaderRows($xlsx, 2);
        $columns = CatalogImportFormat::columns($headers);
        $generations = VehicleGeneration::withTrashed()->with(['model' => fn ($q) => $q->withTrashed()
            ->with(['make' => fn ($q) => $q->withTrashed()])])->get();
        $byModel = $generations->groupBy(fn ($g) => CatalogText::normKey($g->model?->title ?? '', 'model', 120));
        $byId = $generations->keyBy('id');
        $blockedGenerations = [];
        $unknown = [];
        foreach ($assortment['diagnostics']['skipped_rows'] as $skipped) {
            $r = $raw[$skipped['row']];
            $pattern = [];
            foreach (['make', 'model'] as $field) {
                $pattern[$field] = $this->canonical($r[$columns[$field][0]] ?? '');
            }
            if ($pattern['model'] === '') {
                throw new RuntimeException('Не определена область защиты неполной строки '.$skipped['row']);
            }
            $unknown[] = $pattern;
            $matched = false;
            foreach ($generations as $g) {
                if ($this->matches($pattern, $g)) {
                    $blockedGenerations[$g->id] = 'incomplete_excel_row';
                    $matched = true;
                }
            }
            if (! $matched) {
                // An unmatched/incomplete model cannot narrow protection by guessed years/body.
                $broad = ['make' => $pattern['make'], 'model' => ''];
                if ($broad['make'] === '') {
                    throw new RuntimeException('Невозможно определить границу защиты строки '.$skipped['row']);
                }
                $unknown[] = $broad;
                foreach ($generations as $g) {
                    if ($this->matches($broad, $g)) {
                        $blockedGenerations[$g->id] = 'unmatched_incomplete_model_make_scope';
                    }
                }
            }
        }
        $identity = app(CatalogImportIdentity::class);
        $identity->loadExisting();
        foreach ($assortment['expected_products'] as $p) {
            if ($p['state'] !== 'unresolved') {
                continue;
            }
            foreach ($p['source_rows'] as $number) {
                $r = $rows[$number];
                $resolution = $identity->resolve($r, $p['part_type_full_slug'], 'catalog');
                foreach ($resolution['candidate_ids'] as $id) {
                    $blockedGenerations[$id] = 'unresolved_identity';
                }
                if ($resolution['generation_id']) {
                    $blockedGenerations[$resolution['generation_id']] = 'unresolved_applicability';
                }
            }
        }
        $blockedProducts = [];
        foreach (array_chunk(array_keys($blockedGenerations), 500) as $ids) {
            foreach (DB::table('product_fitments')->whereIn('vehicle_generation_id', $ids)->pluck('product_id') as $id) {
                $blockedProducts[(int) $id] = 'conflict_or_incomplete_applicability';
            }
        }
        foreach (app(CatalogImportProtections::class)->ids() as $id) {
            $blockedProducts[$id] = 'permanent_protection';
        }
        $products = Product::withTrashed()->with('fitments', 'partType')->get()->keyBy('id');
        $byGeneration = [];
        foreach ($products as $p) {
            foreach ($p->fitments as $f) {
                $byGeneration[$f->vehicle_generation_id][] = $p;
            }
        }
        $eligible = [];
        $reasons = [];
        $types = PartType::with('productCategory')->get()->keyBy('full_slug');
        foreach ($assortment['expected_products'] as $p) {
            $key = $p['import_key'];
            if ($p['state'] === 'unresolved') {
                $reasons[$key] = 'unresolved_identity';

                continue;
            }
            $r = $rows[$p['source_rows'][0]];
            if ($p['product_id']) {
                $actual = $products->get($p['product_id']);
                if (! $actual || $actual->trashed() || isset($blockedProducts[$actual->id])
                    || $actual->fitments->count() !== 1 || $actual->import_source !== 'catalog'
                    || $actual->part_type_id === null || $actual->partType?->full_slug !== $p['part_type_full_slug']) {
                    $reasons[$key] = 'protected_or_ambiguous_relation';

                    continue;
                }
                $proof = 'existing_unique_identity';
            } else {
                $type = $types->get($p['part_type_full_slug']);
                if (! $type?->is_active || ! $type->productCategory?->is_active) {
                    $reasons[$key] = 'missing_existing_part_category';

                    continue;
                }
                $vehicle = $identity->resolve($r, $p['part_type_full_slug'], 'catalog');
                if ($vehicle['generation_id'] !== null && ! $this->usableGeneration($byId->get($vehicle['generation_id']))) {
                    $reasons[$key] = 'inactive_new_applicability';

                    continue;
                }
                $proof = $this->newProof($r, $p['part_type_full_slug'], $identity, $byModel, $byId, $byGeneration, $blockedGenerations, $unknown);
                if ($proof === null) {
                    $reasons[$key] = 'new_candidate_not_proven';

                    continue;
                }
            }
            $eligible[$key] = ['expected' => $p, 'row' => $r, 'proof' => $proof];
        }
        $scoped = $assortment;
        $scoped['valid'] = true;
        $scoped['scope'] = 'safe_partial';
        $scoped['expected_products'] = array_column($eligible, 'expected');
        $scoped['missing_products'] = [];
        $variants = app(CatalogImportVariantPlan::class)->plan($csv, 'full', $scoped, 'catalog', includeProducts: true);
        $items = [];
        $effects = [];
        foreach ($variants['product_outcomes'] ?? [] as $outcome) {
            $key = $outcome['import_key'];
            if ($outcome['counts']['conflicts'] || $outcome['counts']['protected_products']) {
                $reasons[$key] = 'variant_conflict_or_custom_template';

                continue;
            }
            $item = $eligible[$key];
            $item['fingerprint'] = $outcome['fingerprint'] ?? hash('sha256', json_encode([$item['row'], $item['proof']], JSON_THROW_ON_ERROR));
            $item['effects'] = $outcome['counts'];
            $items[] = $item;
            foreach ($outcome['counts'] as $metric => $count) {
                $effects[$metric] = ($effects[$metric] ?? 0) + $count;
            }
        }
        $allowedIds = array_column(array_column($items, 'expected'), 'product_id');
        $protections = [];
        foreach ($products as $product) {
            if (! in_array($product->id, $allowedIds, true)) {
                $protections[$product->id] = $blockedProducts[$product->id] ?? 'outside_confirmed_assortment';
            }
        }
        $csvRows = app(CatalogConfigurationSource::class)->read($csv);
        $installation = app(CatalogImportProtections::class)->installation($csvRows, array_keys($protections));
        $valid = ($variants['input_valid'] ?? false) && $installation['blocked_types'] === [] && $items !== [];
        $result = ['mode' => 'safe_partial', 'version' => self::VERSION, 'valid' => $valid,
            'files' => ['excel' => $assortment['file']['sha256'], 'csv' => hash_file('sha256', $csv)],
            'matrix_version' => app(CatalogImportProtections::class)->matrixFingerprint(),
            'protection_version' => CatalogSafePartialState::protections(),
            'universe' => CatalogSafePartialState::universe(), 'items' => $items, 'protections' => $protections,
            'counts' => ['updates' => count(array_filter($items, fn ($i) => $i['expected']['product_id'] !== null)),
                'new' => count(array_filter($items, fn ($i) => $i['expected']['product_id'] === null)),
                'protected_existing' => count($protections), 'skipped_keys' => count($reasons),
                'skipped_rows' => count($assortment['diagnostics']['skipped_rows'])],
            'variant_counts' => $valid ? $effects : array_fill_keys(array_keys($effects), null),
            'skip_reasons' => array_count_values($reasons), 'skips' => $reasons,
            'installation' => $installation, 'matrix_review' => app(CatalogConfigurationInstallation::class)->preview($csvRows),
            'diagnostics' => $variants['diagnostics'], 'full_assortment_valid' => $assortment['valid']];
        if ($result['files']['excel'] !== hash_file('sha256', $xlsx)) {
            throw new RuntimeException('Excel изменился во время расчёта.');
        }
        $result['plan_hash'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    private function newProof(array $r, string $path, CatalogImportIdentity $identity, $byModel, $byId, array $byGeneration, array $blocked, array $unknown): ?string
    {
        foreach ($unknown as $pattern) {
            $incoming = ['make' => $r[1], 'model' => $r[2], 'years' => $r[4], 'body' => $r[5]];
            if (collect($pattern)->every(fn ($v, $k) => $v === '' || ($k === 'make'
                ? $this->protectionMake($v) === $this->protectionMake($incoming[$k])
                : $v === $this->canonical($incoming[$k])))) {
                return null;
            }
        }
        $resolution = $identity->resolve($r, $path, 'catalog');
        if ($resolution['error'] !== null || $resolution['product_id'] !== null) {
            return null;
        }
        if ($resolution['generation_id'] !== null) {
            $gid = $resolution['generation_id'];
            $g = $byId->get($gid);
            if (isset($blocked[$gid]) || ! $this->usableGeneration($g)) {
                return null;
            }
            $family = explode('/', $path)[0];
            foreach ($byGeneration[$gid] ?? [] as $product) {
                $oldPath = $product->partType?->full_slug ?? '';
                if ($oldPath === $path || explode('/', $oldPath)[0] === $family
                    || (in_array($family, ['usilitel', 'soedinitel']) && in_array(explode('/', $oldPath)[0], ['usilitel', 'soedinitel']))) {
                    return null;
                }
            }

            return 'unique_existing_vehicle_no_historical_part_family';
        }
        $model = CatalogText::normKey($r[2], 'model', 120);
        if (($byModel[$model] ?? collect())->isNotEmpty()) {
            return null;
        }
        $make = VehicleMake::withTrashed()->where('norm_key', CatalogText::normKey($r[1], 'make', 100))->first();
        if ($make && ($make->trashed() || ! $make->is_active)) {
            return null;
        }

        return 'new_vehicle_model_absent_from_all_historical_makes';
    }

    private function matches(array $pattern, VehicleGeneration $g): bool
    {
        $fields = ['make' => $g->model?->make?->title, 'model' => $g->model?->title, 'years' => $g->years_label, 'body' => $g->body];
        foreach ($pattern as $key => $value) {
            // Market labels cannot narrow a protection to one market.
            $actual = $this->canonical($fields[$key] ?? '');
            $wanted = $value;
            if ($key === 'make') {
                $actual = $this->protectionMake($actual);
                $wanted = $this->protectionMake($wanted);
            }
            if ($wanted !== '' && $wanted !== $actual) {
                return false;
            }
        }

        return true;
    }

    private function usableGeneration(?VehicleGeneration $g): bool
    {
        return $g && ! $g->trashed() && $g->is_active && $g->model && ! $g->model->trashed() && $g->model->is_active
            && $g->model->make && ! $g->model->make->trashed() && $g->model->make->is_active;
    }

    private function canonical(mixed $value): string
    {
        $v = mb_strtolower(CatalogText::plain($value, 1000));

        return preg_replace('/\s*([\p{Pd}()\/])\s*/u', '$1', preg_replace('/\p{Pd}/u', '-', $v));
    }

    /** Broadens protection only; never used to resolve or merge identities. */
    private function protectionMake(mixed $value): string
    {
        $value = preg_replace('/\s+(eur|внутренний)$/u', '', $this->canonical($value));

        return $value === 'volkswagen' ? 'vw' : $value;
    }
}
