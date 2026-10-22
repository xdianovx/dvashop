<?php

namespace App\Services\Import;

use App\Models\ImportRun;
use App\Models\Product;
use App\Models\VehicleGeneration;
use App\Support\CatalogText;
use RuntimeException;

/** One read-only resolver for preview, snapshot preflight and actual row processing. */
final class CatalogImportIdentity
{
    private ?array $existing = null;

    private array $otherMakes = [];

    private array $otherGenerations = [];

    private ?array $products = null;

    public function __construct(private readonly CatalogImportApprovedIdentities $approved) {}

    public static function version(string $description): string
    {
        $description = mb_strtolower(CatalogText::plain($description));
        $pre = str_contains($description, 'дорест') || str_contains($description, 'before');
        $post = str_contains(str_replace('дорест', '', $description), 'рест') || str_contains($description, 'after');
        // A shared applicability is one vehicle. Other descriptive labels are metadata.
        if (($pre && $post) || (! $pre && ! $post)) {
            return '';
        }

        return CatalogText::normKey($description, 'restyling');
    }

    public static function structuralKey(string $title, ?string $years, ?string $body): string
    {
        return CatalogText::normKey(trim($title.' '.$years.' '.CatalogImportFormat::body($body ?? '')), 'generation', 120);
    }

    public function vehicle(array $row, ?ImportRun $run = null): array
    {
        $result = $this->unapprovedVehicle($row, $run);
        $entry = $this->approved->vehicleEntry($row, $run);
        if ($entry === null) {
            return $result;
        }
        try {
            $this->approved->validate($entry, $row, $entry['part_type_path'], 'catalog');
        } catch (RuntimeException $e) {
            return array_replace($result, ['error' => $e->getMessage(), 'code' => 'approved_identity_conflict']);
        }

        return array_replace($result, ['generation' => $entry['vehicle']['historical']['norm_key'],
            'generation_id' => $entry['vehicle_generation_id'], 'error' => null, 'approved_identity' => true]);
    }

    public function isApprovedVehicle(array $row, ?ImportRun $run = null): bool
    {
        return $this->approved->vehicleEntry($row, $run) !== null;
    }

    /** Return the existing relation before the importer can create a VW make/model. */
    public function approvedGeneration(array $row, ?ImportRun $run = null): ?VehicleGeneration
    {
        if (! $this->isApprovedVehicle($row, $run)) {
            return null;
        }
        $result = $this->vehicle($row, $run);
        if ($result['error'] !== null) {
            throw new RuntimeException($result['error']);
        }

        return VehicleGeneration::findOrFail($result['generation_id']);
    }

