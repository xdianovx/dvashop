<?php

use App\Models\Product;
use App\Models\ProductFitment;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Search\CatalogProductQueryPlanner;
use App\Services\Search\DatabaseCatalogSearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('product query planner consumes only coherent vehicle entities and preserves residual product text', function (string $query): void {
    $make = VehicleMake::factory()->create(['title' => 'Toyota', 'search_aliases' => null]);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Probox', 'search_aliases' => null]);
    VehicleGeneration::factory()->forVehicleModel($model)->create();

    $planner = app(CatalogProductQueryPlanner::class);
    $phrases = $planner->phrases($query);
    $plan = $planner->finalize($query, $phrases, [
        '1:1' => ['makes' => [$make->id], 'models' => [], 'generations' => []],
        '2:1' => ['makes' => [], 'models' => [$model->id], 'generations' => []],
    ]);

    expect($plan['active'])->toBeTrue()
        ->and($plan['residual_product_terms'])->toBe(['арка'])
        ->and($plan['consumed_tokens'])->toHaveCount(2)
        ->and($plan['product_filters'])->toBe(['make_id' => $make->id, 'model_id' => $model->id])
        ->and($plan['hierarchy_status'])->toBe('coherent');
})->with([
    'russian vehicle' => 'арка тойота пробокс',
    'english vehicle' => 'арка Toyota Probox',
    'mixed make' => 'арка Toyota пробокс',
    'mixed model' => 'арка тойота Probox',
]);

test('make context narrows duplicate model candidate sets before model is consumed', function (string $firstMakeTitle, string $secondMakeTitle, string $modelTitle): void {
    $firstMake = VehicleMake::factory()->create(['title' => $firstMakeTitle]);
    $secondMake = VehicleMake::factory()->create(['title' => $secondMakeTitle]);
    $firstModel = VehicleModel::factory()->forMake($firstMake)->create(['title' => $modelTitle]);
    $secondModel = VehicleModel::factory()->forMake($secondMake)->create(['title' => $modelTitle]);

    $planner = app(CatalogProductQueryPlanner::class);
    $query = 'порог '.$firstMakeTitle.' '.$modelTitle;
    $phrases = $planner->phrases($query);
    $plan = $planner->finalize($query, $phrases, [
        '1:1' => ['makes' => [$firstMake->id], 'models' => [], 'generations' => []],
        '2:1' => ['makes' => [], 'models' => [$firstModel->id, $secondModel->id], 'generations' => []],
    ]);

    expect($plan['active'])->toBeTrue()
        ->and($plan['product_filters'])->toBe(['make_id' => $firstMake->id, 'model_id' => $firstModel->id])
        ->and($plan['consumed_tokens'])->toBe([strtolower($firstMakeTitle), strtolower($modelTitle)])
        ->and($plan['residual_product_terms'])->toBe(['порог'])
        ->and(collect($plan['hierarchy_narrowing'])->contains(fn (array $step): bool => $step['type'] === 'model'
            && $step['candidates_before'] === 2
            && $step['candidates_after'] === 1))->toBeTrue();
})->with([
    'Partner' => ['Honda', 'Peugeot', 'Partner'],
    'Duster' => ['Dacia', 'Renault', 'Duster'],
    'Logan' => ['Dacia', 'Renault', 'Logan'],
    'Nexia' => ['Daewoo', 'Ravon', 'Nexia'],
    'Matrix' => ['Toyota', 'Hyundai', 'Matrix'],
]);

test('duplicate model without hierarchy context stays ambiguous and residual', function (): void {
    $firstMake = VehicleMake::factory()->create(['title' => 'Honda']);
    $secondMake = VehicleMake::factory()->create(['title' => 'Peugeot']);
    $first = VehicleModel::factory()->forMake($firstMake)->create(['title' => 'Partner']);
    $second = VehicleModel::factory()->forMake($secondMake)->create(['title' => 'Partner']);

    $planner = app(CatalogProductQueryPlanner::class);
    $query = 'порог Partner';
    $phrases = $planner->phrases($query);
    $plan = $planner->finalize($query, $phrases, [
        '1:1' => ['makes' => [], 'models' => [$first->id, $second->id], 'generations' => []],
    ]);

    expect($plan['active'])->toBeFalse()
        ->and($plan['consumed_tokens'])->toBe([])
        ->and($plan['residual_product_terms'])->toBe(['порог', 'partner'])
        ->and($plan['product_filters'])->toBe([])
        ->and($plan['hierarchy_status'])->toBe('ambiguous')
        ->and(collect($plan['rejections'])->contains(fn (array $rejection): bool => $rejection['reason'] === 'ambiguous_after_hierarchy'))->toBeTrue();
});

