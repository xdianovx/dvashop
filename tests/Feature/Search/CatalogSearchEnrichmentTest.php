<?php

use App\Models\Product;
use App\Models\ProductFitment;
use App\Models\VehicleModel;
use App\Services\Search\CatalogSearchConfidenceGate;
use App\Services\Search\CatalogSearchDocument;
use App\Services\Search\CatalogSearchEnrichmentService;
use App\Services\Search\CatalogSearchNormalizer;
use App\Services\Search\CatalogSearchPhoneticEncoder;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/CatalogSearchFixtures.php';

uses(RefreshDatabase::class);

test('normalization is deterministic across unicode punctuation separators spaces and yo', function (): void {
    $normalizer = app(CatalogSearchNormalizer::class);

    expect($normalizer->normalize("  МЕРСЕДЕС\u{00A0}—  БЕНЦ ’Ё’  "))->toBe('мерседес бенц е')
        ->and($normalizer->normalize('CX‑5 / A4'))->toBe('cx 5 a4');
});

test('keyboard layout conversion is generic in both directions', function (): void {
    $normalizer = app(CatalogSearchNormalizer::class);

    expect($normalizer->keyboardLayoutAlternative('сфькн'))->toBe('camry')
        ->and($normalizer->keyboardLayoutAlternative('camry'))->toBe('сфькн')
        ->and($normalizer->keyboardLayoutAlternative(';er'))->toBe('жук')
        ->and($normalizer->keyboardLayoutAlternative('Toyota камри'))->toBeNull();
});

test('meaningful keyboard layout alternatives are re-enriched while exact and structured queries stay primary', function (): void {
    $enrichment = app(CatalogSearchEnrichmentService::class);

    $ruLayout = $enrichment->query('сфькн');
    expect($ruLayout['layout_alternative'])->toBe('camry')
        ->and($ruLayout['layout_searched'])->toBeTrue()
        ->and($ruLayout['layout']['normalized'])->toBe('camry')
        ->and($ruLayout['layout']['strict_transliteration'])->toBe('camry')
        ->and($ruLayout['channels'])->toContain('layout');

    $enLayout = $enrichment->query('rfvhb');
    expect($enLayout['layout_alternative'])->toBe('камри')
        ->and($enLayout['layout_searched'])->toBeTrue()
        ->and($enLayout['layout']['normalized'])->toBe('камри')
        ->and($enLayout['layout']['strict_transliteration'])->toBe('kamri')
        ->and($enLayout['layout']['phonetic_tokens'])->toBe(['KMR'])
        ->and($enLayout['layout']['channels'])->toContain('phonetic', 'phonetic_prefix');

    $toyota = $enrichment->query('njqjnf');
    expect($toyota['layout_alternative'])->toBe('тойота')
        ->and($toyota['layout_searched'])->toBeTrue()
        ->and($toyota['layout']['strict_transliteration'])->toBe('toyota');

    expect($enrichment->query('Camry')['layout_searched'])->toBeFalse()
        ->and($enrichment->query('Toyota')['layout_searched'])->toBeFalse()
        ->and($enrichment->query('BMW X5')['layout_searched'])->toBeFalse()
        ->and($enrichment->query('X5')['layout_searched'])->toBeFalse()
        ->and($enrichment->query('Toyota камри')['layout_searched'])->toBeFalse();
});

test('strict russian transliteration is deterministic and keeps short codes out of phonetics', function (): void {
    $normalizer = app(CatalogSearchNormalizer::class);
    $phonetic = app(CatalogSearchPhoneticEncoder::class);

    expect($normalizer->strictTransliteration('Субару Ауди Камри Виш Легаси'))->toBe('subaru audi kamri vish legasi');
    foreach (['X3', 'X5', 'A3', 'A4', 'Q5', 'Q7', 'C3', 'CX-5'] as $code) {
        expect($normalizer->isShortCode($code))->toBeTrue()
            ->and($phonetic->encode($code))->toBe([]);
    }
});

test('folded metaphone derives generic proper name equivalents without vehicle dictionary', function (): void {
    $phonetic = app(CatalogSearchPhoneticEncoder::class);
    foreach ([
        ['BMW', 'БМВ'],
        ['Camry', 'Камри'],
        ['Wish', 'Виш'],
        ['Legacy', 'Легаси'],
        ['Probox', 'Пробокс'],
        ['Volkswagen', 'Фольксваген'],
        ['Mitsubishi', 'Мицубиси'],
        ['Citroen', 'Ситроен'],
    ] as [$canonical, $russian]) {
        expect($phonetic->encode($canonical))->toBe($phonetic->encode($russian), $canonical.' must share a generic phonetic representation');
    }

    expect($phonetic->encode('Wish'))->not->toBe($phonetic->encode('Vision'))
        ->and($phonetic->encode('Wish'))->not->toBe($phonetic->encode('Vista'));
});

