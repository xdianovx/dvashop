<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\VehicleGeneration;
use App\Services\SpreadsheetReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Recurring FULL evidence plus the read-only verifier of the originally approved list. */
trait PreparesStructuralArchive
{
    public function prepareStructuralArchive(string $csv, string $xlsx, array $decision, bool $lock = false): array
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Structural archive is restricted to local/testing.');
        }
        $this->assertStructuralFiles($csv, $xlsx, $decision);
        $read = static function (string $path): array {
            $stream = fopen($path, 'rb');
            try {
                $headers = fgetcsv($stream, 0, ';', '"', '');
                $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
                $rows = [];
                while (($row = fgetcsv($stream, 0, ';', '"', '')) !== false) {
                    $rows[] = array_combine($headers, $row);
                }

                return $rows;
            } finally {
                fclose($stream);
            }
        };
        $records = $read($csv);
        $ids = array_map(fn ($r) => (int) $r['product_id'], $records);
        sort($ids, SORT_NUMERIC);
        if ($ids === [] || count(array_unique($ids)) !== count($ids)
            || ! hash_equals($decision['ids_sha256'], $this->structuralHash($ids))
            || array_intersect($ids, array_column($read($decision['excluded_path']), 'product_id'))) {
            throw new RuntimeException('Approved structural ID list changed or overlaps exclusions.');
        }
        $counts = array_count_values(array_column($records, 'historical_type'));
        $expectedCounts = $decision['counts'];
        ksort($counts);
        ksort($expectedCounts);
        if ($counts !== $expectedCounts || array_diff(array_keys($counts), ['porog', 'arka/vnutrenniaia'])) {
            throw new RuntimeException('Unexpected structural types or counts.');
        }
        $query = static fn (string $table, string $column, array $keys) => DB::table($table)->whereIn($column, $keys)
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $run = DB::table('import_runs')->where('id', $decision['source_run_id'])
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $scope = json_decode($run->prepared_rows ?? '{}', true, flags: JSON_THROW_ON_ERROR)['full_scope'] ?? [];
        if (! $run || $run->status !== 'done' || $run->import_mode !== 'full' || $run->errors_count !== 0
            || ($scope['excel_sha256'] ?? null) !== $decision['excel_sha256']) {
            throw new RuntimeException('The source FULL is not completed with the approved Excel.');
        }
        $proofs = [];
        $sources = [];
        $childIds = [];
        foreach ($records as $r) {
            if ($r['historical_phase_requires_review'] !== 'no' || $r['source_excel_sha256'] !== $decision['excel_sha256']
                || ($scope['preserve'][$r['product_id']] ?? null) !== 'structural_type_requires_mapping') {
                throw new RuntimeException('Unapproved structural applicability: '.$r['product_id']);
            }
            $proofs[$r['product_id']] = json_decode($r['source_cells_and_successor_products'], true, flags: JSON_THROW_ON_ERROR);
            $sources[$r['product_id']] = json_decode($r['source_rows_and_vehicles'], true, flags: JSON_THROW_ON_ERROR);
            foreach ($proofs[$r['product_id']] as $proof) {
                if (count($proof['existing_same_generation']) !== 1) {
                    throw new RuntimeException('A positive position must have exactly one existing product.');
                }
                $childIds[] = $proof['existing_same_generation'][0]['id'];
            }
        }
        $allIds = array_values(array_unique([...$ids, ...$childIds]));
        $products = $query('products', 'id', $allIds);
        $fitments = $query('product_fitments', 'product_id', $allIds);
        $byProduct = $fitments->groupBy('product_id');
        $generations = $query('vehicle_generations', 'id', $fitments->pluck('vehicle_generation_id')->unique()->all());
        $models = $query('vehicle_models', 'id', $generations->pluck('vehicle_model_id')->unique()->all());
        $makes = $query('vehicle_makes', 'id', $models->pluck('vehicle_make_id')->unique()->all());
        $parts = $query('part_types', 'id', $products->pluck('part_type_id')->unique()->all());
        $protections = DB::table('catalog_import_protections')->orderBy('product_id')->get();
        $protected = $protections->keyBy('product_id');
        $reader = app(SpreadsheetReader::class);
        $columns = [];
        foreach ($reader->readMergedDetailHeaders($xlsx) as $index => $header) {
            $columns[app(ImportPartTypeResolver::class)->structuralPath($header)] = $index;
        }
        $rows = [];
        foreach ($reader->catalogRows($xlsx) as $entry) {
            if ($entry['row'] !== null) {
                $rows[$entry['number']] = $entry['row'];
            }
        }
        $parents = [];
        foreach ($records as $r) {
            $p = $products->get($r['product_id']);
            $f = $byProduct->get($r['product_id']);
            if (! $p || $p->deleted_at !== null || $p->status !== 'active' || $p->import_source !== 'catalog'
                || $p->import_key !== $r['old_import_key'] || (string) $p->updated_at !== $r['product_updated_at']
                || ! in_array($p->publication_override, [null, 'force_active'], true)
                || $parts->get($p->part_type_id)?->full_slug !== $r['historical_type']
                || $protected->get($p->id)?->reason !== $r['protection_reason']
                || $f?->count() !== 1 || (string) $f->first()->vehicle_generation_id !== $r['vehicle_generation_id']) {
                throw new RuntimeException('Historical product/status/protection/fitment changed: '.$r['product_id']);
            }
            $g = $generations->get($f->first()->vehicle_generation_id);
            $m = $models->get($g->vehicle_model_id);
            $b = $makes->get($m->vehicle_make_id);
            foreach (['make' => $b->title, 'model' => $m->title, 'generation' => $g->title, 'years' => $g->years_label,
                'body' => $g->body, 'platform' => $g->platform, 'restyling' => $g->restyling,
                'fitment_note' => $f->first()->note] as $field => $value) {
                if ((string) $value !== $r['historical_'.$field]) {
                    throw new RuntimeException('Historical vehicle changed: '.$p->id.' '.$field);
                }
            }
            if ($g->deleted_at !== null || $m->deleted_at !== null || $b->deleted_at !== null) {
                throw new RuntimeException('A vehicle was deleted after review.');
            }
            $positive = [];
            foreach ($sources[$p->id] as $number => $source) {
                $row = $rows[$number] ?? null;
                if (! $row || array_slice($row, 1, 5) !== $source['vehicle']
                    || ($row['_platform'] ?? '') !== $source['platform'] || ($row['_restyling'] ?? '') !== $source['restyling']) {
                    throw new RuntimeException('Reviewed Excel vehicle changed: '.$number);
                }
                $family = $r['historical_type'] === 'porog' ? ['porog/standartnyi', 'porog/uvelichennyi']
                    : ['arka/vnutrenniaia-zadniaia', 'arka/vnutrenniaia-peredniaia', 'arka/vnutrenniaia-universalnaia'];
                foreach ($family as $path) {
                    $column = $columns[$path] ?? throw new RuntimeException('Missing source column.');
                    if (CatalogImportCell::parse($row['_details'][$column] ?? null) !== null) {
                        $positive[] = CatalogImportCell::column($column).$number;
                    }
                }
            }
            $covered = [];
            foreach ($proofs[$p->id] as $proof) {
                $column = $columns[$proof['path']] ?? throw new RuntimeException('Missing successor column.');
                preg_match('/([0-9]+)$/', $proof['cell'], $number);
                $rowNumber = (int) ($number[1] ?? 0);
                if (! isset($sources[$p->id][$rowNumber]) || $proof['cell'] !== CatalogImportCell::column($column).$rowNumber
                    || (string) ($rows[$rowNumber]['_details'][$column] ?? '') !== (string) $proof['value']) {
                    throw new RuntimeException('A successor is not backed by its reviewed source cell.');
                }
                $oldChild = $proof['existing_same_generation'][0];
                $child = $products->get($oldChild['id']);
                foreach (['id', 'import_key', 'title', 'status', 'publication_override', 'last_import_run_id', 'import_present'] as $field) {
                    if (! $child || (string) $child->$field !== (string) $oldChild[$field]) {
                        throw new RuntimeException('Reviewed successor changed: '.$oldChild['id']);
                    }
                }
                $cf = $byProduct->get($child->id);
                if ($child->deleted_at !== null || $child->import_key !== $proof['expected_key']
                    || $parts->get($child->part_type_id)?->full_slug !== $proof['path'] || $cf?->count() !== 1
                    || $cf->first()->vehicle_generation_id !== $g->id || (string) $cf->first()->note !== (string) $oldChild['note']
                    || ($child->status !== 'active' && ! in_array($child->publication_override, ['draft', 'archived'], true))) {
                    throw new RuntimeException('A successor no longer covers the same applicability.');
                }
                $covered[] = $proof['cell'];
            }
            sort($positive);
            sort($covered);
            if ($positive === [] || $positive !== $covered) {
                throw new RuntimeException('Not every positive source position is covered.');
            }
            $parents[$p->id] = array_intersect_key((array) $p, array_flip(['import_key', 'status', 'import_present', 'updated_at']));
        }
        ksort($parents);
        $identity = [app()->environment(), DB::connection()->getDatabaseName(),
            DB::getDriverName() === 'mysql' ? DB::selectOne('SELECT @@server_uuid AS uuid')->uuid : 'sqlite'];
        $plan = ['version' => 1, 'decision' => $decision, 'manifest_path' => $csv, 'xlsx_path' => $xlsx,
            'database' => $identity, 'parents' => $parents, 'counts' => $counts,
            'state_sha256' => $this->structuralHash([$run, $products->all(), $fitments->all(), $generations->all(),
                $models->all(), $makes->all(), $parts->all(), $protections->all()])];

        $this->assertStructuralFiles($csv, $xlsx, $decision);

        return $plan + ['seal' => $this->structuralSeal($plan)];
    }

    /** The recurring FULL rule: retire a generic offer, never alias a physical part. */
    public function proveStructuralRetirement(
        Product $product,
        VehicleGeneration $generation,
        array $rows,
        array $paths,
        array $coverage,
        Collection $productsByKey,
        bool $materialized = false,
    ): array {
        $families = ['porog' => ['porog/standartnyi', 'porog/uvelichennyi'],
            'arka/vnutrenniaia' => ['arka/vnutrenniaia-zadniaia', 'arka/vnutrenniaia-peredniaia', 'arka/vnutrenniaia-universalnaia']];
        $family = $families[$product->partType?->full_slug] ?? null;
        $hold = static fn (string $reason) => ['proof' => null, 'reason' => $reason];
        if ($family === null) {
            return $hold('structural_type_requires_mapping');
        }
        if ($product->trashed() || in_array($product->publication_override, ['draft', 'archived'], true)
            || $product->fitments->count() !== 1 || $product->fitments->first()->vehicle_generation_id !== $generation->id
            || $generation->trashed() || ! $generation->model || $generation->model->trashed()
            || ! $generation->model->make || $generation->model->make->trashed()) {
            return $hold('structural_applicability_requires_decision');
        }
        $historicalPhase = $generation->restyling ?: (preg_match('/\[([^\]]*)\]/u', $generation->title, $m) ? $m[1] : '');
        $counterparts = [];
        $vehicles = [];
        foreach ($rows as $number => $row) {
            $phase = CatalogImportIdentity::version($row['_restyling'] ?? '');
            if ($phase !== '' && $phase !== CatalogImportIdentity::version($historicalPhase)) {
                return $hold('structural_restyling_requires_decision');
            }
            $vehicles[$number] = ['vehicle' => array_slice($row, 1, 5), 'platform' => $row['_platform'] ?? '', 'restyling' => $row['_restyling'] ?? ''];
            foreach ($paths as $candidate => $candidateColumns) {
                $related = $product->partType->full_slug === 'porog'
                    ? str_starts_with($candidate, 'porog/') : str_starts_with($candidate, 'arka/vnutrenniaia');
                if ($related && ! in_array($candidate, $family, true)) {
                    foreach ($candidateColumns as $column) {
                        if (CatalogImportCell::parse($row['_details'][$column] ?? null) !== null) {
                            return $hold('structural_type_requires_mapping');
                        }
                    }
                }
            }
            foreach ($family as $path) {
                foreach ($paths[$path] ?? [] as $column) {
                    if (CatalogImportCell::parse($row['_details'][$column] ?? null) === null) {
                        continue;
                    }
                    $accepted = $coverage[$number][$path] ?? null;
                    if (! $accepted || $accepted['generation_id'] !== $generation->id) {
                        return $hold('structural_successor_requires_decision');
                    }
                    $child = $productsByKey->get($accepted['key']);
                    if ($child === null) {
                        if ($materialized || ! $accepted['executable'] || $product->fitments->first()->note !== null) {
                            return $hold('structural_successor_missing');
                        }
                    } elseif ($child->trashed() || $child->import_source !== $product->import_source
                        || $child->partType?->full_slug !== $path || $child->fitments->count() !== 1
                        || $child->fitments->first()->vehicle_generation_id !== $generation->id
                        || $child->fitments->first()->note !== $product->fitments->first()->note
                        || ($child->status->value !== 'active' && ! in_array($child->publication_override, ['draft', 'archived'], true)
                            && ($materialized || ! $accepted['executable']))) {
                        return $hold('structural_successor_requires_decision');
                    }
                    $counterparts[] = ['row' => $number, 'path' => $path, 'column' => $column,
                        'import_key' => $accepted['key'], 'product_id' => $child?->id,
                        'fitment_note' => $product->fitments->first()->note];
                }
            }
        }
        if ($counterparts === []) {
            return $hold('structural_successor_missing');
        }

        return ['reason' => null, 'proof' => ['code' => 'structural_generic_retired', 'policy_version' => 1,
            'generation_id' => $generation->id, 'historical_type' => $product->partType->full_slug,
            'source_vehicles' => $vehicles, 'counterparts' => $counterparts]];
    }

    /** Existing IDs must stay exact; planned new offers must be materialized at finalization. */
    public function structuralEvidenceMatches(array $before, array $after): bool
    {
        foreach (['code', 'policy_version', 'generation_id', 'historical_type', 'source_vehicles'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                return false;
            }
        }
        if (count($before['counterparts']) !== count($after['counterparts'])) {
            return false;
        }
        foreach ($before['counterparts'] as $index => $old) {
            $now = $after['counterparts'][$index];
            $newId = $now['product_id'];
            if (! $newId || ($old['product_id'] !== null && $old['product_id'] !== $newId)) {
                return false;
            }
            unset($old['product_id'], $now['product_id']);
            if ($old !== $now) {
                return false;
            }
        }

        return true;
    }

    private function assertStructuralFiles(string $csv, string $xlsx, array $decision): void
    {
        foreach ([$csv => $decision['manifest_sha256'], $xlsx => $decision['excel_sha256'],
            $decision['excluded_path'] => $decision['excluded_sha256']] as $path => $hash) {
            clearstatcache(true, $path);
            if (! is_file($path) || ! hash_equals($hash, hash_file('sha256', $path))) {
                throw new RuntimeException('Approved structural source changed: '.$path);
            }
        }
    }

    private function structuralHash(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function structuralSeal(array $body): string
    {
        return hash_hmac('sha256', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), config('app.key'));
    }
}
