<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\VehicleMake;
use App\Services\PublicVehicleCatalogVisibility;
use Illuminate\Http\JsonResponse;

final class VehicleMakeModelsController extends Controller
{
    public function __construct(
        private readonly PublicVehicleCatalogVisibility $vehicleVisibility,
    ) {}

    public function __invoke(string $makeSlug): JsonResponse
    {
        $make = $this->vehicleVisibility->makes(VehicleMake::query())
            ->where('slug', $makeSlug)
            ->firstOrFail();

        $models = $this->vehicleVisibility->models($make->models())
            ->orderBy('position')
            ->orderBy('title')
            ->orderBy('id')
            ->get(['title', 'slug'])
            ->map(fn ($model): array => [
                'title' => (string) $model->title,
                'slug' => (string) $model->slug,
            ])
            ->values();

        return response()->json($models);
    }
}