test('confidence gate accepts close phonetic proper names and rejects distance and collisions', function (): void {
    $normalizer = app(CatalogSearchNormalizer::class);
    $gate = app(CatalogSearchConfidenceGate::class);
    $tokens = $normalizer->significantLatinTokens('камри');

    expect($gate->evaluate($tokens, ['Camry'])['accepted'])->toBeTrue()
        ->and($gate->evaluate($tokens, ['Chrysler'])['accepted'])->toBeFalse()
        ->and($gate->selectUnambiguous([
            ['id' => 1, 'score' => 0.20],
            ['id' => 2, 'score' => 0.21],
        ]))->toMatchArray(['accepted_ids' => [], 'reason' => 'ambiguous']);
});

test('multi word query produces all significant phonetic tokens and preserves strict hierarchy semantics', function (): void {
    $enrichment = app(CatalogSearchEnrichmentService::class);
    $normalizer = app(CatalogSearchNormalizer::class);
    $gate = app(CatalogSearchConfidenceGate::class);
    $plan = $enrichment->query('Тойота Камри');
    $tokens = $normalizer->significantLatinTokens('Тойота Камри');
    $camry = $gate->evaluate($tokens, ['Toyota', 'Camry'], 'Camry');
    $gracia = $gate->evaluate($tokens, ['Toyota', 'Camry Gracia'], 'Camry Gracia');

    expect($plan['strict_transliteration'])->toBe('toyota kamri')
        ->and($plan['phonetic_tokens'])->toHaveCount(2)
        ->and($plan['short_code'])->toBeFalse()
        ->and($plan['channels'])->toContain('strict_transliteration', 'phonetic')
        ->and($camry['accepted'])->toBeTrue()
        ->and($gracia['accepted'])->toBeTrue()
        ->and($camry['score'])->toBeLessThan($gracia['score'])
        ->and($enrichment->query('Camry')['channels'])->not->toContain('phonetic');
});

test('verified aliases stay separate from generated fields and generation inherits make and model enrichment', function (): void {
    $fixture = searchVehicleFixture('Toyota', 'Camry', ['тойота'], ['камри']);
    $generation = $fixture['generation']->fresh()->load(CatalogSearchDocument::relations($fixture['generation']));
    $document = $generation->toSearchableArray();

    expect($document['model_aliases'])->toBe(['камри'])
        ->and($document['make_aliases'])->toBe(['тойота'])
        ->and($document['strict_transliteration_terms'])->toContain('camry', 'kamri', 'toyota')
        ->and($document['phonetic_terms'])->toContain(...app(CatalogSearchPhoneticEncoder::class)->encode('Camry'))
        ->and($document['phonetic_prefix_terms'])->toContain(...app(CatalogSearchPhoneticEncoder::class)->prefixTerms('Camry'))
        ->and($document['vehicle_entities'][0]['model_id'])->toBe($fixture['model']->id)
        ->and($fixture['model']->fresh()->search_aliases)->toBe(['камри']);
});

test('product inherits enrichment from every fitment without phonetic sku or generated database writes', function (): void {
    $first = searchVehicleFixture('Toyota', 'Camry', [], []);
    $second = searchVehicleFixture('Subaru', 'Legacy', [], []);
    ProductFitment::factory()->forProduct($first['product'])->forVehicleGeneration($second['generation'])->create();
    $product = Product::query()->with(CatalogSearchDocument::relations(new Product))->findOrFail($first['product']->id);
    $document = $product->toSearchableArray();
    $encoder = app(CatalogSearchPhoneticEncoder::class);

    expect($document['model_titles'])->toEqualCanonicalizing(['Camry', 'Legacy'])
        ->and($document['vehicle_entities'])->toHaveCount(2)
        ->and($document['phonetic_terms'])->toContain(...$encoder->encode('Camry'))
        ->and($document['phonetic_terms'])->toContain(...$encoder->encode('Legacy'))
        ->and($document['phonetic_prefix_terms'])->toContain(...$encoder->prefixTerms('Camry'))
        ->and($document['phonetic_terms'])->not->toContain((string) $product->sku)
        ->and($document['phonetic_prefix_terms'])->not->toContain((string) $product->sku)
        ->and($first['model']->fresh()->search_aliases)->toBe([])
        ->and($second['model']->fresh()->search_aliases)->toBe([]);
});

