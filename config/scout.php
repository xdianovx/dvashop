<?php

use App\Models\Product;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;

$common = [
    'filterableAttributes' => ['is_active', 'category_id', 'part_type_id'],
    'sortableAttributes' => ['position'],
    'rankingRules' => ['words', 'typo', 'proximity', 'exactness', 'attribute', 'sort'],
    'typoTolerance' => [
        'minWordSizeForTypos' => ['oneTypo' => 5, 'twoTypos' => 10],
        'disableOnAttributes' => ['sku', 'variant_skus', 'layout_terms', 'strict_transliteration_terms', 'phonetic_terms', 'phonetic_prefix_terms', 'structured_terms'],
    ],
    'pagination' => ['maxTotalHits' => 20000],
];
$product = $common;
$product['filterableAttributes'] = array_values(array_unique(array_merge(
    $common['filterableAttributes'],
    ['make_ids', 'model_ids', 'generation_ids'],
)));

return [
    'driver' => env('SCOUT_DRIVER', null),
    'prefix' => env('SCOUT_PREFIX', 'dvashop_local_'),
    'queue' => env('SCOUT_QUEUE', true) ? ['connection' => env('SCOUT_QUEUE_CONNECTION', 'catalog-search'), 'queue' => 'catalog-search'] : false,
    'after_commit' => true,
    'chunk' => ['searchable' => 100, 'unsearchable' => 100],
    'soft_delete' => false,
    'identify' => false,
    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://meilisearch:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [
            VehicleMake::class => $common + ['searchableAttributes' => ['title', 'search_aliases', 'layout_terms', 'strict_transliteration_terms', 'phonetic_terms', 'phonetic_prefix_terms', 'structured_terms']],
            VehicleModel::class => $common + ['searchableAttributes' => ['title', 'make_title', 'search_aliases', 'make_aliases', 'layout_terms', 'strict_transliteration_terms', 'phonetic_terms', 'phonetic_prefix_terms', 'structured_terms']],
            VehicleGeneration::class => $common + ['searchableAttributes' => ['title', 'body', 'years_label', 'model_title', 'make_title', 'model_aliases', 'make_aliases', 'layout_terms', 'strict_transliteration_terms', 'phonetic_terms', 'phonetic_prefix_terms', 'structured_terms']],
            Product::class => $product + ['searchableAttributes' => ['title', 'sku', 'variant_skus', 'make_titles', 'model_titles', 'generation_titles', 'bodies', 'years', 'make_aliases', 'model_aliases', 'layout_terms', 'strict_transliteration_terms', 'phonetic_terms', 'phonetic_prefix_terms', 'structured_terms']],
        ],
    ],
];
