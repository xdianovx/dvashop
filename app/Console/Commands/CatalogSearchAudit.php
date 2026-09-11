<?php

namespace App\Console\Commands;

use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Search\CatalogSearchPhoneticAudit;
use Illuminate\Console\Command;

class CatalogSearchAudit extends Command
{
    protected $signature = 'catalog-search:audit';

    protected $description = 'Audit catalog proper-name phonetic collisions and canonical prefix safety.';

    public function handle(CatalogSearchPhoneticAudit $audit): int
    {
        $entities = [];
        $models = [];
        VehicleMake::query()->select(['id', 'title'])->orderBy('id')->each(function (VehicleMake $make) use (&$entities): void {
            $entities[] = ['type' => 'make', 'id' => (int) $make->id, 'title' => (string) $make->title];
        });
        VehicleModel::query()->select(['id', 'title'])->orderBy('id')->each(function (VehicleModel $model) use (&$entities, &$models): void {
            $entry = ['type' => 'model', 'id' => (int) $model->id, 'title' => (string) $model->title];
            $entities[] = $entry;
            $models[] = $entry;
        });

        $this->line(json_encode([
            'phonetic' => $audit->compare($entities),
            'prefix' => $audit->prefix($models),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