test('model context narrows duplicate generation candidates', function (): void {
    $firstMake = VehicleMake::factory()->create(['title' => 'First']);
    $secondMake = VehicleMake::factory()->create(['title' => 'Second']);
    $firstModel = VehicleModel::factory()->forMake($firstMake)->create(['title' => 'SharedModel']);
    $secondModel = VehicleModel::factory()->forMake($secondMake)->create(['title' => 'OtherModel']);
    $firstGeneration = VehicleGeneration::factory()->forVehicleModel($firstModel)->create(['title' => 'G1']);
    $secondGeneration = VehicleGeneration::factory()->forVehicleModel($secondModel)->create(['title' => 'G1']);

    $planner = app(CatalogProductQueryPlanner::class);
    $query = 'арка SharedModel G1';
    $phrases = $planner->phrases($query);
    $plan = $planner->finalize($query, $phrases, [
        '1:1' => ['makes' => [], 'models' => [$firstModel->id], 'generations' => []],
        '2:1' => ['makes' => [], 'models' => [], 'generations' => [$firstGeneration->id, $secondGeneration->id]],
    ]);

    expect($plan['product_filters'])->toBe([
        'make_id' => $firstMake->id,
        'model_id' => $firstModel->id,
        'generation_id' => $firstGeneration->id,
    ])->and(collect($plan['hierarchy_narrowing'])->contains(fn (array $step): bool => $step['type'] === 'generation'
        && $step['candidates_before'] === 2
        && $step['candidates_after'] === 1))->toBeTrue();
});

test('unique generation can establish model and make context', function (): void {
    $make = VehicleMake::factory()->create(['title' => 'Make']);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Model']);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => 'G1']);

    $planner = app(CatalogProductQueryPlanner::class);
    $query = 'арка G1';
    $phrases = $planner->phrases($query);
    $plan = $planner->finalize($query, $phrases, [
        '1:1' => ['makes' => [], 'models' => [], 'generations' => [$generation->id]],
    ]);

    expect($plan['product_filters'])->toBe([
        'make_id' => $make->id,
        'model_id' => $model->id,
        'generation_id' => $generation->id,
    ]);
});

test('conflicting hierarchy keeps the conflicting phrase residual and never creates incoherent filters', function (): void {
    $toyota = VehicleMake::factory()->create(['title' => 'Toyota']);
    $bmw = VehicleMake::factory()->create(['title' => 'BMW']);
    $x5 = VehicleModel::factory()->forMake($bmw)->create(['title' => 'X5']);

    $planner = app(CatalogProductQueryPlanner::class);
    $query = 'арка тойота X5';
    $phrases = $planner->phrases($query);
    $plan = $planner->finalize($query, $phrases, [
        '1:1' => ['makes' => [$toyota->id], 'models' => [], 'generations' => []],
        '2:1' => ['makes' => [], 'models' => [$x5->id], 'generations' => []],
    ]);

    expect($plan['active'])->toBeTrue()
        ->and($plan['product_filters'])->toBe(['make_id' => $bmw->id, 'model_id' => $x5->id])
        ->and($plan['consumed_tokens'])->toBe(['x5'])
        ->and($plan['residual_product_terms'])->toBe(['арка', 'тойота'])
        ->and($plan['hierarchy_status'])->toBe('conflict')
        ->and(collect($plan['rejections'])->contains(fn (array $rejection): bool => $rejection['reason'] === 'hierarchy_conflict'))->toBeTrue();
});

test('db public visibility rejects stale make model and generation candidates before planner consumption', function (string $group): void {
    $make = VehicleMake::factory()->create(['title' => 'VisibleMake']);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'VisibleModel']);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => 'VisibleGeneration']);
    $product = Product::factory()->withDefaultVariant()->create(['title' => 'Арка']);
    ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();

    $entity = match ($group) {
        'makes' => $make,
        'models' => $model,
        'generations' => $generation,
    };
    $entity->update(['is_active' => false]);

    $raw = [
        'makes' => $group === 'makes' ? [$make->id] : [],
        'models' => $group === 'models' ? [$model->id] : [],
        'generations' => $group === 'generations' ? [$generation->id] : [],
    ];
    $public = app(DatabaseCatalogSearchProvider::class)->publicVehicleIds($raw);
    expect($public[$group])->toBe([]);

    $planner = app(CatalogProductQueryPlanner::class);
    $query = 'арка stale';
    $phrases = $planner->phrases($query);
    $plan = $planner->finalize($query, $phrases, [
        '1:1' => $raw,
    ], $public);

    expect($plan['active'])->toBeFalse()
        ->and($plan['consumed_tokens'])->toBe([])
        ->and($plan['product_filters'])->toBe([])
        ->and(collect($plan['rejections'])->contains(fn (array $rejection): bool => $rejection['reason'] === 'not_public'))->toBeTrue();
})->with(['makes', 'models', 'generations']);

test('vehicle visibility and hierarchy hydration query counts stay bounded for candidate batches', function (): void {
    $resolutions = [];
    $publicExpected = ['makes' => [], 'models' => [], 'generations' => []];
    for ($i = 0; $i < 4; $i++) {
        $make = VehicleMake::factory()->create(['title' => 'Make '.$i]);
        $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Model '.$i]);
        $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => 'G'.$i]);
        $product = Product::factory()->withDefaultVariant()->create();
        ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();
        $publicExpected['makes'][] = $make->id;
        $publicExpected['models'][] = $model->id;
        $publicExpected['generations'][] = $generation->id;
    }
    $resolutions['1:1'] = $publicExpected;

    DB::flushQueryLog();
    DB::enableQueryLog();
    $public = app(DatabaseCatalogSearchProvider::class)->publicVehicleIds($publicExpected);
    $visibilityQueries = count(DB::getQueryLog());

    DB::flushQueryLog();
    $planner = app(CatalogProductQueryPlanner::class);
    $phrases = $planner->phrases('арка candidates');
    $planner->finalize('арка candidates', $phrases, $resolutions, $public);
    $hydrationQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($visibilityQueries)->toBeLessThanOrEqual(3)
        ->and($hydrationQueries)->toBeLessThanOrEqual(6);
});

