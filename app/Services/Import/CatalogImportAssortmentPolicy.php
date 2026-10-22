<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\VehicleGeneration;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\SpreadsheetReader;
use App\Support\CatalogText;

/** Evidence for the existing FULL snapshot, not a second importer or identity resolver. */
final class CatalogImportAssortmentPolicy
{
    public function apply(array $plan, string $xlsx, bool $materialized = false): array
    {
        $reader = app(SpreadsheetReader::class);
        $identity = app(CatalogImportIdentity::class);
        $headers = $reader->readMergedDetailHeaders($xlsx);
        $paths = [];
        foreach ($headers as $column => $header) {
            $paths[app(ImportPartTypeResolver::class)->structuralPath($header)][] = $column;
        }
        $rows = [];
        foreach ($reader->catalogRows($xlsx) as $entry) {
            if ($entry['row'] !== null) {
                $rows[$entry['number']] = $entry['row'] + ['_row_number' => $entry['number']];
            }
        }
        $generations = VehicleGeneration::withTrashed()->with(['model' => fn ($q) => $q->withTrashed()
            ->with(['make' => fn ($q) => $q->withTrashed()])])->get()->keyBy('id');
        $blocked = [];
        $exceptions = [];
        $raw = $reader->readHeaderRows($xlsx, 100000);
        $columns = CatalogImportFormat::columns($reader->readHeaderRows($xlsx, 2));
        foreach ($plan['diagnostics']['skipped_rows'] as $skip) {
            $pattern = [];
            foreach (['make', 'model'] as $field) {
                $pattern[$field] = $this->canonical($raw[$skip['row']][$columns[$field][0]] ?? '');
            }
            $matches = $generations->filter(fn ($g) => $this->matches($g, $pattern));
            if ($matches->isEmpty()) {
                // Unknown model cannot narrow absence to an invented applicability.
                $pattern['model'] = '';
                $matches = $generations->filter(fn ($g) => $this->matches($g, $pattern));
            }
            foreach ($matches as $g) {
                $blocked[$g->id][] = ['code' => 'incomplete_excel_row', 'row' => $skip['row']];
            }
            $exceptions[] = ['code' => 'incomplete_excel_row'] + $skip;
        }
        foreach ($plan['expected_products'] as $expected) {
            if ($expected['state'] !== 'unresolved') {
                continue;
            }
            foreach ($expected['source_rows'] as $number) {
                $r = $identity->resolve($rows[$number], $expected['part_type_full_slug'], $plan['source']);
                $ids = array_filter([...$r['candidate_ids'], $r['generation_id']]);
                // Collisions can mark the *previous* row unresolved with an empty candidate list.
                $v = $identity->vehicle($rows[$number]);
                $ids = array_unique([...$ids, ...$v['candidate_ids'], ...array_filter([$v['generation_id']])]);
                foreach ($ids as $id) {
                    $blocked[$id][] = ['code' => 'conflicting_identity', 'row' => $number];
                }
            }
            $exceptions[] = ['code' => 'unresolved_identity', 'key' => $expected['import_key'], 'rows' => $expected['source_rows']];
        }
        $vehicleRows = [];
        foreach ($rows as $number => $row) {
            $v = $identity->vehicle($row);
            foreach (array_unique([...$v['candidate_ids'], ...array_filter([$v['generation_id']])]) as $id) {
                if ($v['error'] !== null) {
                    $blocked[$id][] = ['code' => $v['code'] ?? 'conflicting_vehicle', 'row' => $number];
                } else {
                    $vehicleRows[$id][$number] = $row;
                }
            }
        }
        $products = Product::withTrashed()->where('import_source', $plan['source'])->with('fitments', 'partType')->get()->keyBy('id');
        $protected = array_fill_keys(app(CatalogImportProtections::class)->ids(), true);
        $preserve = [];
        $execution = [];
        $snapshot = [];
        $rowScope = [];
        $coverage = [];
        foreach ($plan['expected_products'] as $expected) {
            if ($expected['state'] === 'unresolved') {
                continue;
            }
            $p = $expected['product_id'] ? $products->get($expected['product_id']) : null;
            $resolution = $identity->resolve($rows[$expected['source_rows'][0]], $expected['part_type_full_slug'], $plan['source']);
            $relationUncertain = $p && ($p->fitments->count() !== 1 || $resolution['product_id'] !== $p->id || $resolution['import_key'] !== $p->import_key);
            $v = $identity->vehicle($rows[$expected['source_rows'][0]]);
            $g = $generations->get($v['generation_id']);
            $uncertain = $p && $p->fitments->contains(fn ($f) => isset($blocked[$f->vehicle_generation_id]));
            $held = $p && isset($protected[$p->id]);
            $guards = app(CatalogImportProtections::class);
            $inactive = $g && ($g->trashed() || ! $g->model || $g->model->trashed() || ! $g->model->make || $g->model->make->trashed()
                || (! $g->is_active && $guards->protectsVehicle('generation', $g->id))
                || (! $g->model->is_active && $guards->protectsVehicle('model', $g->model->id))
                || (! $g->model->make->is_active && $guards->protectsVehicle('make', $g->model->make->id)));
            if (! $relationUncertain && ! $uncertain && ! isset($blocked[$v['generation_id']]) && $v['error'] === null) {
                foreach ($expected['source_rows'] as $number) {
                    $coverage[$number][$expected['part_type_full_slug']] = ['key' => $expected['import_key'],
                        'generation_id' => $v['generation_id'], 'executable' => ! $held && ! $inactive];
                }
            }
            if ($uncertain || $held || $inactive || $relationUncertain) {
                $reason = $relationUncertain ? 'historical_relation_requires_decision' : ($uncertain ? 'uncertain_applicability' : ($held ? 'permanent_protection_present' : 'inactive_vehicle_requires_decision'));
                if ($p) {
                    $preserve[$p->id] = $reason;
                    $snapshot[] = $expected;
                }
                $exceptions[] = ['code' => $reason, 'product_id' => $p?->id, 'key' => $expected['import_key'], 'rows' => $expected['source_rows']];

                continue;
            }
            $execution[] = $expected;
            $snapshot[] = $expected;
            foreach ($expected['source_rows'] as $number) {
                $rowScope[$number][$expected['part_type_full_slug']] = ['key' => $expected['import_key'], 'product_id' => $expected['product_id']];
            }
        }
        $archives = [];
        $productsByKey = $products->keyBy('import_key');
        foreach ($plan['missing_products'] as &$missing) {
            $p = $products->get($missing['product_id']);
            $reason = null;
            $evidence = [];
            if (in_array($p->publication_override, ['draft', 'archived'], true)) {
                $reason = 'manual_publication_hold';
            } elseif ($p->fitments->count() !== 1) {
                $reason = 'missing_or_multiple_fitments';
            } else {
                $gid = $p->fitments->first()->vehicle_generation_id;
                $g = $generations->get($gid);
                if (isset($blocked[$gid])) {
                    $reason = 'uncertain_applicability';
                    $evidence = $blocked[$gid];
                } elseif (! $g || ! $g->model || ! $g->model->make || ! $p->partType) {
                    $reason = 'incomplete_historical_identity';
                } elseif (! isset($vehicleRows[$gid])) {
                    $evidence = ['code' => 'complete_vehicle_absent', 'generation_id' => $gid];
                } else {
                    $path = $p->partType->full_slug;
                    $relatedPaths = isset($paths[$path]) ? [$path] : array_values(array_filter(array_keys($paths), fn ($candidate) => $this->sameFamily($path, $candidate)));
                    if ($relatedPaths === []) {
                        $reason = 'unmapped_historical_type';
                    } else {
                        $positive = [];
                        foreach ($vehicleRows[$gid] as $number => $row) {
                            foreach ($relatedPaths as $candidate) {
                                foreach ($paths[$candidate] as $column) {
                                    if (CatalogImportCell::parse($row['_details'][$column] ?? null) !== null) {
                                        $positive[] = ['row' => $number, 'path' => $candidate];
                                    }
                                }
                            }
                        }
                        if ($positive !== []) {
                            $reason = isset($paths[$path]) ? 'positive_applicability_requires_identity_decision' : 'structural_type_requires_mapping';
                            $evidence = $positive;
                            if (! isset($paths[$path]) && in_array($path, ['porog', 'arka/vnutrenniaia'], true)) {
                                $structural = app(ImportProductFactory::class)->proveStructuralRetirement(
                                    $p, $g, $vehicleRows[$gid], $paths, $coverage, $productsByKey, $materialized,
                                );
                                $reason = $structural['reason'];
                                $evidence = $structural['proof'] ?? $positive;
                            }
                        } else {
                            $evidence = ['code' => isset($paths[$path]) ? 'explicit_negative_cells' : 'entire_part_family_absent',
                                'rows' => array_keys($vehicleRows[$gid]), 'checked_paths' => $relatedPaths, 'generation_id' => $gid];
                        }
                    }
                }
            }
            if ($reason !== null) {
                $preserve[$p->id] = $reason;
                $missing['action'] = 'preserve_exception';
                $missing['reason'] = $reason;
                $missing['evidence'] = $evidence;
            } else {
                $missing['absence_proof'] = $evidence;
                if ($p->status->value !== 'archived' || $p->import_present === null || (bool) $p->import_present) {
                    $missing['action'] = 'archive';
                    $archives[$p->id] = ['product_id' => $p->id, 'import_key' => $p->import_key,
                        'status' => $p->status->value, 'import_present' => $p->getRawOriginal('import_present'),
                        'updated_at' => $p->getRawOriginal('updated_at'), 'fingerprint' => self::fingerprint($p), 'evidence' => $evidence];
                } else {
                    $missing['action'] = 'keep_archived';
                }
            }
        }
        unset($missing);
        foreach ($products as $p) {
            if ($p->fitments->contains(fn ($f) => isset($blocked[$f->vehicle_generation_id]))) {
                $preserve[$p->id] ??= 'uncertain_applicability';
            }
        }
        if (array_intersect(array_keys($archives), array_keys($preserve))) {
            throw new \RuntimeException('Архивация пересекается с неопределённой областью.');
        }
        $plan['execution_products'] = $execution;
        $plan['snapshot_products'] = $snapshot;
        $plan['execution_allowed'] = $snapshot !== [];
        $plan['excel_complete'] = $plan['valid'] && $exceptions === [] && $preserve === [];
        $plan['exceptions'] = $exceptions;
        $plan['full_scope'] = ['version' => 1, 'excel_sha256' => $plan['file']['sha256'],
            'identity_baseline' => ['generation_id_ceiling' => (int) ($generations->keys()->max() ?? 0),
                'approved_vw_active' => app(CatalogImportApprovedIdentities::class)->active()],
            'rows' => $rowScope, 'archives' => $archives, 'preserve' => $preserve,
            'readonly_present' => array_values(array_filter(array_column($snapshot, 'product_id'), fn ($id) => $id && isset($preserve[$id])))];
        $plan['execution_counts'] = ['new' => count(array_filter($execution, fn ($e) => $e['product_id'] === null)),
            'updates' => count(array_filter($execution, fn ($e) => $e['product_id'] !== null)),
            'archive' => count(array_filter($archives, fn ($a) => $a['status'] !== 'archived')),
            'archive_presence_updates' => count($archives), 'preserved' => count($preserve),
            'manual_holds' => $products->filter(fn ($p) => in_array($p->publication_override, ['draft', 'archived'], true))->count()];
        $structuralArchives = array_filter($archives, fn ($a) => ($a['evidence']['code'] ?? null) === 'structural_generic_retired');
        $structuralHolds = array_filter($plan['missing_products'], fn ($m) => ($m['action'] ?? null) === 'preserve_exception'
            && in_array($products->get($m['product_id'])?->partType?->full_slug, ['porog', 'arka/vnutrenniaia', 'arka/karman-zadniaia'], true));
        $plan['structural_counts'] = ['archive' => count($structuralArchives), 'held' => count($structuralHolds),
            'held_by_reason' => array_count_values(array_column($structuralHolds, 'reason'))];
        $plan['execution_counts']['structural_archive'] = count($structuralArchives);
        $plan['full_scope']['hash'] = hash('sha256', json_encode([$plan['full_scope'], $execution], JSON_THROW_ON_ERROR));

        return $plan;
    }

