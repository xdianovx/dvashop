<?php

namespace App\Services\Search;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\StorefrontProductAvailability;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CatalogSearchDocument
{
    public const MODELS = [
        'makes' => VehicleMake::class,
        'models' => VehicleModel::class,
        'generations' => VehicleGeneration::class,
        'products' => Product::class,
    ];

    public static function relations(Model $model): array
    {
        return match (true) {
            $model instanceof VehicleModel => ['make'],
            $model instanceof VehicleGeneration => ['model.make'],
            $model instanceof Product => [
                'variants' => fn ($query) => app(StorefrontProductAvailability::class)->variants($query)->select(['id', 'product_id', 'sku'])->whereNotNull('sku')->where('sku', '<>', ''),
                'fitments.generation.model.make',
            ],
            default => [],
        };
    }

    private static function relation(Model $model, string $name): mixed
    {
        if (! $model->relationLoaded($name)) {
            throw new LogicException('Search documents require eager-loaded relation: '.$name);
        }

        return $model->getRelation($name);
    }

    public static function make(Model $model): array
    {
        $document = [
            'id' => (int) $model->getKey(),
            'title' => (string) $model->title,
            'position' => (int) $model->position,
            'is_active' => $model instanceof Product ? $model->status === ProductStatus::Active : (bool) $model->is_active,
            'vehicle_entities' => [],
        ];
        $canonical = [(string) $model->title];
        $aliases = [];
        $phoneticCanonical = [(string) $model->title];
        $phoneticAliases = [];

        if ($model instanceof VehicleMake || $model instanceof VehicleModel) {
            $document['search_aliases'] = $model->search_aliases ?? [];
            $aliases = array_merge($aliases, $document['search_aliases']);
            $phoneticAliases = array_merge($phoneticAliases, $document['search_aliases']);
        }
        if ($model instanceof VehicleMake) {
            $document['vehicle_entities'][] = self::vehicleEntity($model);
        }
        if ($model instanceof VehicleModel) {
            $make = self::relation($model, 'make');
            $document += self::makeFields($make);
            if ($make) {
                $document['vehicle_entities'][] = self::vehicleEntity($make, $model);
            }
            $canonical[] = (string) $make?->title;
            $aliases = array_merge($aliases, $make?->search_aliases ?? []);
            $phoneticCanonical[] = (string) $make?->title;
            $phoneticAliases = array_merge($phoneticAliases, $make?->search_aliases ?? []);
        }
        if ($model instanceof VehicleGeneration) {
            $parent = self::relation($model, 'model');
            $make = $parent ? self::relation($parent, 'make') : null;
            $document += [
                'body' => (string) $model->body,
                'years_label' => (string) $model->years_label,
                'model_id' => (int) $model->vehicle_model_id,
                'model_title' => (string) $parent?->title,
                'model_aliases' => $parent?->search_aliases ?? [],
            ];
            $document += self::makeFields($make);
            if ($make && $parent) {
                $document['vehicle_entities'][] = self::vehicleEntity($make, $parent, $model);
            }
            $canonical = array_merge($canonical, [(string) $model->body, (string) $model->years_label, (string) $parent?->title, (string) $make?->title]);
            $aliases = array_merge($aliases, $parent?->search_aliases ?? [], $make?->search_aliases ?? []);
            $phoneticCanonical = array_merge($phoneticCanonical, [(string) $parent?->title, (string) $make?->title]);
            $phoneticAliases = array_merge($phoneticAliases, $parent?->search_aliases ?? [], $make?->search_aliases ?? []);
        }
        if ($model instanceof Product) {
            $document['sku'] = (string) $model->sku;
            $document['category_id'] = (int) $model->product_category_id;
            $document['part_type_id'] = (int) $model->part_type_id;
            $document['variant_skus'] = SearchText::unique(self::relation($model, 'variants')->pluck('sku')->all());
            foreach (['make_ids', 'make_titles', 'make_aliases', 'model_ids', 'model_titles', 'model_aliases', 'generation_ids', 'generation_titles', 'bodies', 'years'] as $key) {
                $document[$key] = [];
            }
            foreach (self::relation($model, 'fitments') as $fitment) {
                $generation = self::relation($fitment, 'generation');
                $parent = $generation ? self::relation($generation, 'model') : null;
                $make = $parent ? self::relation($parent, 'make') : null;
                if (! $generation || ! $parent || ! $make) {
                    continue;
                }
                $values = [
                    'make_ids' => [$make->id], 'make_titles' => [$make->title], 'make_aliases' => $make->search_aliases ?? [],
                    'model_ids' => [$parent->id], 'model_titles' => [$parent->title], 'model_aliases' => $parent->search_aliases ?? [],
                    'generation_ids' => [$generation->id], 'generation_titles' => [$generation->title],
                    'bodies' => [$generation->body], 'years' => [$generation->years_label],
                ];
                foreach ($values as $key => $items) {
                    $document[$key] = array_merge($document[$key], $items);
                }
                $document['vehicle_entities'][] = self::vehicleEntity($make, $parent, $generation);
            }
            foreach (['make_ids', 'model_ids', 'generation_ids'] as $key) {
                $document[$key] = array_values(array_unique(array_map('intval', $document[$key])));
            }
            foreach (['make_titles', 'make_aliases', 'model_titles', 'model_aliases', 'generation_titles', 'bodies', 'years'] as $key) {
                $document[$key] = SearchText::unique($document[$key]);
            }
            $canonical = array_merge(
                [(string) $model->title],
                $document['make_titles'], $document['model_titles'], $document['generation_titles'], $document['bodies'], $document['years'],
            );
            $aliases = array_merge($document['make_aliases'], $document['model_aliases']);
            $phoneticCanonical = array_merge($document['make_titles'], $document['model_titles'], $document['generation_titles']);
            $phoneticAliases = $aliases;
        }

        $document += app(CatalogSearchEnrichmentService::class)->document(
            SearchText::unique($canonical),
            SearchText::unique($aliases),
            SearchText::unique($phoneticCanonical),
            SearchText::unique($phoneticAliases),
        );

        return $document;
    }