test('entity planner is hard bounded and does not activate for simple or overlong queries', function (): void {
    $planner = app(CatalogProductQueryPlanner::class);

    expect($planner->shouldPlan('Camry'))->toBeFalse()
        ->and($planner->shouldPlan('арка'))->toBeFalse()
        ->and($planner->shouldPlan('one two three four five six seven'))->toBeFalse()
        ->and($planner->phrases('one two three four five six')['phrases'])->toHaveCount(15);
});

test('product search settings expose fitment identity filters', function (): void {
    $settings = config('scout.meilisearch.index-settings.'.Product::class);

    expect($settings['filterableAttributes'])->toContain('make_ids', 'model_ids', 'generation_ids');
});

test('generic mixed product and vehicle examples decompose without product or vehicle dictionaries', function (string $query, string $makeTitle, string $modelTitle, string $residual): void {
    $make = VehicleMake::factory()->create(['title' => $makeTitle, 'search_aliases' => null]);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => $modelTitle, 'search_aliases' => null]);

    $planner = app(CatalogProductQueryPlanner::class);
    $phrases = $planner->phrases($query);
    $plan = $planner->finalize($query, $phrases, [
        '1:1' => ['makes' => [$make->id], 'models' => [], 'generations' => []],
        '2:1' => ['makes' => [], 'models' => [$model->id], 'generations' => []],
    ]);

    expect($plan['active'])->toBeTrue()
        ->and($plan['residual_product_terms'])->toBe([$residual])
        ->and($plan['product_filters'])->toBe(['make_id' => $make->id, 'model_id' => $model->id]);
})->with([
    ['порог тойота камри', 'Toyota', 'Camry', 'порог'],
    ['ремкомплект бмв X5', 'BMW', 'X5', 'ремкомплект'],
    ['крыло ауди 100', 'Audi', '100', 'крыло'],
    ['арка мерседес W124', 'Mercedes', 'W124', 'арка'],
    ['порог субару легаси', 'Subaru', 'Legacy', 'порог'],
]);

test('sql visibility revalidates entity filters against one current fitment', function (): void {
    $toyota = VehicleMake::factory()->create(['title' => 'Toyota']);
    $toyotaModel = VehicleModel::factory()->forMake($toyota)->create(['title' => 'Probox']);
    $toyotaGeneration = VehicleGeneration::factory()->forVehicleModel($toyotaModel)->create();
    $toyotaProduct = Product::factory()->withDefaultVariant()->create();
    ProductFitment::factory()->forProduct($toyotaProduct)->forVehicleGeneration($toyotaGeneration)->create();

    $bmw = VehicleMake::factory()->create(['title' => 'BMW']);
    $bmwModel = VehicleModel::factory()->forMake($bmw)->create(['title' => 'X5']);
    $bmwGeneration = VehicleGeneration::factory()->forVehicleModel($bmwModel)->create();
    $bmwProduct = Product::factory()->withDefaultVariant()->create();
    ProductFitment::factory()->forProduct($bmwProduct)->forVehicleGeneration($bmwGeneration)->create();

    $ids = app(DatabaseCatalogSearchProvider::class)->publicProductIds(
        [$toyotaProduct->id, $bmwProduct->id],
        null,
        null,
        ['make_id' => $toyota->id, 'model_id' => $toyotaModel->id],
    );

    expect($ids)->toBe([$toyotaProduct->id]);
});

test('strong vehicle evidence tier excludes lower phonetic collision and keeps exact duplicates for hierarchy', function (): void {
    $planner = app(CatalogProductQueryPlanner::class);

    $kalos = $planner->selectCandidateTier([
        'strong' => [
            10 => ['id' => 10, 'title' => 'Kalos', 'source' => 'original', 'channel' => 'original', 'tier' => 'strong', 'confidence' => 0.0],
        ],
        'generated' => [],
        'phonetic' => [
            10 => ['id' => 10, 'title' => 'Kalos', 'source' => 'phonetic', 'channel' => 'phonetic', 'tier' => 'phonetic', 'confidence' => 0.0],
            11 => ['id' => 11, 'title' => 'Koleos', 'source' => 'phonetic', 'channel' => 'phonetic', 'tier' => 'phonetic', 'confidence' => 0.2],
        ],
    ]);

    expect($kalos['ids'])->toBe([10])
        ->and($kalos['selected_tier'])->toBe('strong')
        ->and($kalos['ignored_lower_tiers'])->toBe(['phonetic']);

    $partner = $planner->selectCandidateTier([
        'strong' => [
            20 => ['id' => 20, 'title' => 'Partner', 'source' => 'original', 'channel' => 'original', 'tier' => 'strong', 'confidence' => 0.0],
            21 => ['id' => 21, 'title' => 'Partner', 'source' => 'original', 'channel' => 'original', 'tier' => 'strong', 'confidence' => 0.0],
        ],
        'generated' => [],
        'phonetic' => [
            22 => ['id' => 22, 'title' => 'Panther', 'source' => 'phonetic', 'channel' => 'phonetic', 'tier' => 'phonetic', 'confidence' => 0.1],
        ],
    ]);

    expect($partner['ids'])->toBe([20, 21])
        ->and($partner['selected_tier'])->toBe('strong')
        ->and($partner['ignored_lower_tiers'])->toBe(['phonetic']);
});