test('mixed query classification keeps short codes and numeric tokens local to their token', function (): void {
    $enrichment = app(CatalogSearchEnrichmentService::class);

    $bmw = $enrichment->query('бмв X5');
    expect($bmw['short_code'])->toBeFalse()
        ->and($bmw['word_tokens'])->toBe(['bmv'])
        ->and($bmw['structured_tokens'])->toBe(['x5'])
        ->and($bmw['phonetic_prefix_tokens'])->toBe(['px3bmv'])
        ->and($bmw['channels'])->toContain('phonetic', 'phonetic_prefix')
        ->and($bmw['token_details'][0]['original'])->toBe('бмв')
        ->and($bmw['token_details'][0]['type'])->toBe('word')
        ->and($bmw['token_details'][1]['original'])->toBe('X5')
        ->and($bmw['token_details'][1]['type'])->toBe('short_code')
        ->and($bmw['token_details'][1]['channels'])->toContain('structured_exact');

    $year = $enrichment->query('камри 2012');
    expect($year['short_code'])->toBeFalse()
        ->and($year['word_tokens'])->toBe(['kamri'])
        ->and($year['structured_tokens'])->toBe(['2012'])
        ->and($year['phonetic_prefix_tokens'])->toBe(['px5kamri'])
        ->and($year['token_details'][1]['type'])->toBe('numeric')
        ->and($year['channels'])->toContain('phonetic');

    $onlyCode = $enrichment->query('X5');
    expect($onlyCode['short_code'])->toBeTrue()
        ->and($onlyCode['word_tokens'])->toBe([])
        ->and($onlyCode['structured_tokens'])->toBe(['x5'])
        ->and($onlyCode['channels'])->not->toContain('phonetic', 'phonetic_prefix');

    $normalizer = app(CatalogSearchNormalizer::class);
    expect(array_column($normalizer->classifiedTokens('2012–2015'), 'structured'))->toBe(['2012', '2015'])
        ->and(array_column($normalizer->classifiedTokens('2012–2015'), 'type'))->toBe(['numeric', 'numeric'])
        ->and($normalizer->structuredTokens('CX-5'))->toBe(['cx5'])
        ->and($normalizer->significantLatinTokens('Mercedes-Benz'))->toBe(['mercedes', 'benz']);
});

test('russian phonetic prefixes are gated separately from full words', function (): void {
    $normalizer = app(CatalogSearchNormalizer::class);
    $phonetic = app(CatalogSearchPhoneticEncoder::class);
    $gate = app(CatalogSearchConfidenceGate::class);

    expect($phonetic->prefixSignature('камр'))->toBe($phonetic->prefixSignature('camr'))
        ->and($phonetic->prefixSignature('кам'))->toBe($phonetic->prefixSignature('cam'))
        ->and($gate->evaluatePrefix($normalizer->significantLatinTokens('камр'), ['Camry'])['accepted'])->toBeTrue()
        ->and($gate->evaluatePrefix($normalizer->significantLatinTokens('кам'), ['Camry'])['accepted'])->toBeTrue()
        ->and($gate->evaluate($normalizer->significantLatinTokens('камр'), ['Camry'])['accepted'])->toBeFalse()
        ->and($gate->evaluate($normalizer->significantLatinTokens('виш'), ['Wish'])['accepted'])->toBeTrue()
        ->and($gate->evaluate($normalizer->significantLatinTokens('виш'), ['Vision'])['accepted'])->toBeFalse()
        ->and($gate->evaluate($normalizer->significantLatinTokens('виш'), ['Vista'])['accepted'])->toBeFalse()
        ->and($gate->evaluate($normalizer->significantLatinTokens('вис'), ['Wish'])['accepted'])->toBeFalse()
        ->and($gate->selectPrefixUnambiguous([
            ['id' => 1, 'score' => 0.04],
            ['id' => 2, 'score' => 0.04],
        ]))->toMatchArray(['accepted_ids' => [], 'reason' => 'ambiguous_prefix'])
        ->and($normalizer->significantLatinTokens('ви'))->toBe([]);
});

test('strict generated attributes keep typo tolerance disabled', function (): void {
    $disabled = config('scout.meilisearch.index-settings.'.VehicleModel::class.'.typoTolerance.disableOnAttributes');

    expect($disabled)->toContain(
        'strict_transliteration_terms',
        'phonetic_terms',
        'phonetic_prefix_terms',
        'structured_terms',
        'sku',
        'variant_skus',
    );
});