    private function unapprovedVehicle(array $row, ?ImportRun $run = null): array
    {
        if ($this->existing === null) {
            $this->loadExisting();
        }
        $make = CatalogText::normKey($row[1], 'make', 120);
        $model = CatalogText::normKey($row[2], 'model', 120);
        $base = self::structuralKey($row[3], $row[4], $row[5]);
        $version = self::version($row['_restyling'] ?? '');
        $generation = $version === '' ? $base : self::structuralKey($row[3].' restyling '.$version, $row[4], $row[5]);
        $matches = $this->historical($this->existing[$make.':'.$model.':'.$base] ?? [], $run);
        $compatible = array_filter($matches, fn ($v) => $v['version'] === $version);
        // A single historical, unqualified applicability can retain its identity.
        // Competing incoming versions are rejected by the whole-file preflight.
        if ($compatible === [] && $version !== '' && count($matches) === 1 && reset($matches)['version'] === '') {
            $compatible = $matches;
        }
        $result = ['make' => $make, 'model' => $model, 'generation' => $generation, 'proposed_generation' => $generation,
            'generation_id' => null, 'version' => $version, 'error' => null, 'candidate_ids' => []];
        if ($version !== '' && $matches !== [] && $compatible === []
            && ! array_filter($matches, fn ($v) => $v['version'] === '')) {
            // An explicitly different version can be created; it never adopts an old ID.
            return $result;
        }
        if (count($compatible) > 1 || ($matches !== [] && $compatible === [])) {
            return array_replace($result, ['error' => 'Неоднозначные существующие автомобили или применимости: '.$make.':'.$model.':'.$base,
                'code' => 'ambiguous_vehicle', 'candidate_ids' => array_keys($matches)]);
        }
        if ($compatible !== []) {
            $match = reset($compatible);
            $ordinal = preg_match('/^\s*(\d+)\b/u', $row[3], $number) ? $number[1] : null;
            $neighbors = $this->historical($this->otherGenerations[$make.':'.$model.':'.self::structuralKey('', $row[4], $row[5])] ?? [], $run);
            $contradictions = array_filter($neighbors, fn ($v) => $ordinal !== null && $v['ordinal'] !== null && $ordinal !== $v['ordinal']);
            if ($contradictions !== []) {
                return array_replace($result, ['error' => 'При тех же марке, модели, годах и кузове в истории указано другое поколение. Требуется проверка исходной строки.',
                    'code' => 'generation_change', 'candidate_ids' => array_keys($neighbors)]);
            }

            return array_replace($result, ['make' => $match['make'], 'model' => $match['model'],
                'generation' => $match['generation'], 'generation_id' => $match['id']]);
        }
        $other = $this->historical($this->otherMakes[$model.':'.$base] ?? [], $run);
        if ($other !== []) {
            return array_replace($result, ['error' => 'Возможное изменение марки или рынка; автоматическое объединение запрещено.',
                'code' => 'make_or_market_change', 'candidate_ids' => array_keys($other)]);
        }
        $near = $this->historical($this->otherGenerations[$make.':'.$model.':'.self::structuralKey('', $row[4], $row[5])] ?? [], $run);
        if ($near !== []) {
            return array_replace($result, ['error' => 'При тех же марке, модели, годах и кузове отличается поколение.',
                'code' => 'generation_change', 'candidate_ids' => array_keys($near)]);
        }

        return $result;
    }

    public function resolve(array $row, string $partPath, string $source, ?ImportRun $run = null): array
    {
        // A vehicle-level allowlist hit must never approve an unlisted part.
        $vehicle = $this->unapprovedVehicle($row, $run);
        $newKey = self::fromSegments($source, $vehicle['make'], $vehicle['model'], $vehicle['proposed_generation'], $partPath);
        $stableKey = self::fromSegments($source, $vehicle['make'], $vehicle['model'], $vehicle['generation'], $partPath);
        $result = $vehicle + ['import_key' => $stableKey, 'new_import_key' => $newKey, 'product_id' => null, 'historical_candidates' => []];
        $entry = $this->approved->entry($newKey, $run);
        if ($entry !== null) {
            try {
                $this->approved->validate($entry, $row, $partPath, $source);
            } catch (RuntimeException $e) {
                return array_replace($result, ['error' => $e->getMessage(), 'code' => 'approved_identity_conflict',
                    'historical_candidates' => [['id' => $entry['product_id'], 'import_key' => $entry['import_key']]]]);
            }

            return array_replace($result, ['import_key' => $entry['import_key'], 'product_id' => $entry['product_id'],
                'generation' => $entry['vehicle']['historical']['norm_key'], 'generation_id' => $entry['vehicle_generation_id'],
                'error' => null, 'approved_identity' => true,
                'historical_candidates' => [['id' => $entry['product_id'], 'import_key' => $entry['import_key']]]]);
        }
        if ($vehicle['error'] !== null) {
            $this->loadProducts();
            foreach ($vehicle['candidate_ids'] as $id) {
                foreach ($this->products[$source][$id][$partPath] ?? [] as $product) {
                    $result['historical_candidates'][] = $product;
                }
            }

            return $result;
        }
        $matches = $this->historicalProducts($vehicle['generation_id'], $partPath, $source);
        $result['historical_candidates'] = $matches;
        if (count($matches) > 1) {
            return array_replace($result, ['error' => 'Несколько исторических товаров соответствуют автомобилю и типу детали.',
                'code' => 'ambiguous_product', 'historical_candidates' => $matches]);
        }
        if ($matches !== []) {
            $product = reset($matches);
            $result['import_key'] = $product['import_key'];
            $result['product_id'] = $product['id'];
        }

        return $result;
    }