test('global phrase evidence tier beats specificity across entity groups', function (): void {
    $planner = app(CatalogProductQueryPlanner::class);

    $ford = VehicleMake::factory()->create(['title' => 'Ford']);
    $otherMake = VehicleMake::factory()->create(['title' => 'CollisionMake']);
    $freda = VehicleModel::factory()->forMake($otherMake)->create(['title' => 'Freda']);
    $freed = VehicleModel::factory()->forMake($otherMake)->create(['title' => 'Freed']);
    $forte = VehicleModel::factory()->forMake($otherMake)->create(['title' => 'Forte']);

    $query = 'порог Ford';
    $phrases = $planner->phrases($query);
    $plan = $planner->finalize($query, $phrases, [
        '1:1' => [
            'makes' => [$ford->id],
            'models' => [$freda->id, $freed->id, $forte->id],
            'generations' => [],
            'channel_priority' => [
                'makes' => ['selected_tier' => 'strong'],
                'models' => ['selected_tier' => 'phonetic'],
                'generations' => ['selected_tier' => null],
            ],
        ],
    ]);

    $state = collect($plan['candidate_phrases'])->firstWhere('phrase', 'ford');

    expect($plan['product_filters'])->toBe(['make_id' => $ford->id])
        ->and($plan['consumed_tokens'])->toBe(['ford'])
        ->and($plan['residual_product_terms'])->toBe(['порог'])
        ->and($state['global_channel_priority']['selected_tier'] ?? null)->toBe('strong')
        ->and($state['global_channel_priority']['eligible_groups'] ?? [])->toBe(['makes'])
        ->and($state['raw_global_channel_priority']['ignored_groups_due_to_lower_tier'] ?? [])->toBe(['models'])
        ->and($state['model_candidates'] ?? [])->toBe([]);
});

test('exact model evidence beats weaker make and generation groups before specificity', function (): void {
    $planner = app(CatalogProductQueryPlanner::class);

    $parentMake = VehicleMake::factory()->create(['title' => 'Parent']);
    $exactModel = VehicleModel::factory()->forMake($parentMake)->create(['title' => 'ExactModel']);
    $weakMake = VehicleMake::factory()->create(['title' => 'WeakMake']);
    $weakModel = VehicleModel::factory()->forMake($weakMake)->create(['title' => 'WeakModel']);
    $weakGeneration = VehicleGeneration::factory()->forVehicleModel($weakModel)->create(['title' => 'WeakGeneration']);

    $query = 'порог ExactModel';
    $phrases = $planner->phrases($query);
    $plan = $planner->finalize($query, $phrases, [
        '1:1' => [
            'makes' => [$weakMake->id],
            'models' => [$exactModel->id],
            'generations' => [$weakGeneration->id],
            'channel_priority' => [
                'makes' => ['selected_tier' => 'phonetic'],
                'models' => ['selected_tier' => 'strong'],
                'generations' => ['selected_tier' => 'phonetic'],
            ],
        ],
    ]);

    $state = collect($plan['candidate_phrases'])->firstWhere('phrase', 'exactmodel');

    expect($plan['product_filters'])->toBe(['make_id' => $parentMake->id, 'model_id' => $exactModel->id])
        ->and($state['global_channel_priority']['selected_tier'] ?? null)->toBe('strong')
        ->and($state['global_channel_priority']['eligible_groups'] ?? [])->toBe(['models']);
});

test('exact generation evidence beats weaker model and make groups and establishes parents', function (): void {
    $planner = app(CatalogProductQueryPlanner::class);

    $parentMake = VehicleMake::factory()->create(['title' => 'Parent']);
    $parentModel = VehicleModel::factory()->forMake($parentMake)->create(['title' => 'ParentModel']);
    $exactGeneration = VehicleGeneration::factory()->forVehicleModel($parentModel)->create(['title' => 'ExactGeneration']);
    $weakMake = VehicleMake::factory()->create(['title' => 'WeakMake']);
    $weakModel = VehicleModel::factory()->forMake($weakMake)->create(['title' => 'WeakModel']);

    $query = 'арка ExactGeneration';
    $phrases = $planner->phrases($query);
    $plan = $planner->finalize($query, $phrases, [
        '1:1' => [
            'makes' => [$weakMake->id],
            'models' => [$weakModel->id],
            'generations' => [$exactGeneration->id],
            'channel_priority' => [
                'makes' => ['selected_tier' => 'phonetic'],
                'models' => ['selected_tier' => 'generated'],
                'generations' => ['selected_tier' => 'strong'],
            ],
        ],
    ]);

    $state = collect($plan['candidate_phrases'])->firstWhere('phrase', 'exactgeneration');

    expect($plan['product_filters'])->toBe([
        'make_id' => $parentMake->id,
        'model_id' => $parentModel->id,
        'generation_id' => $exactGeneration->id,
    ])->and($state['global_channel_priority']['selected_tier'] ?? null)->toBe('strong')
        ->and($state['global_channel_priority']['eligible_groups'] ?? [])->toBe(['generations']);
});

