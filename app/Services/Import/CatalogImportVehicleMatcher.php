<?php

namespace App\Services\Import;

use App\Models\VehicleGeneration;
use App\Models\VehicleModel;
use App\Support\CatalogText;

final class CatalogImportVehicleMatcher
{
    /** @var array<int, array<string, array<int, VehicleGeneration>>> */
    private array $generations = [];

    public function find(VehicleModel $model, string $title, ?string $years, ?string $body): ?VehicleGeneration
    {
        $modelId = (int) $model->getKey();
        if (! isset($this->generations[$modelId])) {
            $this->generations[$modelId] = [];
            foreach ($model->generations()->get() as $generation) {
                $key = $this->key($generation->title, $generation->years_label, $generation->body);
                $this->generations[$modelId][$key][] = $generation;
            }
        }
        $matches = $this->generations[$modelId][$this->key($title, $years, $body)] ?? [];

        // Ambiguous old records must never be merged by guessing.
        return count($matches) === 1 ? $matches[0]->fresh() : null;
    }

    private function key(string $title, ?string $years, ?string $body): string
    {
        return CatalogText::normKey(trim($title.' '.$years.' '.CatalogImportFormat::body($body ?? '')), 'generation');
    }
}