    public function key(array $row, string $partPath, string $source, ?ImportRun $run = null): string
    {
        $result = $this->resolve($row, $partPath, $source, $run);
        if ($result['error'] !== null) {
            throw new RuntimeException($result['error']);
        }

        return $result['import_key'];
    }

    public function forGeneration(VehicleGeneration $generation, string $partPath, string $source): string
    {
        $generation->loadMissing('model.make');
        $matches = $this->historicalProducts($generation->id, $partPath, $source);
        if (count($matches) > 1) {
            throw new RuntimeException('Несколько исторических товаров соответствуют автомобилю и типу детали.');
        }

        return $matches !== [] ? reset($matches)['import_key'] : self::fromSegments($source,
            $generation->model->make->norm_key, $generation->model->norm_key, $generation->norm_key, $partPath);
    }

    private function historicalProducts(?int $generationId, string $path, string $source): array
    {
        if ($generationId === null) {
            return [];
        }
        $this->loadProducts();

        return array_values($this->products[$source][$generationId][$path] ?? []);
    }

    /** Keep real history and competing writers; exclude only this FULL's new vehicles. */
    private function historical(array $candidates, ?ImportRun $run): array
    {
        $ceiling = $run?->prepared_rows['full_scope']['identity_baseline']['generation_id_ceiling'] ?? null;
        if ($run?->import_mode !== 'full' || ! is_int($ceiling)) {
            return $candidates;
        }

        return array_filter($candidates, fn ($v) => ! ($v['id'] > $ceiling
            && (string) $v['last_import_run_id'] === (string) $run->id
            && $v['import_source'] === $run->type));
    }

    private function loadProducts(): void
    {
        if ($this->products !== null) {
            return;
        }
        $this->products = [];
        Product::withTrashed()->whereNotNull('import_key')->whereNotNull('import_source')
            ->select(['id', 'import_source', 'import_key', 'part_type_id'])
            ->with('fitments:id,product_id,vehicle_generation_id', 'partType:id,full_slug')
            ->chunkById(500, function ($products): void {
                foreach ($products as $product) {
                    foreach ($product->fitments as $fitment) {
                        $this->products[$product->import_source][$fitment->vehicle_generation_id][$product->partType?->full_slug][$product->id] =
                            ['id' => $product->id, 'import_key' => $product->import_key];
                    }
                }
            });
    }

    public function loadExisting(): void
    {
        $this->existing = $this->otherMakes = $this->otherGenerations = [];
        $this->products = null;
        VehicleGeneration::with('model.make')->chunkById(500, function ($generations): void {
            foreach ($generations as $generation) {
                $model = $generation->model;
                $make = $model?->make;
                if (! $make) {
                    continue;
                }
                $title = preg_replace('/\s*\[[^\]]*\]/u', '', $generation->title);
                $description = $generation->restyling ?: (preg_match('/\[([^\]]*)\]/u', $generation->title, $m) ? $m[1] : '');
                $base = self::structuralKey($title, $generation->years_label, $generation->body);
                $v = ['id' => $generation->id, 'make' => $make->norm_key, 'model' => $model->norm_key,
                    'last_import_run_id' => $generation->last_import_run_id, 'import_source' => $generation->import_source,
                    'generation' => $generation->norm_key, 'version' => self::version($description),
                    'ordinal' => preg_match('/^\s*(\d+)\b/u', $title, $number) ? $number[1] : null];
                $this->existing[$make->norm_key.':'.$model->norm_key.':'.$base][$generation->id] = $v;
                $this->otherMakes[$model->norm_key.':'.$base][$generation->id] = $v;
                $this->otherGenerations[$make->norm_key.':'.$model->norm_key.':'.self::structuralKey('', $generation->years_label, $generation->body)][$generation->id] = $v;
            }
        });
    }

    public static function fromSegments(string $source, string $make, string $model, string $generation, string $partPath): string
    {
        return CatalogText::stableKey([$source, $make, $model, $generation, ...explode('/', $partPath)], ':', 240, 'catalog');
    }
}