test('generated evidence beats phonetic groups and phonetic remains fallback when alone', function (): void {
    $planner = app(CatalogProductQueryPlanner::class);

    $generated = $planner->selectGlobalPhraseTier([
        'makes' => ['selected_tier' => 'generated'],
        'models' => ['selected_tier' => 'phonetic'],
        'generations' => ['selected_tier' => null],
    ], ['makes' => 1, 'models' => 2, 'generations' => 0]);

    $phoneticOnly = $planner->selectGlobalPhraseTier([
        'makes' => ['selected_tier' => null],
        'models' => ['selected_tier' => 'phonetic'],
        'generations' => ['selected_tier' => null],
    ], ['makes' => 0, 'models' => 2, 'generations' => 0]);

    expect($generated['selected_tier'])->toBe('generated')
        ->and($generated['eligible_groups'])->toBe(['makes'])
        ->and($generated['ignored_groups_due_to_lower_tier'])->toBe(['models'])
        ->and($phoneticOnly['selected_tier'])->toBe('phonetic')
        ->and($phoneticOnly['eligible_groups'])->toBe(['models']);
});

test('better confidence within one global tier beats entity specificity', function (): void {
    $planner = app(CatalogProductQueryPlanner::class);

    $make = VehicleMake::factory()->create(['title' => 'ConfidenceMake']);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'ConfidenceModel']);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => 'ConfidenceGeneration']);

    $query = 'арка candidate';
    $phrases = $planner->phrases($query);
    $plan = $planner->finalize($query, $phrases, [
        '1:1' => [
            'makes' => [],
            'models' => [$model->id],
            'generations' => [$generation->id],
            'channel_priority' => [
                'makes' => ['selected_tier' => null, 'candidates_by_tier' => []],
                'models' => [
                    'selected_tier' => 'phonetic',
                    'candidates_by_tier' => [
                        'phonetic' => [[
                            'id' => $model->id,
                            'title' => $model->title,
                            'source' => 'phonetic',
                            'channel' => 'phonetic',
                            'tier' => 'phonetic',
                            'confidence' => 0.20,
                        ]],
                    ],
                ],
                'generations' => [
                    'selected_tier' => 'phonetic',
                    'candidates_by_tier' => [
                        'phonetic' => [[
                            'id' => $generation->id,
                            'title' => $generation->title,
                            'source' => 'phonetic',
                            'channel' => 'phonetic',
                            'tier' => 'phonetic',
                            'confidence' => 0.36,
                        ]],
                    ],
                ],
            ],
        ],
    ]);

    $step = collect($plan['hierarchy_narrowing'])->firstWhere('phrase', 'candidate');

    expect($plan['product_filters'])->toBe([
        'make_id' => $make->id,
        'model_id' => $model->id,
    ])->and($plan['product_filters'])->not->toHaveKey('generation_id')
        ->and($step['group_confidences'] ?? [])->toEqual(['models' => 0.20, 'generations' => 0.36])
        ->and($step['best_confidence'] ?? null)->toBe(0.20)
        ->and($step['confidence_eligible_groups'] ?? [])->toBe(['models'])
        ->and($step['ignored_due_to_weaker_confidence'] ?? [])->toBe([[
            'group' => 'generations',
            'best_confidence' => 0.36,
            'candidate_count' => 1,
        ]]);
});

