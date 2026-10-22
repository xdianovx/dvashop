<?php

namespace App\Services\Import;

use App\Models\ImportRun;
use App\Models\VehicleGeneration;
use Illuminate\Support\Facades\DB;

class VehicleGenerationImageDispatchGuard
{
    public function queue(ImportRun $run, VehicleGeneration $generation, string $url, array $alternatives = []): bool
    {
        return app(ImportImageTasks::class)->enqueue($run, 'vehicle', $generation->id, $url, $alternatives);
    }

    /** Legacy reservation API; current producers use queue() for atomic registration/count/dispatch. */
    public function reserve(ImportRun $run, VehicleGeneration $generation, string $url): bool
    {
        return DB::transaction(function () use ($run, $generation, $url): bool {
            $lockedRun = ImportRun::query()
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $dispatchKey = $generation->getKey().':'.hash('sha256', $url);
            $dispatchKeys = array_values(array_filter(
                $lockedRun->queued_vehicle_image_keys ?? [],
                is_string(...),
            ));

            if (in_array($dispatchKey, $dispatchKeys, true)) {
                return false;
            }

            $dispatchKeys[] = $dispatchKey;

            $lockedRun->forceFill([
                'queued_vehicle_image_keys' => $dispatchKeys,
            ])->save();

            return true;
        });
    }
}