    private static function makeFields(?VehicleMake $make): array
    {
        return [
            'make_id' => (int) $make?->id,
            'make_title' => (string) $make?->title,
            'make_aliases' => $make?->search_aliases ?? [],
        ];
    }

    private static function vehicleEntity(VehicleMake $make, ?VehicleModel $model = null, ?VehicleGeneration $generation = null): array
    {
        $terms = array_merge(
            [(string) $make->title],
            $make->search_aliases ?? [],
            $model ? [(string) $model->title] : [],
            $model?->search_aliases ?? [],
            $generation ? [(string) $generation->title, (string) $generation->years_label, (string) $generation->body] : [],
        );

        return [
            'make_id' => (int) $make->getKey(),
            'make_title' => (string) $make->title,
            'make_aliases' => $make->search_aliases ?? [],
            'model_id' => $model ? (int) $model->getKey() : null,
            'model_title' => $model ? (string) $model->title : null,
            'model_aliases' => $model?->search_aliases ?? [],
            'generation_id' => $generation ? (int) $generation->getKey() : null,
            'generation_title' => $generation ? (string) $generation->title : null,
            'phonetic_prefix_terms' => array_values(array_unique(array_merge(...array_map(
                static fn (string $term): array => app(CatalogSearchPhoneticEncoder::class)->prefixTerms($term),
                SearchText::unique($terms),
            )))),
            'structured_terms' => app(CatalogSearchEnrichmentService::class)->structuredTerms(SearchText::unique($terms)),
        ];
    }

    /** @return list<string> */
    public static function canonicalAttributes(Model $model): array
    {
        return match (true) {
            $model instanceof VehicleMake => ['title'],
            $model instanceof VehicleModel => ['title', 'make_title'],
            $model instanceof VehicleGeneration => ['title', 'body', 'years_label', 'model_title', 'make_title'],
            default => ['title', 'make_titles', 'model_titles', 'generation_titles', 'bodies', 'years'],
        };
    }

    /** @return list<string> */
    public static function aliasAttributes(Model $model): array
    {
        return match (true) {
            $model instanceof VehicleMake => ['search_aliases'],
            $model instanceof VehicleModel => ['search_aliases', 'make_aliases'],
            $model instanceof VehicleGeneration => ['model_aliases', 'make_aliases'],
            default => ['make_aliases', 'model_aliases'],
        };
    }

    /** @return list<string> */
    public static function skuAttributes(Model $model): array
    {
        return $model instanceof Product ? ['sku', 'variant_skus'] : [];
    }

    /** @return list<string> */
    public static function originalAttributes(Model $model): array
    {
        return array_values(array_unique(array_merge(self::canonicalAttributes($model), self::aliasAttributes($model), self::skuAttributes($model))));
    }

    /** @return list<string> */
    public static function attributes(Model $model): array
    {
        return array_merge(self::originalAttributes($model), ['layout_terms', 'strict_transliteration_terms', 'phonetic_terms', 'phonetic_prefix_terms', 'structured_terms']);
    }
}