test('same group confidence differences do not disambiguate sibling model candidates', function (): void {
    $planner = app(CatalogProductQueryPlanner::class);

    $firstMake = VehicleMake::factory()->create(['title' => 'FirstMake']);
    $secondMake = VehicleMake::factory()->create(['title' => 'SecondMake']);
    $firstModel = VehicleModel::factory()->forMake($firstMake)->create(['title' => 'FirstCandidate']);
    $secondModel = VehicleModel::factory()->forMake($secondMake)->create(['title' => 'SecondCandidate']);

    $query = 'арка candidate';
    $phrases = $planner->phrases($query);
    $plan = $planner->finalize($query, $phrases, [
        '1:1' => [
            'makes' => [],
            'models' => [$firstModel->id, $secondModel->id],
            'generations' => [],
            'channel_priority' => [
                'models' => [
                    'selected_tier' => 'phonetic',
                    'candidates_by_tier' => [
                        'phonetic' => [
                            ['id' => $firstModel->id, 'source' => 'phonetic', 'channel' => 'phonetic', 'tier' => 'phonetic', 'confidence' => 0.20],
                            ['id' => $secondModel->id, 'source' => 'phonetic', 'channel' => 'phonetic', 'tier' => 'phonetic', 'confidence' => 0.21],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $rejection = collect($plan['rejections'])->firstWhere('phrase', 'candidate');

    expect($plan['product_filters'])->toBe([])
        ->and($plan['hierarchy_status'])->toBe('ambiguous')
        ->and($rejection['reason'] ?? null)->toBe('ambiguous_after_hierarchy')
        ->and($rejection['candidates_before'] ?? null)->toBe(2)
        ->and($rejection['candidates_after'] ?? null)->toBe(2)
        ->and($rejection['group_confidences'] ?? [])->toBe(['models' => 0.20])
        ->and($rejection['confidence_eligible_groups'] ?? [])->toBe(['models'])
        ->and($rejection['ignored_due_to_weaker_confidence'] ?? [])->toBe([]);
});

test('hierarchy can select a non best confidence sibling inside one entity group', function (): void {
    $planner = app(CatalogProductQueryPlanner::class);

    $firstMake = VehicleMake::factory()->create(['title' => 'FirstMake']);
    $secondMake = VehicleMake::factory()->create(['title' => 'SecondMake']);
    $firstModel = VehicleModel::factory()->forMake($firstMake)->create(['title' => 'SharedCandidate']);
    $secondModel = VehicleModel::factory()->forMake($secondMake)->create(['title' => 'SharedCandidate']);

    $query = 'SecondMake candidate';
    $phrases = $planner->phrases($query);
    $plan = $planner->finalize($query, $phrases, [
        '0:1' => [
            'makes' => [$secondMake->id],
            'models' => [],
            'generations' => [],
            'channel_priority' => [
                'makes' => [
                    'selected_tier' => 'strong',
                    'candidates_by_tier' => [
                        'strong' => [['id' => $secondMake->id, 'source' => 'original', 'channel' => 'original', 'tier' => 'strong', 'confidence' => 0.0]],
                    ],
                ],
            ],
        ],
        '1:1' => [
            'makes' => [],
            'models' => [$firstModel->id, $secondModel->id],
            'generations' => [],
            'channel_priority' => [
                'models' => [
                    'selected_tier' => 'phonetic',
                    'candidates_by_tier' => [
                        'phonetic' => [
                            ['id' => $firstModel->id, 'source' => 'phonetic', 'channel' => 'phonetic', 'tier' => 'phonetic', 'confidence' => 0.20],
                            ['id' => $secondModel->id, 'source' => 'phonetic', 'channel' => 'phonetic', 'tier' => 'phonetic', 'confidence' => 0.21],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $modelStep = collect($plan['hierarchy_narrowing'])->firstWhere('type', 'model');

    expect($plan['product_filters'])->toBe([
        'make_id' => $secondMake->id,
        'model_id' => $secondModel->id,
    ])->and($modelStep['candidates_before'] ?? null)->toBe(2)
        ->and($modelStep['candidates_after'] ?? null)->toBe(1)
        ->and($modelStep['group_confidences'] ?? [])->toBe(['models' => 0.21]);
});

test('specificity is preserved when intra tier confidence is tied within epsilon', function (float $generationConfidence): void {
    $planner = app(CatalogProductQueryPlanner::class);

    $make = VehicleMake::factory()->create(['title' => 'TieMake']);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'TieModel']);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => 'TieGeneration']);

    $query = 'арка candidate';
    $phrases = $planner->phrases($query);
    $plan = $planner->finalize($query, $phrases, [
        '1:1' => [
            'makes' => [],
            'models' => [$model->id],
            'generations' => [$generation->id],
            'channel_priority' => [
                'models' => [
                    'selected_tier' => 'phonetic',
                    'candidates_by_tier' => [
                        'phonetic' => [['id' => $model->id, 'source' => 'phonetic', 'channel' => 'phonetic', 'tier' => 'phonetic', 'confidence' => 0.20]],
                    ],
                ],
                'generations' => [
                    'selected_tier' => 'phonetic',
                    'candidates_by_tier' => [
                        'phonetic' => [['id' => $generation->id, 'source' => 'phonetic', 'channel' => 'phonetic', 'tier' => 'phonetic', 'confidence' => $generationConfidence]],
                    ],
                ],
            ],
        ],
    ]);

    expect($plan['product_filters'])->toBe([
        'make_id' => $make->id,
        'model_id' => $model->id,
        'generation_id' => $generation->id,
    ]);
})->with([
    'exact tie' => [0.20],
    'epsilon tie' => [0.20000005],
]);

test('better generation evidence can still beat model evidence within the same tier', function (): void {
    $planner = app(CatalogProductQueryPlanner::class);

    $make = VehicleMake::factory()->create(['title' => 'GenerationMake']);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'GenerationModel']);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => 'GenerationCandidate']);

    $query = 'арка candidate';
    $phrases = $planner->phrases($query);
    $plan = $planner->finalize($query, $phrases, [
        '1:1' => [
            'makes' => [],
            'models' => [$model->id],
            'generations' => [$generation->id],
            'channel_priority' => [
                'models' => [
                    'selected_tier' => 'phonetic',
                    'candidates_by_tier' => [
                        'phonetic' => [['id' => $model->id, 'source' => 'phonetic', 'channel' => 'phonetic', 'tier' => 'phonetic', 'confidence' => 0.20]],
                    ],
                ],
                'generations' => [
                    'selected_tier' => 'phonetic',
                    'candidates_by_tier' => [
                        'phonetic' => [['id' => $generation->id, 'source' => 'phonetic', 'channel' => 'phonetic', 'tier' => 'phonetic', 'confidence' => 0.10]],
                    ],
                ],
            ],
        ],
    ]);

    expect($plan['product_filters'])->toBe([
        'make_id' => $make->id,
        'model_id' => $model->id,
        'generation_id' => $generation->id,
    ]);
});

test('product vehicle match requires a public generation chain on the matching fitment', function (): void {
    $make = VehicleMake::factory()->create(['title' => 'Toyota']);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Camry']);
    $inactiveGeneration = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => 'G1']);
    $publicGeneration = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => 'G2']);

    $publicProduct = Product::factory()->withDefaultVariant()->create(['title' => 'Арка публичная']);
    ProductFitment::factory()->forProduct($publicProduct)->forVehicleGeneration($publicGeneration)->create();
    $staleFitmentProduct = Product::factory()->withDefaultVariant()->create(['title' => 'Арка старая']);
    ProductFitment::factory()->forProduct($staleFitmentProduct)->forVehicleGeneration($inactiveGeneration)->create();
    $inactiveGeneration->update(['is_active' => false]);

    $ids = app(DatabaseCatalogSearchProvider::class)->publicProductIds(
        [$publicProduct->id, $staleFitmentProduct->id],
        null,
        null,
        ['model_id' => $model->id],
    );

    expect($ids)->toBe([$publicProduct->id]);
});

test('product vehicle filters require one coherent matching fitment across make and model', function (): void {
    $toyota = VehicleMake::factory()->create(['title' => 'Toyota']);
    $corolla = VehicleModel::factory()->forMake($toyota)->create(['title' => 'Corolla']);
    $corollaGeneration = VehicleGeneration::factory()->forVehicleModel($corolla)->create();

    $honda = VehicleMake::factory()->create(['title' => 'Honda']);
    $partner = VehicleModel::factory()->forMake($honda)->create(['title' => 'Partner']);
    $partnerGeneration = VehicleGeneration::factory()->forVehicleModel($partner)->create();

    $crossFitmentProduct = Product::factory()->withDefaultVariant()->create(['title' => 'Порог']);
    ProductFitment::factory()->forProduct($crossFitmentProduct)->forVehicleGeneration($corollaGeneration)->create();
    ProductFitment::factory()->forProduct($crossFitmentProduct)->forVehicleGeneration($partnerGeneration)->create();

    $ids = app(DatabaseCatalogSearchProvider::class)->publicProductIds(
        [$crossFitmentProduct->id],
        null,
        null,
        ['make_id' => $toyota->id, 'model_id' => $partner->id],
    );

    expect($ids)->toBe([]);
});

test('same public fitment satisfies coherent make and model filters with bounded sql', function (): void {
    $toyota = VehicleMake::factory()->create(['title' => 'Toyota']);
    $probox = VehicleModel::factory()->forMake($toyota)->create(['title' => 'Probox']);
    $generation = VehicleGeneration::factory()->forVehicleModel($probox)->create();
    $product = Product::factory()->withDefaultVariant()->create(['title' => 'Арка']);
    ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $ids = app(DatabaseCatalogSearchProvider::class)->publicProductIds(
        [$product->id],
        null,
        null,
        ['make_id' => $toyota->id, 'model_id' => $probox->id],
    );
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($ids)->toBe([$product->id])
        ->and($queryCount)->toBeLessThanOrEqual(2);
});

test('explicit stale make intent does not redirect a duplicate model to another public make', function (): void {
    $firstMake = VehicleMake::factory()->create(['title' => 'MakeA']);
    $secondMake = VehicleMake::factory()->create(['title' => 'MakeB']);
    $firstModel = VehicleModel::factory()->forMake($firstMake)->create(['title' => 'Partner']);
    $secondModel = VehicleModel::factory()->forMake($secondMake)->create(['title' => 'Partner']);
    $firstGeneration = VehicleGeneration::factory()->forVehicleModel($firstModel)->create();
    $secondGeneration = VehicleGeneration::factory()->forVehicleModel($secondModel)->create();
    $firstProduct = Product::factory()->withDefaultVariant()->create(['title' => 'Порог A']);
    $secondProduct = Product::factory()->withDefaultVariant()->create(['title' => 'Порог B']);
    ProductFitment::factory()->forProduct($firstProduct)->forVehicleGeneration($firstGeneration)->create();
    ProductFitment::factory()->forProduct($secondProduct)->forVehicleGeneration($secondGeneration)->create();

    $planner = app(CatalogProductQueryPlanner::class);
    $query = 'part MakeA Partner';
    $phrases = $planner->phrases($query);
    $resolutions = [
        '1:1' => [
            'makes' => [$firstMake->id],
            'models' => [],
            'generations' => [],
            'channel_priority' => [
                'makes' => strongEntityPriority($firstMake->id, 'MakeA'),
            ],
        ],
        '2:1' => [
            'makes' => [],
            'models' => [$firstModel->id, $secondModel->id],
            'generations' => [],
            'channel_priority' => [
                'models' => strongEntityPriority($firstModel->id, 'Partner', $secondModel->id),
            ],
        ],
    ];

    $bothPublic = app(DatabaseCatalogSearchProvider::class)->publicVehicleIds($planner->candidateIds($resolutions));
    $publicPlan = $planner->finalize($query, $phrases, $resolutions, $bothPublic);
    expect($publicPlan['product_filters'])->toBe(['make_id' => $firstMake->id, 'model_id' => $firstModel->id]);

    $firstModel->update(['is_active' => false]);
    $publicAfterStale = app(DatabaseCatalogSearchProvider::class)->publicVehicleIds($planner->candidateIds($resolutions));
    $stalePlan = $planner->finalize($query, $phrases, $resolutions, $publicAfterStale);
    $partnerRejection = collect($stalePlan['rejections'])->firstWhere('phrase', 'partner');
    $makePhrase = collect($stalePlan['candidate_phrases'])->firstWhere('phrase', 'makea');

    expect($stalePlan['product_filters']['make_id'] ?? null)->toBeNull()
        ->and($stalePlan['product_filters']['model_id'] ?? null)->toBeNull()
        ->and($stalePlan['consumed_tokens'])->not->toContain('partner')
        ->and(collect($stalePlan['rejections'])->contains(fn (array $rejection): bool => ($rejection['reason'] ?? null) === 'not_public'))->toBeTrue()
        ->and($partnerRejection['reason'] ?? null)->toBe('not_public')
        ->and($partnerRejection['stale_intent_conflicts'] ?? [])->not->toBeEmpty()
        ->and($makePhrase['stale_intent']['id'] ?? null)->toBe($firstMake->id)
        ->and($makePhrase['stale_intent']['reason'] ?? null)->toBe('not_public');
});

test('weaker stale phonetic intent does not block stronger public exact entity evidence', function (): void {
    $staleMake = VehicleMake::factory()->create(['title' => 'OldMake']);
    $publicMake = VehicleMake::factory()->create(['title' => 'PublicMake']);
    $staleModel = VehicleModel::factory()->forMake($staleMake)->create(['title' => 'OldModel']);
    $publicModel = VehicleModel::factory()->forMake($publicMake)->create(['title' => 'Partner']);
    $staleGeneration = VehicleGeneration::factory()->forVehicleModel($staleModel)->create();
    $publicGeneration = VehicleGeneration::factory()->forVehicleModel($publicModel)->create();
    $staleProduct = Product::factory()->withDefaultVariant()->create();
    $publicProduct = Product::factory()->withDefaultVariant()->create();
    ProductFitment::factory()->forProduct($staleProduct)->forVehicleGeneration($staleGeneration)->create();
    ProductFitment::factory()->forProduct($publicProduct)->forVehicleGeneration($publicGeneration)->create();
    $staleModel->update(['is_active' => false]);

    $planner = app(CatalogProductQueryPlanner::class);
    $query = 'part noise Partner';
    $phrases = $planner->phrases($query);
    $resolutions = [
        '1:1' => [
            'makes' => [$staleMake->id],
            'models' => [],
            'generations' => [],
            'channel_priority' => [
                'makes' => entityPriority('phonetic', [[
                    'id' => $staleMake->id,
                    'title' => 'OldMake',
                    'source' => 'phonetic',
                    'channel' => 'phonetic',
                    'tier' => 'phonetic',
                    'confidence' => 0.40,
                ]]),
            ],
        ],
        '2:1' => [
            'makes' => [],
            'models' => [$publicModel->id],
            'generations' => [],
            'channel_priority' => [
                'models' => strongEntityPriority($publicModel->id, 'Partner'),
            ],
        ],
    ];

    $public = app(DatabaseCatalogSearchProvider::class)->publicVehicleIds($planner->candidateIds($resolutions));
    $plan = $planner->finalize($query, $phrases, $resolutions, $public);

    expect($plan['product_filters']['model_id'] ?? null)->toBe($publicModel->id)
        ->and($plan['product_filters']['make_id'] ?? null)->toBe($publicMake->id)
        ->and($plan['consumed_tokens'])->toContain('partner')
        ->and($plan['consumed_tokens'])->not->toContain('noise');
});

function strongEntityPriority(int $firstId, string $title, ?int $secondId = null): array
{
    $candidates = [[
        'id' => $firstId,
        'title' => $title,
        'source' => 'original',
        'channel' => 'original',
        'tier' => 'strong',
        'confidence' => 0.0,
    ]];
    if ($secondId !== null) {
        $candidates[] = [
            'id' => $secondId,
            'title' => $title,
            'source' => 'original',
            'channel' => 'original',
            'tier' => 'strong',
            'confidence' => 0.0,
        ];
    }

    return entityPriority('strong', $candidates);
}

function entityPriority(string $selectedTier, array $selectedCandidates): array
{
    return [
        'candidates_by_tier' => [
            'strong' => $selectedTier === 'strong' ? $selectedCandidates : [],
            'generated' => $selectedTier === 'generated' ? $selectedCandidates : [],
            'phonetic' => $selectedTier === 'phonetic' ? $selectedCandidates : [],
        ],
        'selected_tier' => $selectedTier,
        'ignored_lower_tiers' => [],
    ];
}
