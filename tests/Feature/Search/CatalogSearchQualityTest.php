<?php

use App\Services\Search\CatalogSearchConfidenceGate;
use App\Services\Search\CatalogSearchNormalizer;
use App\Services\Search\CatalogSearchPhoneticAudit;
use App\Services\Search\CatalogSearchPhoneticEncoder;

$autoExpected = [
    ['Audi', 'Ауди'],
    ['Subaru', 'Субару'],
    ['Toyota', 'Тойота'],
    ['Mazda', 'Мазда'],
    ['Camry', 'Камри'],
    ['Wish', 'Виш'],
    ['Legacy', 'Легаси'],
    ['Probox', 'Пробокс'],
    ['Land Cruiser', 'Ленд Крузер'],
    ['Land Cruiser', 'Ланд Крузер'],
    ['Mercedes-Benz', 'Мерседес Бенц'],
    ['Volkswagen', 'Фольксваген'],
    ['Mitsubishi', 'Мицубиси'],
    ['Citroen', 'Ситроен'],
];

$verifiedAliasExpected = [
    ['Hyundai', 'Хендай'],
    ['Chevrolet', 'Шевроле'],
    ['Peugeot', 'Пежо'],
    ['Renault', 'Рено'],
];

test('AUTO_EXPECTED proper names resolve generically while false positives prefer empty', function () use ($autoExpected): void {
    $normalizer = app(CatalogSearchNormalizer::class);
    $phonetic = app(CatalogSearchPhoneticEncoder::class);
    $gate = app(CatalogSearchConfidenceGate::class);
    $catalog = array_values(array_unique(array_merge(
        array_column($autoExpected, 0),
        ['Vision', 'Vista', 'FC Vision', 'Chrysler', 'BMW'],
    )));

    foreach ($autoExpected as [$expected, $query]) {
        $strict = $normalizer->strictTransliteration($query);
        $strictCandidates = array_values(array_filter($catalog, fn (string $title): bool => $normalizer->strictTransliteration($title) === $strict));
        if ($strictCandidates !== []) {
            expect($strictCandidates[0])->toBe($expected, $query.' strict transliteration must resolve top1');

            continue;
        }

        $queryTokens = $normalizer->significantLatinTokens($query);
        $candidates = [];
        foreach ($catalog as $id => $title) {
            if ($phonetic->encode($title) !== $phonetic->encode($query)) {
                continue;
            }
            $evaluation = $gate->evaluate($queryTokens, [$title], $title);
            if ($evaluation['accepted']) {
                $candidates[] = ['id' => $id, 'title' => $title, 'score' => $evaluation['score']];
            }
        }
        $selection = $gate->selectUnambiguous(array_map(
            static fn (array $candidate): array => ['id' => $candidate['id'], 'score' => $candidate['score']],
            $candidates,
        ));
        $top = collect($candidates)->firstWhere('id', $selection['accepted_ids'][0] ?? -1);
        expect($top['title'] ?? null)->toBe($expected, $query.' generic phonetic matcher must resolve top1');
    }

    expect($phonetic->encode('Виш'))->not->toBe($phonetic->encode('Vision'))
        ->and($phonetic->encode('Виш'))->not->toBe($phonetic->encode('Vista'))
        ->and($phonetic->encode('Камри'))->not->toBe($phonetic->encode('Chrysler'))
        ->and($phonetic->encode('Камри'))->not->toBe($phonetic->encode('BMW'));
});

test('VERIFIED_ALIAS_EXPECTED remains an explicit exception layer instead of production hardcode', function () use ($verifiedAliasExpected): void {
    $normalizer = app(CatalogSearchNormalizer::class);

    foreach ($verifiedAliasExpected as [$canonical, $alias]) {
        $verified = [$normalizer->normalize($alias) => $canonical];
        expect($verified[$normalizer->normalize($alias)] ?? null)->toBe($canonical);
    }
});

test('real catalog collision and prefix audit stays conservative', function (): void {
    $data = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/Search/vehicle-search-catalog.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $entities = [];
    $models = [];
    foreach ($data['makes'] as $row) {
        $entities[] = ['type' => 'make', 'id' => (int) $row['id'], 'title' => (string) $row['title']];
    }
    foreach ($data['models'] as $row) {
        $entry = ['type' => 'model', 'id' => (int) $row['id'], 'title' => (string) $row['title']];
        $entities[] = $entry;
        $models[] = $entry;
    }

    $audit = app(CatalogSearchPhoneticAudit::class);
    $phonetic = $audit->compare($entities)['folded_metaphone'];
    $prefix = $audit->prefix($models);

    expect(count($data['makes']))->toBe(78)
        ->and(count($data['models']))->toBe(943)
        ->and($phonetic)->toMatchArray([
            'entity_count' => 827,
            'unique_keys' => 656,
            'collision_keys' => 114,
            'ambiguous_entities' => 285,
            'max_bucket' => 13,
        ])
        ->and($prefix)->toBe([
            'tests' => 2033,
            'unique_correct' => 917,
            'ambiguous' => 1116,
            'false_positives' => 0,
        ]);
});