    public static function fingerprint(Product $p): string
    {
        $p->loadMissing('fitments');

        return hash('sha256', json_encode([$p->getRawOriginal(), $p->fitments->sortBy('id')->map(fn ($f) => $f->getRawOriginal())->values()->all()], JSON_THROW_ON_ERROR));
    }

    public static function seal(int $runId, array $scope): string
    {
        unset($scope['seal']);
        $sort = function ($value) use (&$sort) {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        return hash_hmac('sha256', $runId.':'.json_encode($sort($scope), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function sameFamily(string $old, string $new): bool
    {
        if ($old === 'arka/vnutrenniaia') {
            return str_starts_with($new, 'arka/vnutrenniaia');
        }
        $a = explode('/', $old)[0];
        $b = explode('/', $new)[0];

        return $a === $b || ($old === 'usilitel/soedinitel-porogov' && in_array($b, ['usilitel', 'soedinitel'], true));
    }

    private function canonical(mixed $value): string
    {
        return preg_replace('/\s*([\p{Pd}()\/])\s*/u', '$1', preg_replace('/\p{Pd}/u', '-', mb_strtolower(CatalogText::plain($value, 1000))));
    }

    private function matches(VehicleGeneration $g, array $pattern): bool
    {
        $make = fn ($v) => str_replace('volkswagen', 'vw', preg_replace('/\s+(eur|внутренний)$/u', '', $this->canonical($v)));

        return ($pattern['make'] === '' || $make($pattern['make']) === $make($g->model?->make?->title))
            && ($pattern['model'] === '' || $pattern['model'] === $this->canonical($g->model?->title));
    }
}
