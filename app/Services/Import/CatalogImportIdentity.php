<?php

namespace App\Services\Import;

use App\Models\VehicleGeneration;
use App\Support\CatalogText;
use RuntimeException;

final class CatalogImportIdentity
{
    private ?array $existing = null;

    /** Resolve only exact normalized structural identities; never guess renamed vehicles. */
    public function key(array $row, string $partPath, string $source): string
    {
        if ($this->existing === null) {
            $this->loadExisting();
        }
        $make = CatalogText::normKey($row[1], 'make', 120);
        $model = CatalogText::normKey($row[2], 'model', 120);
        $generation = CatalogText::normKey(trim($row[3].' '.$row[4].' '.$row[5]), 'generation', 120);
        $identity = $make.':'.$model.':'.$generation;
        $matches = $this->existing[$identity] ?? [];
        if (count($matches) > 1) {
            throw new RuntimeException('Неоднозначные существующие автомобили: '.$identity);
        }
        [$make, $model, $generation] = $matches[0] ?? [$make, $model, $generation];

        return self::fromSegments($source, $make, $model, $generation, $partPath);
    }

    public function loadExisting(): void
    {
        $this->existing = [];
        VehicleGeneration::with('model.make')->chunkById(500, function ($generations): void {
            foreach ($generations as $generation) {
                $model = $generation->model;
                $make = $model->make;
                $key = $make->norm_key.':'.$model->norm_key.':'.CatalogText::normKey(trim($generation->title.' '.$generation->years_label.' '.CatalogImportFormat::body($generation->body ?? '')), 'generation', 120);
                $this->existing[$key][] = [$make->norm_key, $model->norm_key, $generation->norm_key];
            }
        });
    }

    public static function fromSegments(string $source, string $make, string $model, string $generation, string $partPath): string
    {
        return CatalogText::stableKey([$source, $make, $model, $generation, ...explode('/', $partPath)], ':', 240, 'catalog');
    }
}
