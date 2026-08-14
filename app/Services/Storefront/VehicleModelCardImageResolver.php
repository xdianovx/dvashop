<?php

namespace App\Services\Storefront;

use App\Models\VehicleGeneration;
use App\Models\VehicleModel;
use App\Services\Media\MediaUrlService;
use App\Services\PublicVehicleCatalogVisibility;
use Illuminate\Support\Collection;

final readonly class VehicleModelCardImageResolver
{
    public function __construct(
        private PublicVehicleCatalogVisibility $visibility,
        private MediaUrlService $media,
    ) {}

    /**
     * @param  Collection<int, VehicleModel>  $models
     * @return Collection<int, string>
     */
    public function resolve(Collection $models): Collection
    {
        $modelIds = $models
            ->map(fn (VehicleModel $model): int => (int) $model->getKey())
            ->filter()
            ->unique()
            ->values();

        if ($modelIds->isEmpty()) {
            return collect();
        }

        $candidates = $this->visibility->generations(VehicleGeneration::query())
            ->whereIn('vehicle_model_id', $modelIds)
            ->whereNotNull('image')
            ->where('image', '<>', '')
            ->orderBy('vehicle_model_id')
            ->orderBy('position')
            ->orderBy('title')
            ->orderBy('years_label')
            ->orderBy('body')
            ->orderBy('id')
            ->get(['id', 'vehicle_model_id', 'title', 'years_label', 'body', 'image', 'position']);

        $images = [];

        foreach ($candidates as $generation) {
            $modelId = (int) $generation->vehicle_model_id;

            if (isset($images[$modelId])) {
                continue;
            }

            $url = $this->media->publicDiskUrl($generation->image, 'public');

            if ($url !== null) {
                $images[$modelId] = $url;
            }
        }

        return collect($images);
    }
}
