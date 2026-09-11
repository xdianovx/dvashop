<?php

namespace App\Services\Search;

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Cursor;
use Meilisearch\Client;
use Meilisearch\Contracts\SearchQuery;

class MeilisearchCatalogSearchProvider
{
    private const MODE_ORIGINAL_STRONG = 0;

    private const MODE_STRICT_TRANSLITERATION = 1;

    private const MODE_PHONETIC = 2;

    private const MODE_PHONETIC_PREFIX = 3;

    private const MODE_ORIGINAL_FUZZY = 4;

    private const VEHICLE_WINDOW = 50;

    private const VEHICLE_MAX_WINDOWS = 3;

    private const VEHICLE_FINAL_LIMIT = 10;

    public function __construct(private readonly DatabaseCatalogSearchProvider $database) {}

    protected function client(): Client
    {
        $timeout = max(10, min(2000, config('catalog-search.timeout_ms'))) / 1000;

        return new Client(config('scout.meilisearch.host'), config('scout.meilisearch.key'), new HttpClient([
            'connect_timeout' => $timeout,
            'timeout' => $timeout,
        ]));
    }

    public function candidates(string $query, ?ProductCategory $category = null, ?PartType $partType = null, int $offset = 0, int $limit = 50, ?array $productQueryPlan = null): array
    {
        $plan = app(CatalogSearchEnrichmentService::class)->query($query);
        $productQueryPlan ??= $this->productQueryPlan($query);
        $channels = $this->channelDefinitions($plan);
        $queries = [];
        $metadata = [];

        foreach ($channels as $channel => $definition) {
            foreach (CatalogSearchDocument::MODELS as $group => $class) {
                $model = new $class;
                if ($group === 'products' && ($productQueryPlan['active'] ?? false)) {
                    if ($channel !== 'original') {
                        continue;
                    }
                    $productDefinition = $this->entityAwareProductDefinition($productQueryPlan);
                    $queryValue = $productDefinition['query'];
                    $attributes = $productDefinition['attributes'];
                } else {
                    $queryValue = $definition['query'];
                    $attributes = $definition['attributes']($model);
                }
                $queries[] = (new SearchQuery)
                    ->setIndexUid($model->searchableAs())
                    ->setQuery($queryValue)
                    ->setAttributesToSearchOn($attributes)
                    ->setAttributesToRetrieve($this->attributesToRetrieve($model))
                    ->setMatchingStrategy('all')
                    ->setFilter($group === 'products' ? $this->productFilters($category, $partType, $productQueryPlan) : ['is_active = true'])
                    ->setSort(['position:asc'])
                    ->setLimit($group === 'products' ? $limit : self::VEHICLE_WINDOW)
                    ->setOffset($group === 'products' ? $offset : 0);
                $metadata[] = [$channel, $group];
            }
        }

        $results = $this->client()->multiSearch($queries)['results'];
        $raw = [];
        foreach ($results as $index => $result) {
            [$channel, $group] = $metadata[$index];
            $raw[$channel][$group] = $result;
        }

        return $this->rankCandidateRaw($plan, $raw, $limit, $productQueryPlan) + [
            'raw' => $raw,
            'product_query_plan' => $productQueryPlan,
        ];
    }

    /**
     * @param  array<string,array<string,array<string,mixed>>>  $raw
     * @return array<string,mixed>
     */
    private function rankCandidateRaw(array $plan, array $raw, int $productLimit, ?array $productQueryPlan = null): array
    {
        $strictPlan = $this->channelPlan($plan, 'strict_transliteration');
        $strictSource = $this->channelSource($plan, 'strict_transliteration');
        $phoneticPlan = $this->channelPlan($plan, 'phonetic');
        $phoneticSource = $this->channelSource($plan, 'phonetic');
        $phonetic = $this->resolvePhonetic($phoneticPlan ?? $plan, $this->mergePhoneticRaw(
            $raw['phonetic'] ?? [],
            $raw['phonetic_prefix'] ?? [],
        ));
        if ($phoneticSource !== null) {
            $phonetic['diagnostics'] = array_map(
                static fn (array $decision): array => $decision + ['source' => $phoneticSource],
                $phonetic['diagnostics'],
            );
        }
        $ids = array_fill_keys(array_keys(CatalogSearchDocument::MODELS), []);
        $ranked = array_fill_keys(array_keys(CatalogSearchDocument::MODELS), []);
        $diagnostics = $phonetic['diagnostics'];

        foreach (CatalogSearchDocument::MODELS as $group => $class) {
            if ($group === 'products' && ($productQueryPlan['active'] ?? false)) {
                foreach ($raw['original']['products']['hits'] ?? [] as $position => $hit) {
                    $ranked[$group][] = ['id' => (int) $hit['id'], 'rank' => 0, 'position' => $position];
                    $diagnostics[] = [
                        'group' => 'products',
                        'id' => (int) $hit['id'],
                        'title' => (string) ($hit['title'] ?? ''),
                        'accepted' => true,
                        'score' => 0,
                        'reason' => ($productQueryPlan['residual_query'] ?? '') === '' ? 'vehicle_filters_only' : 'residual_product_text_with_vehicle_filters',
                        'source' => 'entity_query_plan',
                    ];
                }
                $ids[$group] = array_values(array_unique(array_column($ranked[$group], 'id')));

                continue;
            }
            foreach ($raw['original'][$group]['hits'] ?? [] as $position => $hit) {
                $priority = $this->originalPriority($hit, $plan['normalized'], new $class);
                if (($plan['structured_tokens'] ?? []) !== [] && $priority > 4) {
                    $diagnostics[] = $this->diagnostic($group, $hit, ['accepted' => false, 'score' => $priority, 'reason' => 'structured_typo_rejected']) + ['source' => 'original'];

                    continue;
                }
                if (($plan['layout_searched'] ?? false) && $priority > 4) {
                    $diagnostics[] = $this->diagnostic($group, $hit, ['accepted' => false, 'score' => $priority, 'reason' => 'layout_active_fuzzy_rejected']) + ['source' => 'original'];

                    continue;
                }
                $ranked[$group][] = ['id' => (int) $hit['id'], 'rank' => $priority, 'position' => $position];
                $diagnostics[] = [
                    'group' => $group,
                    'id' => (int) $hit['id'],
                    'title' => (string) ($hit['title'] ?? ''),
                    'accepted' => true,
                    'score' => $priority,
                    'reason' => $this->priorityReason($priority),
                    'source' => $priority === 4 ? 'layout_index' : 'original',
                ];
            }
            foreach ($raw['strict_transliteration'][$group]['hits'] ?? [] as $position => $hit) {
                if ($strictPlan === null || ! $this->structuredTokensMatch($strictPlan['structured_tokens'] ?? [], $hit['structured_terms'] ?? [])) {
                    $diagnostics[] = $this->diagnostic($group, $hit, ['accepted' => false, 'score' => 5, 'reason' => 'structured_mismatch']) + ['source' => $strictSource ?? 'original'];

                    continue;
                }
                if (! $this->allTokensMatch(
                    $strictPlan['strict_transliteration'],
                    $this->values($hit, ['strict_transliteration_terms']),
                    false,
                )) {
                    $diagnostics[] = $this->diagnostic($group, $hit, ['accepted' => false, 'score' => 5, 'reason' => 'strict_prefix_rejected']) + ['source' => $strictSource ?? 'original'];

                    continue;
                }
                $ranked[$group][] = ['id' => (int) $hit['id'], 'rank' => 5, 'position' => $position];
                $diagnostics[] = [
                    'group' => $group,
                    'id' => (int) $hit['id'],
                    'title' => (string) ($hit['title'] ?? ''),
                    'accepted' => true,
                    'score' => 5,
                    'reason' => $strictSource === 'layout' ? 'layout_strict_transliteration' : 'strict_transliteration',
                    'source' => $strictSource ?? 'original',
                ];
            }
            $phoneticAccepted = array_fill_keys(array_map(
                static fn (array $hit): int => (int) $hit['id'],
                $phonetic['hits'][$group] ?? [],
            ), true);
            foreach ($raw['phonetic'][$group]['hits'] ?? [] as $position => $hit) {
                if (isset($phoneticAccepted[(int) $hit['id']])) {
                    $ranked[$group][] = ['id' => (int) $hit['id'], 'rank' => 6, 'position' => $position];
                }
            }
            foreach ($raw['phonetic_prefix'][$group]['hits'] ?? [] as $position => $hit) {
                if (isset($phoneticAccepted[(int) $hit['id']])) {
                    $ranked[$group][] = ['id' => (int) $hit['id'], 'rank' => 7, 'position' => $position];
                }
            }
            usort($ranked[$group], static fn (array $a, array $b): int => [$a['rank'], $a['position'], $a['id']] <=> [$b['rank'], $b['position'], $b['id']]);
            $ids[$group] = array_values(array_unique(array_column($ranked[$group], 'id')));
        }

        $originalHits = $raw['original']['products']['hits'] ?? [];
        $strong = array_filter($originalHits, fn (array $hit): bool => $this->originalPriority($hit, $plan['normalized'], new Product) <= 4);
        $fuzzy = ($plan['structured_tokens'] ?? []) === [] && ! ($plan['layout_searched'] ?? false)
            ? array_filter($originalHits, fn (array $hit): bool => $this->originalPriority($hit, $plan['normalized'], new Product) > 4)
            : [];
        $phoneticProductIds = array_fill_keys(array_map(
            static fn (array $hit): int => (int) $hit['id'],
            $phonetic['hits']['products'] ?? [],
        ), true);
        $phoneticProductHits = array_values(array_filter(
            $raw['phonetic']['products']['hits'] ?? [],
            static fn (array $hit): bool => isset($phoneticProductIds[(int) $hit['id']]),
        ));
        $phoneticPrefixProductHits = array_values(array_filter(
            $raw['phonetic_prefix']['products']['hits'] ?? [],
            static fn (array $hit): bool => isset($phoneticProductIds[(int) $hit['id']]),
        ));
        $productWindows = ($productQueryPlan['active'] ?? false)
            ? [
                self::MODE_ORIGINAL_STRONG => ['hits' => $originalHits, 'raw_count' => count($originalHits), 'more' => count($originalHits) === $productLimit],
                self::MODE_STRICT_TRANSLITERATION => ['hits' => [], 'raw_count' => 0, 'more' => false],
                self::MODE_PHONETIC => ['hits' => [], 'raw_count' => 0, 'more' => false],
                self::MODE_PHONETIC_PREFIX => ['hits' => [], 'raw_count' => 0, 'more' => false],
                self::MODE_ORIGINAL_FUZZY => ['hits' => [], 'raw_count' => 0, 'more' => false],
            ]
            : [
                self::MODE_ORIGINAL_STRONG => ['hits' => $strong, 'raw_count' => count($originalHits), 'more' => count($originalHits) === $productLimit],
                self::MODE_STRICT_TRANSLITERATION => [
                    'hits' => $raw['strict_transliteration']['products']['hits'] ?? [],
                    'raw_count' => count($raw['strict_transliteration']['products']['hits'] ?? []),
                    'more' => isset($raw['strict_transliteration']['products']) && count($raw['strict_transliteration']['products']['hits']) === $productLimit,
                ],
                self::MODE_PHONETIC => [
                    'hits' => $phoneticProductHits,
                    'raw_count' => count($raw['phonetic']['products']['hits'] ?? []),
                    'more' => isset($raw['phonetic']['products']) && count($raw['phonetic']['products']['hits']) === $productLimit,
                ],
                self::MODE_PHONETIC_PREFIX => [
                    'hits' => $phoneticPrefixProductHits,
                    'raw_count' => count($raw['phonetic_prefix']['products']['hits'] ?? []),
                    'more' => isset($raw['phonetic_prefix']['products']) && count($raw['phonetic_prefix']['products']['hits']) === $productLimit,
                ],
                self::MODE_ORIGINAL_FUZZY => ['hits' => $fuzzy, 'raw_count' => count($originalHits), 'more' => count($originalHits) === $productLimit],
            ];

        return [
            'ids' => $ids,
            'product_windows' => $productWindows,
            'phonetic_context' => $phonetic['context'],
            'channel_sources' => [
                'strict_transliteration' => $strictSource,
                'phonetic' => $phoneticSource,
                'phonetic_prefix' => $this->channelSource($plan, 'phonetic_prefix'),
            ],
            'plan' => $plan,
            'diagnostics' => $diagnostics,
        ];
    }

    /** @return array<string, array{query:string,attributes:callable(Model):array}> */
    private function channelDefinitions(array $plan): array
    {
        $channels = [
            'original' => [
                'query' => $plan['normalized'],
                'attributes' => static fn (Model $model): array => array_merge(CatalogSearchDocument::originalAttributes($model), ['layout_terms']),
            ],
        ];
        $strictPlan = $this->channelPlan($plan, 'strict_transliteration');
        if ($strictPlan !== null) {
            $channels['strict_transliteration'] = [
                'query' => $strictPlan['strict_transliteration'],
                'attributes' => static fn (Model $model): array => ['strict_transliteration_terms'],
            ];
        }
        $phoneticPlan = $this->channelPlan($plan, 'phonetic');
        if ($phoneticPlan !== null) {
            $channels['phonetic'] = [
                'query' => implode(' ', array_merge($phoneticPlan['structured_tokens'], $phoneticPlan['phonetic_tokens'])),
                'attributes' => static fn (Model $model): array => ['phonetic_terms', 'structured_terms'],
            ];
        }
        $prefixPlan = $this->channelPlan($plan, 'phonetic_prefix');
        if ($prefixPlan !== null && ($prefixPlan['phonetic_prefix_query_tokens'] ?? []) !== []) {
            $channels['phonetic_prefix'] = [
                'query' => implode(' ', array_merge($prefixPlan['structured_tokens'], $prefixPlan['phonetic_prefix_query_tokens'])),
                'attributes' => static fn (Model $model): array => ['phonetic_terms', 'phonetic_prefix_terms', 'structured_terms'],
            ];
        }

        return $channels;
    }

    /** @return array<string,mixed> */
    private function productQueryPlan(string $query): array
    {
        $planner = app(CatalogProductQueryPlanner::class);
        if (! $planner->shouldPlan($query)) {
            return $planner->emptyPlan($query);
        }

        $phrasePlan = $planner->phrases($query);
        if ($phrasePlan['phrases'] === []) {
            return $planner->emptyPlan($query);
        }

        $resolutions = $this->resolveVehiclePhrases($phrasePlan['phrases']);
        $publicIds = $this->database->publicVehicleIds($planner->candidateIds($resolutions));

        return $planner->finalize($query, $phrasePlan, $resolutions, $publicIds);
    }

    /**
     * Resolve every bounded phrase through the existing vehicle search representations
     * in one Meilisearch multiSearch. Candidates are retained by evidence tier and only
     * the strongest non-empty tier per phrase/entity group is exposed to hierarchy.
     * This prevents a weak phonetic collision from polluting an exact/alias match while
     * preserving duplicate candidates inside the same tier for hierarchy narrowing.
     *
     * @param  list<array{key:string,start:int,length:int,text:string}>  $phrases
     * @return array<string,array<string,mixed>>
     */
    private function resolveVehiclePhrases(array $phrases): array
    {
        $queries = [];
        $metadata = [];
        $plans = [];
        $groups = array_intersect_key(CatalogSearchDocument::MODELS, array_flip(['makes', 'models', 'generations']));

        foreach ($phrases as $phrase) {
            $plan = app(CatalogSearchEnrichmentService::class)->query($phrase['text']);
            $plans[$phrase['key']] = $plan;
            $channels = $this->channelDefinitions($plan);
            unset($channels['phonetic_prefix']);
            foreach ($channels as $channel => $definition) {
                foreach ($groups as $group => $class) {
                    $model = new $class;
                    $queries[] = (new SearchQuery)
                        ->setIndexUid($model->searchableAs())
                        ->setQuery($definition['query'])
                        ->setAttributesToSearchOn($definition['attributes']($model))
                        ->setAttributesToRetrieve($this->attributesToRetrieve($model))
                        ->setMatchingStrategy('all')
                        ->setFilter(['is_active = true'])
                        ->setSort(['position:asc'])
                        ->setLimit(8)
                        ->setOffset(0);
                    $metadata[] = [$phrase['key'], $channel, $group];
                }
            }
        }

        $resolved = [];
        $acceptedByTier = [];
        foreach ($phrases as $phrase) {
            $resolved[$phrase['key']] = [
                'makes' => [],
                'models' => [],
                'generations' => [],
                'channel_priority' => [],
            ];
            foreach (array_keys($groups) as $group) {
                foreach (['strong', 'generated', 'phonetic'] as $tier) {
                    $acceptedByTier[$phrase['key']][$group][$tier] = [];
                }
            }
        }
        if ($queries === []) {
            return $resolved;
        }

        $results = $this->client()->multiSearch($queries)['results'];
        foreach ($results as $index => $result) {
            [$key, $channel, $group] = $metadata[$index];
            foreach ($result['hits'] ?? [] as $hit) {
                $evidence = $this->directVehiclePhraseEvidence($plans[$key], $group, $hit, $channel);
                if ($evidence === null) {
                    continue;
                }
                $id = (int) ($hit['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $tier = $evidence['tier'];
                $existing = $acceptedByTier[$key][$group][$tier][$id] ?? null;
                if ($existing === null || ($evidence['confidence'] ?? 1.0) < ($existing['confidence'] ?? 1.0)) {
                    $acceptedByTier[$key][$group][$tier][$id] = [
                        'id' => $id,
                        'title' => (string) ($hit['title'] ?? ''),
                        'source' => $evidence['source'],
                        'channel' => $channel,
                        'tier' => $tier,
                        'confidence' => $evidence['confidence'],
                    ];
                }
            }
        }

        foreach ($resolved as $key => &$phraseResolved) {
            foreach (array_keys($groups) as $group) {
                $selection = app(CatalogProductQueryPlanner::class)->selectCandidateTier($acceptedByTier[$key][$group] ?? []);
                $phraseResolved[$group] = $selection['ids'];
                $phraseResolved['channel_priority'][$group] = [
                    'candidates_by_tier' => $selection['candidates_by_tier'],
                    'selected_tier' => $selection['selected_tier'],
                    'ignored_lower_tiers' => $selection['ignored_lower_tiers'],
                ];
            }
        }
        unset($phraseResolved);

        return $resolved;
    }

    /** @return array{tier:string,source:string,confidence:float}|null */
    private function directVehiclePhraseEvidence(array $plan, string $group, array $hit, string $channel): ?array
    {
        $entity = $hit['vehicle_entities'][0] ?? null;
        if (! is_array($entity)) {
            return null;
        }

        [$canonical, $aliases] = match ($group) {
            'makes' => [[(string) ($entity['make_title'] ?? '')], $entity['make_aliases'] ?? []],
            'models' => [[(string) ($entity['model_title'] ?? '')], $entity['model_aliases'] ?? []],
            'generations' => [[
                (string) ($entity['generation_title'] ?? ''),
                (string) ($hit['years_label'] ?? ''),
                (string) ($hit['body'] ?? ''),
            ], []],
            default => [[], []],
        };
        $canonical = SearchText::unique($canonical);
        $aliases = SearchText::unique($aliases);
        $terms = SearchText::unique(array_merge($canonical, $aliases));
        if ($terms === []) {
            return null;
        }

        $channelPlan = $this->channelPlan($plan, $channel) ?? $plan;
        $requiredStructured = $channelPlan['structured_tokens'] ?? [];
        if (! $this->structuredTokensMatch($requiredStructured, app(CatalogSearchEnrichmentService::class)->structuredTerms($terms))) {
            return null;
        }

        if ($channel === 'original') {
            if ($this->allTokensMatch($plan['normalized'], $canonical, false)) {
                return ['tier' => 'strong', 'source' => 'original', 'confidence' => 0.0];
            }
            if ($aliases !== [] && $this->allTokensMatch($plan['normalized'], $aliases, false)) {
                return ['tier' => 'strong', 'source' => 'alias', 'confidence' => 0.0];
            }
            $layoutTerms = [];
            foreach ($terms as $term) {
                $alternative = app(CatalogSearchNormalizer::class)->keyboardLayoutAlternative($term);
                if ($alternative !== null) {
                    $layoutTerms[] = $alternative;
                }
            }
            if ($this->allTokensMatch($plan['normalized'], $layoutTerms, false)) {
                return ['tier' => 'generated', 'source' => 'layout', 'confidence' => 0.0];
            }

            return null;
        }

        if ($channel === 'strict_transliteration') {
            $strictTerms = array_map(
                static fn (string $term): string => app(CatalogSearchNormalizer::class)->strictTransliteration($term),
                $terms,
            );
            if ($this->allTokensMatch((string) ($channelPlan['strict_transliteration'] ?? ''), $strictTerms, false)) {
                return ['tier' => 'generated', 'source' => 'transliteration', 'confidence' => 0.0];
            }

            return null;
        }

        if ($channel === 'phonetic') {
            $wordTokens = $channelPlan['word_tokens'] ?? [];
            if ($wordTokens === []) {
                return null;
            }
            $evaluation = app(CatalogSearchConfidenceGate::class)->evaluate($wordTokens, $terms, $canonical[0] ?? null);
            if (! $evaluation['accepted']) {
                return null;
            }

            return ['tier' => 'phonetic', 'source' => 'phonetic', 'confidence' => (float) $evaluation['score']];
        }

        return null;
    }

    /** @return array{query:string,attributes:list<string>} */
    private function entityAwareProductDefinition(array $productQueryPlan): array
    {
        return [
            'query' => (string) ($productQueryPlan['residual_query'] ?? ''),
            'attributes' => ['title', 'sku', 'variant_skus', 'structured_terms'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function channelPlan(array $plan, string $channel): ?array
    {
        $layout = $plan['layout'] ?? null;
        if (is_array($layout)) {
            if ($channel === 'strict_transliteration') {
                return ($layout['strict_transliteration'] ?? '') !== '' && ($layout['word_tokens'] ?? []) !== [] ? $layout : null;
            }

            // Once the generic plausibility gate accepts a keyboard alternative, the
            // generated cross-language channels come from that representation only.
            // This prevents gibberish from the wrong layout being phonetic-matched in
            // parallel with the corrected representation.
            return in_array($channel, $layout['channels'] ?? [], true) ? $layout : null;
        }

        return in_array($channel, $plan['channels'] ?? [], true) ? $plan : null;
    }

    private function channelSource(array $plan, string $channel): ?string
    {
        $selected = $this->channelPlan($plan, $channel);
        if ($selected === null) {
            return null;
        }

        return is_array($plan['layout'] ?? null) ? 'layout' : 'original';
    }

    /** @return array<string,array<string,mixed>> */
    private function mergePhoneticRaw(array $full, array $prefix): array
    {
        $merged = [];
        foreach (array_keys(CatalogSearchDocument::MODELS) as $group) {
            $merged[$group] = $full[$group] ?? $prefix[$group] ?? ['hits' => []];
            $merged[$group]['hits'] = array_merge(
                $full[$group]['hits'] ?? [],
                $prefix[$group]['hits'] ?? [],
            );
        }

        return $merged;
    }

    /** @return list<string> */
    private function attributesToRetrieve(Model $model): array
    {
        return array_values(array_unique(array_merge(
            ['id', 'title', 'vehicle_entities'],
            CatalogSearchDocument::attributes($model),
            ['make_id', 'model_id', 'make_ids', 'model_ids', 'generation_ids'],
        )));
    }

    private function originalPriority(array $hit, string $query, Model $model): int
    {
        $canonical = $this->values($hit, array_merge(CatalogSearchDocument::canonicalAttributes($model), CatalogSearchDocument::skuAttributes($model)));
        $aliases = $this->values($hit, CatalogSearchDocument::aliasAttributes($model));
        $layout = $this->values($hit, ['layout_terms']);

        if ($this->allTokensMatch($query, $canonical, false)) {
            return 0;
        }
        if ($this->allTokensMatch($query, array_merge($canonical, $aliases), false)) {
            return 1;
        }
        if ($this->allTokensMatch($query, $canonical, true)) {
            return 2;
        }
        if ($this->allTokensMatch($query, array_merge($canonical, $aliases), true)) {
            return 3;
        }
        if ($this->allTokensMatch($query, $layout, false)) {
            return 4;
        }

        return 8;
    }

    private function priorityReason(int $priority): string
    {
        return match ($priority) {
            0 => 'canonical_exact',
            1 => 'verified_alias_exact',
            2 => 'canonical_prefix',
            3 => 'verified_alias_prefix',
            4 => 'keyboard_layout',
            default => 'normal_typo',
        };
    }

    /** @return list<string> */
    private function values(array $hit, array $attributes): array
    {
        $values = [];
        foreach ($attributes as $attribute) {
            foreach ((array) ($hit[$attribute] ?? []) as $value) {
                if (is_scalar($value) && (string) $value !== '') {
                    $values[] = (string) $value;
                }
            }
        }

        return $values;
    }

    private function allTokensMatch(string $query, array $values, bool $prefix): bool
    {
        $normalizer = app(CatalogSearchNormalizer::class);
        $queryTokens = $normalizer->tokens($query);
        if ($queryTokens === [] || $values === []) {
            return false;
        }
        $candidateTokens = [];
        foreach ($values as $value) {
            $candidateTokens = array_merge($candidateTokens, $normalizer->tokens($value));
        }

        foreach ($queryTokens as $queryToken) {
            $matched = false;
            foreach ($candidateTokens as $candidateToken) {
                if ($prefix ? str_starts_with($candidateToken, $queryToken) : $candidateToken === $queryToken) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{
     *   hits:array<string,list<array<string,mixed>>>,
     *   context:array{make_ids:list<int>,model_ids:list<int>},
     *   diagnostics:list<array<string,mixed>>
     * }
     */
    private function resolvePhonetic(array $plan, array $raw): array
    {
        $empty = [
            'hits' => array_fill_keys(array_keys(CatalogSearchDocument::MODELS), []),
            'context' => ['make_ids' => [], 'model_ids' => []],
            'diagnostics' => [],
        ];
        $queryTokens = $plan['word_tokens'] ?? [];
        if ($raw === [] || $queryTokens === []) {
            return $empty;
        }

        $gate = app(CatalogSearchConfidenceGate::class);
        $structured = $plan['structured_tokens'] ?? [];
        $diagnostics = [];

        $makeFull = [];
        $makePrefix = [];
        $makeHitById = [];
        foreach ($raw['makes']['hits'] ?? [] as $hit) {
            $entity = $hit['vehicle_entities'][0] ?? null;
            if (! is_array($entity) || ! $this->structuredTokensMatch($structured, $entity['structured_terms'] ?? [])) {
                $diagnostics[] = $this->diagnostic('makes', $hit, ['accepted' => false, 'score' => 1.0, 'reason' => 'structured_mismatch']);

                continue;
            }
            $id = (int) $hit['id'];
            $makeHitById[$id] = $hit;
            [$full, $prefix] = $this->phoneticEvaluations($gate, $queryTokens, $this->makeTerms($entity), (string) ($entity['make_title'] ?? ''));
            if ($full['accepted']) {
                $makeFull[] = ['id' => $id, 'score' => $full['score']];
            } elseif ($prefix['accepted']) {
                $makePrefix[] = ['id' => $id, 'score' => $prefix['score']];
            } else {
                $diagnostics[] = $this->diagnostic('makes', $hit, $prefix['reason'] === 'not_prefix' ? $full : $prefix);
            }
        }
        $makeSelection = $this->selectPreferredPhonetic($gate, $makeFull, $makePrefix);
        $makeIds = $makeSelection['accepted_ids'];
        foreach (array_merge($makeFull, $makePrefix) as $candidate) {
            $accepted = in_array($candidate['id'], $makeIds, true);
            $diagnostics[] = $this->diagnostic('makes', $makeHitById[$candidate['id']], [
                'accepted' => $accepted,
                'score' => $candidate['score'],
                'reason' => $accepted ? $makeSelection['mode'] : $makeSelection['reason'],
            ]);
        }
        $makeHits = array_values(array_intersect_key($makeHitById, array_flip($makeIds)));

        $modelFull = [];
        $modelPrefix = [];
        $modelHitById = [];
        $modelExpansionIds = [];
        foreach ($raw['models']['hits'] ?? [] as $hit) {
            $entity = $hit['vehicle_entities'][0] ?? null;
            if (! is_array($entity) || ! $this->structuredTokensMatch($structured, $entity['structured_terms'] ?? [])) {
                $diagnostics[] = $this->diagnostic('models', $hit, ['accepted' => false, 'score' => 1.0, 'reason' => 'structured_mismatch']);

                continue;
            }
            $id = (int) $hit['id'];
            $makeId = (int) ($entity['make_id'] ?? 0);
            $modelHitById[$id] = $hit;
            if ($structured === [] && in_array($makeId, $makeIds, true)) {
                [$makeFullEvaluation] = $this->phoneticEvaluations($gate, $queryTokens, $this->makeTerms($entity));
                if ($makeFullEvaluation['accepted']) {
                    $modelExpansionIds[] = $id;
                    $diagnostics[] = $this->diagnostic('models', $hit, ['accepted' => true, 'score' => $makeFullEvaluation['score'], 'reason' => 'hierarchy_make']);

                    continue;
                }
            }

            $terms = $this->modelTerms($entity, count($queryTokens) > 1 || $structured !== []);
            [$full, $prefix] = $this->phoneticEvaluations($gate, $queryTokens, $terms, (string) ($entity['model_title'] ?? ''));
            if ($full['accepted']) {
                $modelFull[] = ['id' => $id, 'score' => $full['score']];
            } elseif ($prefix['accepted']) {
                $modelPrefix[] = ['id' => $id, 'score' => $prefix['score']];
            } else {
                $diagnostics[] = $this->diagnostic('models', $hit, $prefix['reason'] === 'not_prefix' ? $full : $prefix);
            }
        }
        $modelSelection = $this->selectPreferredPhonetic($gate, $modelFull, $modelPrefix);
        foreach (array_merge($modelFull, $modelPrefix) as $candidate) {
            $accepted = in_array($candidate['id'], $modelSelection['accepted_ids'], true);
            $diagnostics[] = $this->diagnostic('models', $modelHitById[$candidate['id']], [
                'accepted' => $accepted,
                'score' => $candidate['score'],
                'reason' => $accepted ? $modelSelection['mode'] : $modelSelection['reason'],
            ]);
        }
        $modelIds = array_values(array_unique(array_merge($modelExpansionIds, $modelSelection['accepted_ids'])));
        $modelHits = array_values(array_intersect_key($modelHitById, array_flip($modelIds)));

        // A strict structured token can legitimately live below Model (for example a
        // generation year). Derive the model identity from Generation/Product hits,
        // but only when all structured tokens and all word tokens agree on one entity.
        if ($modelIds === [] && $structured !== []) {
            $derivedFull = [];
            $derivedPrefix = [];
            foreach (['generations', 'products'] as $group) {
                foreach ($raw[$group]['hits'] ?? [] as $hit) {
                    foreach ($hit['vehicle_entities'] ?? [] as $entity) {
                        $modelId = (int) ($entity['model_id'] ?? 0);
                        if ($modelId <= 0 || ! $this->structuredTokensMatch($structured, $entity['structured_terms'] ?? [])) {
                            continue;
                        }
                        [$full, $prefix] = $this->phoneticEvaluations(
                            $gate,
                            $queryTokens,
                            $this->modelTerms($entity, true),
                            (string) ($entity['model_title'] ?? ''),
                        );
                        if ($full['accepted']) {
                            $derivedFull[] = ['id' => $modelId, 'score' => $full['score']];
                        } elseif ($prefix['accepted']) {
                            $derivedPrefix[] = ['id' => $modelId, 'score' => $prefix['score']];
                        }
                    }
                }
            }
            $derivedSelection = $this->selectPreferredPhonetic($gate, $derivedFull, $derivedPrefix);
            $modelIds = $derivedSelection['accepted_ids'];
        }

        $generationHits = [];
        $generationDirectFull = [];
        $generationDirectPrefix = [];
        $generationHitById = [];
        foreach ($raw['generations']['hits'] ?? [] as $hit) {
            $id = (int) $hit['id'];
            $generationHitById[$id] = $hit;
            $acceptedByHierarchy = false;
            foreach ($hit['vehicle_entities'] ?? [] as $entity) {
                if (! $this->structuredTokensMatch($structured, $entity['structured_terms'] ?? [])) {
                    continue;
                }
                $modelId = (int) ($entity['model_id'] ?? 0);
                $makeId = (int) ($entity['make_id'] ?? 0);
                if ($modelIds !== [] && in_array($modelId, $modelIds, true)) {
                    $acceptedByHierarchy = true;
                    break;
                }
                if ($modelIds === [] && $makeIds !== [] && in_array($makeId, $makeIds, true)) {
                    $acceptedByHierarchy = true;
                    break;
                }
                if ($modelIds === [] && $makeIds === []) {
                    [$full, $prefix] = $this->phoneticEvaluations($gate, $queryTokens, $this->generationTerms($entity), (string) ($entity['generation_title'] ?? ''));
                    if ($full['accepted']) {
                        $generationDirectFull[] = ['id' => $id, 'score' => $full['score']];
                    } elseif ($prefix['accepted']) {
                        $generationDirectPrefix[] = ['id' => $id, 'score' => $prefix['score']];
                    }
                }
            }
            if ($acceptedByHierarchy) {
                $generationHits[] = $hit;
            }
        }
        if ($modelIds === [] && $makeIds === []) {
            $generationSelection = $this->selectPreferredPhonetic($gate, $generationDirectFull, $generationDirectPrefix);
            foreach ($generationSelection['accepted_ids'] as $id) {
                if (isset($generationHitById[$id])) {
                    $generationHits[] = $generationHitById[$id];
                }
            }
        }
        $generationHits = array_values(array_reduce($generationHits, static function (array $carry, array $hit): array {
            $carry[(int) $hit['id']] = $hit;

            return $carry;
        }, []));

        $productHits = [];
        foreach ($raw['products']['hits'] ?? [] as $rawIndex => $hit) {
            $accepted = false;
            foreach ($hit['vehicle_entities'] ?? [] as $entity) {
                if (! $this->structuredTokensMatch($structured, $entity['structured_terms'] ?? [])) {
                    continue;
                }
                $modelId = (int) ($entity['model_id'] ?? 0);
                $makeId = (int) ($entity['make_id'] ?? 0);
                if ($modelIds !== [] && in_array($modelId, $modelIds, true)) {
                    $accepted = true;
                    break;
                }
                if ($modelIds === [] && $makeIds !== [] && in_array($makeId, $makeIds, true)) {
                    $accepted = true;
                    break;
                }
            }
            if ($accepted) {
                $productHits[$rawIndex] = $hit;
            } else {
                $diagnostics[] = $this->diagnostic('products', $hit, ['accepted' => false, 'score' => 1.0, 'reason' => $structured === [] ? 'hierarchy' : 'hierarchy_or_structured']);
            }
        }

        return [
            'hits' => [
                'makes' => $makeHits,
                'models' => $modelHits,
                'generations' => $generationHits,
                'products' => $productHits,
            ],
            'context' => ['make_ids' => $makeIds, 'model_ids' => $modelIds],
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array{0:array{accepted:bool,score:float,reason:string},1:array{accepted:bool,score:float,reason:string}}
     */
    private function phoneticEvaluations(CatalogSearchConfidenceGate $gate, array $queryTokens, array $terms, ?string $primaryTerm = null): array
    {
        $full = $gate->evaluate($queryTokens, $terms, $primaryTerm);
        $prefix = $full['accepted']
            ? ['accepted' => false, 'score' => $full['score'], 'reason' => 'full_word_preferred']
            : $gate->evaluatePrefix($queryTokens, $terms, $primaryTerm);

        return [$full, $prefix];
    }

    /**
     * Full-word evidence always wins. If it exists but is ambiguous we prefer EMPTY
     * instead of letting a weaker prefix interpretation guess around it.
     *
     * @return array{accepted_ids:list<int>,reason:string,mode:string}
     */
    private function selectPreferredPhonetic(CatalogSearchConfidenceGate $gate, array $full, array $prefix): array
    {
        $full = $this->dedupeCandidateScores($full);
        $prefix = $this->dedupeCandidateScores($prefix);
        if ($full !== []) {
            $selection = $gate->selectUnambiguous($full);

            return $selection + ['mode' => $selection['accepted_ids'] === [] ? 'full_word_rejected' : 'accepted'];
        }
        $selection = $gate->selectPrefixUnambiguous($prefix);

        return $selection + ['mode' => $selection['accepted_ids'] === [] ? 'prefix_rejected' : 'prefix_accepted'];
    }

    /** @return list<array{id:int,score:float}> */
    private function dedupeCandidateScores(array $candidates): array
    {
        $scores = [];
        foreach ($candidates as $candidate) {
            $id = (int) $candidate['id'];
            $score = (float) $candidate['score'];
            $scores[$id] = isset($scores[$id]) ? min($scores[$id], $score) : $score;
        }

        return array_map(
            static fn (int $id, float $score): array => ['id' => $id, 'score' => $score],
            array_keys($scores),
            array_values($scores),
        );
    }

    private function structuredTokensMatch(array $required, array $available): bool
    {
        if ($required === []) {
            return true;
        }
        $available = array_fill_keys(array_map(static fn (mixed $value): string => mb_strtolower((string) $value), $available), true);
        foreach ($required as $token) {
            if (! isset($available[mb_strtolower((string) $token)])) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function makeTerms(array $entity): array
    {
        return array_merge([(string) ($entity['make_title'] ?? '')], $entity['make_aliases'] ?? []);
    }

    /** @return list<string> */
    private function modelTerms(array $entity, bool $withMake): array
    {
        $terms = array_merge([(string) ($entity['model_title'] ?? '')], $entity['model_aliases'] ?? []);

        return $withMake ? array_merge($this->makeTerms($entity), $terms) : $terms;
    }

    /** @return list<string> */
    private function generationTerms(array $entity): array
    {
        return array_merge(
            $this->modelTerms($entity, true),
            [(string) ($entity['generation_title'] ?? '')],
        );
    }

    private function diagnostic(string $group, array $hit, array $evaluation): array
    {
        return [
            'group' => $group,
            'id' => (int) ($hit['id'] ?? 0),
            'title' => (string) ($hit['title'] ?? ''),
            'accepted' => (bool) $evaluation['accepted'],
            'score' => round((float) $evaluation['score'], 4),
            'reason' => (string) $evaluation['reason'],
        ];
    }

    public function search(string $query, ?ProductCategory $category = null, ?PartType $partType = null): array
    {
        $fingerprint = hash('sha256', $query.'|'.$category?->id.'|'.$partType?->id);
        $cursor = Cursor::fromEncoded(request()->query('search_cursor'));
        $state = $cursor?->toArray() ?? [];
        if (($state['query'] ?? null) !== $fingerprint || ! is_int($state['offset'] ?? null)
            || $state['offset'] < 0 || $state['offset'] > 20000 || ! in_array($state['channel'] ?? null, [0, 1, 2, 3, 4], true)) {
            $cursor = null;
            $state = [];
        }
        $backwards = $cursor?->pointsToPreviousItems() ?? false;
        $boundary = $state['offset'] ?? 0;
        $offset = $backwards ? max(0, $boundary - 50) : $boundary;
        $limit = $backwards ? max(1, $boundary - $offset) : 50;
        $candidates = $this->candidates($query, $category, $partType, $offset, $limit);
        $vehicleResults = $this->database->searchVehicleItems($query, $candidates['ids']);
        [$candidates, $vehicleRefilled] = $this->refillVehicleCandidates($candidates, $limit, [
            'makes' => $vehicleResults['makes']->count(),
            'models' => $vehicleResults['models']->count(),
            'generations' => $vehicleResults['generations']->count(),
        ]);
        if ($vehicleRefilled) {
            $vehicleResults = $this->database->searchVehicleItems($query, $candidates['ids']);
        }
        $channel = $state['channel'] ?? $this->firstProductMode($candidates['product_windows']);
        $window = $candidates['product_windows'][$channel] ?? ['hits' => [], 'raw_count' => 0, 'more' => false];
        $visible = [];
        $seen = [];
        $more = false;
        $edge = $offset;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $entries = [];
            foreach ($window['hits'] as $i => $hit) {
                $id = (int) $hit['id'];
                if (! isset($seen[$id])) {
                    $entries[] = ['id' => $id, 'offset' => $offset + $i];
                    $seen[$id] = true;
                }
            }
            if ($backwards) {
                $entries = array_reverse($entries);
            }
            $public = array_flip($this->database->publicProductIds(
                array_column($entries, 'id'),
                $category,
                $partType,
                $candidates['product_query_plan']['product_filters'] ?? [],
            ));
            foreach ($entries as $entry) {
                if (isset($public[$entry['id']])) {
                    $visible[] = $entry;
                }
            }
            $rawCount = (int) ($window['raw_count'] ?? count($window['hits']));
            $edge = $backwards ? $offset : $offset + $rawCount;
            $more = $backwards ? $offset > 0 : $window['more'] && $edge < 20000;
            if (count($visible) >= 13 || ! $more || $attempt === 2) {
                break;
            }
            $limit = $backwards ? min(50, $offset) : 50;
            $offset = $backwards ? max(0, $offset - 50) : $edge;
            $window = $this->productWindow(
                $query,
                $category,
                $partType,
                $channel,
                $offset,
                $limit,
                $candidates['phonetic_context'],
                $candidates['product_query_plan'] ?? null,
            );
        }

        $hasLookahead = count($visible) > 12;
        $display = array_slice($visible, 0, 12);
        if ($backwards) {
            $display = array_reverse($display);
        }
        $makeCursor = fn (int $position, bool $next) => new Cursor(['offset' => $position, 'channel' => $channel, 'query' => $fingerprint], $next);
        $first = $display[0]['offset'] ?? $boundary;
        $last = $display === [] ? $boundary : $display[array_key_last($display)]['offset'] + 1;
        $limited = ! $hasLookahead && $more;
        $previous = $backwards
            ? ($hasLookahead || $more ? $makeCursor($limited ? $edge : $first, false) : null)
            : ($cursor && $first > 0 ? $makeCursor($first, false) : null);
        $next = $backwards
            ? $makeCursor($last, true)
            : ($hasLookahead || $more ? $makeCursor($limited ? $edge : $last, true) : null);
        $products = new CatalogSearchPaginator($this->database->hydrateProducts(array_column($display, 'id')), $next, $previous, $limited);

        return $vehicleResults + ['products' => $products];
    }

    /**
     * Refill only under-filled vehicle groups, only when the previous Meilisearch
     * window for at least one channel was full. At most three 50-hit windows are
     * considered per group; all under-filled groups share one multiSearch per round.
     *
     * @param  array{makes:int,models:int,generations:int}  $visibleCounts
     * @return array{0:array<string,mixed>,1:bool}
     */
    private function refillVehicleCandidates(array $candidates, int $productLimit, array $visibleCounts): array
    {
        $raw = $candidates['raw'] ?? [];
        $plan = $candidates['plan'];
        $channels = $this->channelDefinitions($plan);
        $latestCounts = [];
        foreach (array_keys($channels) as $channel) {
            foreach (['makes', 'models', 'generations'] as $group) {
                $latestCounts[$channel][$group] = count($raw[$channel][$group]['hits'] ?? []);
            }
        }

        $refilled = false;
        for ($window = 1; $window < self::VEHICLE_MAX_WINDOWS; $window++) {
            $needed = [];
            foreach (['makes', 'models', 'generations'] as $group) {
                if (($visibleCounts[$group] ?? 0) >= self::VEHICLE_FINAL_LIMIT) {
                    continue;
                }
                foreach (array_keys($channels) as $channel) {
                    if (($latestCounts[$channel][$group] ?? 0) === self::VEHICLE_WINDOW) {
                        $needed[$group] = true;
                        break;
                    }
                }
            }
            if ($needed === []) {
                break;
            }

            $queries = [];
            $metadata = [];
            foreach ($channels as $channel => $definition) {
                foreach (array_keys($needed) as $group) {
                    if (($latestCounts[$channel][$group] ?? 0) !== self::VEHICLE_WINDOW) {
                        continue;
                    }
                    $class = CatalogSearchDocument::MODELS[$group];
                    $model = new $class;
                    $queries[] = (new SearchQuery)
                        ->setIndexUid($model->searchableAs())
                        ->setQuery($definition['query'])
                        ->setAttributesToSearchOn($definition['attributes']($model))
                        ->setAttributesToRetrieve($this->attributesToRetrieve($model))
                        ->setMatchingStrategy('all')
                        ->setFilter(['is_active = true'])
                        ->setSort(['position:asc'])
                        ->setLimit(self::VEHICLE_WINDOW)
                        ->setOffset($window * self::VEHICLE_WINDOW);
                    $metadata[] = [$channel, $group];
                }
            }
            if ($queries === []) {
                break;
            }

            $results = $this->client()->multiSearch($queries)['results'];
            foreach ($results as $index => $result) {
                [$channel, $group] = $metadata[$index];
                $hits = $result['hits'] ?? [];
                $latestCounts[$channel][$group] = count($hits);
                $raw[$channel][$group]['hits'] = array_merge($raw[$channel][$group]['hits'] ?? [], $hits);
            }
            $refilled = true;
            $productQueryPlan = $candidates['product_query_plan'] ?? null;
            $ranked = $this->rankCandidateRaw($plan, $raw, $productLimit, $productQueryPlan);
            $candidates = $ranked + ['raw' => $raw, 'product_query_plan' => $productQueryPlan];

            if ($window + 1 < self::VEHICLE_MAX_WINDOWS) {
                $public = $this->database->publicVehicleIds([
                    'makes' => $candidates['ids']['makes'],
                    'models' => $candidates['ids']['models'],
                    'generations' => $candidates['ids']['generations'],
                ]);
                $visibleCounts = [
                    'makes' => min(self::VEHICLE_FINAL_LIMIT, count($public['makes'])),
                    'models' => min(self::VEHICLE_FINAL_LIMIT, count($public['models'])),
                    'generations' => min(self::VEHICLE_FINAL_LIMIT, count($public['generations'])),
                ];
            }
        }

        return [$candidates, $refilled];
    }

    private function firstProductMode(array $windows): int
    {
        foreach ([self::MODE_ORIGINAL_STRONG, self::MODE_STRICT_TRANSLITERATION, self::MODE_PHONETIC, self::MODE_PHONETIC_PREFIX, self::MODE_ORIGINAL_FUZZY] as $mode) {
            if (($windows[$mode]['hits'] ?? []) !== []) {
                return $mode;
            }
        }

        return self::MODE_ORIGINAL_STRONG;
    }

    protected function productWindow(
        string $query,
        ?ProductCategory $category,
        ?PartType $partType,
        int $channel,
        int $offset,
        int $limit,
        array $phoneticContext = ['make_ids' => [], 'model_ids' => []],
        ?array $productQueryPlan = null,
    ): array {
        $plan = app(CatalogSearchEnrichmentService::class)->query($query);
        $model = new Product;
        $strictPlan = $this->channelPlan($plan, 'strict_transliteration') ?? $plan;
        $phoneticPlan = $this->channelPlan($plan, 'phonetic') ?? $plan;
        $prefixPlan = $this->channelPlan($plan, 'phonetic_prefix') ?? $phoneticPlan;
        $definition = ($productQueryPlan['active'] ?? false)
            ? $this->entityAwareProductDefinition($productQueryPlan)
            : match ($channel) {
                self::MODE_STRICT_TRANSLITERATION => ['query' => $strictPlan['strict_transliteration'], 'attributes' => ['strict_transliteration_terms']],
                self::MODE_PHONETIC => [
                    'query' => implode(' ', array_merge($phoneticPlan['structured_tokens'], $phoneticPlan['phonetic_tokens'])),
                    'attributes' => ['phonetic_terms', 'structured_terms'],
                ],
                self::MODE_PHONETIC_PREFIX => [
                    'query' => implode(' ', array_merge($prefixPlan['structured_tokens'], $prefixPlan['phonetic_prefix_query_tokens'] ?? [])),
                    'attributes' => ['phonetic_terms', 'phonetic_prefix_terms', 'structured_terms'],
                ],
                default => ['query' => $plan['normalized'], 'attributes' => array_merge(CatalogSearchDocument::originalAttributes($model), ['layout_terms'])],
            };
        $search = (new SearchQuery)
            ->setIndexUid($model->searchableAs())
            ->setQuery($definition['query'])
            ->setAttributesToSearchOn($definition['attributes'])
            ->setAttributesToRetrieve($this->attributesToRetrieve($model))
            ->setMatchingStrategy('all')->setFilter($this->productFilters($category, $partType, $productQueryPlan))->setSort(['position:asc'])
            ->setOffset($offset)->setLimit($limit);
        $result = $this->client()->multiSearch([$search])['results'][0];
        $rawHits = $result['hits'];
        if ($productQueryPlan['active'] ?? false) {
            return ['hits' => $rawHits, 'raw_count' => count($rawHits), 'more' => count($rawHits) === $limit];
        }

        $hits = match ($channel) {
            self::MODE_ORIGINAL_STRONG => array_filter($rawHits, fn (array $hit): bool => $this->originalPriority($hit, $plan['normalized'], $model) <= 4),
            self::MODE_STRICT_TRANSLITERATION => array_filter(
                $rawHits,
                fn (array $hit): bool => $this->structuredTokensMatch($strictPlan['structured_tokens'] ?? [], $hit['structured_terms'] ?? [])
                    && $this->allTokensMatch(
                        $strictPlan['strict_transliteration'],
                        $this->values($hit, ['strict_transliteration_terms']),
                        false,
                    ),
            ),
            self::MODE_ORIGINAL_FUZZY => ($plan['structured_tokens'] ?? []) === [] && ! ($plan['layout_searched'] ?? false)
                ? array_filter($rawHits, fn (array $hit): bool => $this->originalPriority($hit, $plan['normalized'], $model) > 4)
                : [],
            self::MODE_PHONETIC, self::MODE_PHONETIC_PREFIX => array_filter($rawHits, function (array $hit) use ($phoneticContext, $phoneticPlan): bool {
                foreach ($hit['vehicle_entities'] ?? [] as $entity) {
                    if (! $this->structuredTokensMatch($phoneticPlan['structured_tokens'] ?? [], $entity['structured_terms'] ?? [])) {
                        continue;
                    }
                    if ($phoneticContext['model_ids'] !== [] && in_array((int) ($entity['model_id'] ?? 0), $phoneticContext['model_ids'], true)) {
                        return true;
                    }
                    if ($phoneticContext['model_ids'] === [] && $phoneticContext['make_ids'] !== [] && in_array((int) ($entity['make_id'] ?? 0), $phoneticContext['make_ids'], true)) {
                        return true;
                    }
                }

                return false;
            }),
            default => $rawHits,
        };

        return ['hits' => $hits, 'raw_count' => count($rawHits), 'more' => count($rawHits) === $limit];
    }

    /** @return list<string> */
    private function productFilters(?ProductCategory $category, ?PartType $partType, ?array $productQueryPlan = null): array
    {
        $filters = ['is_active = true'];
        if ($category) {
            $filters[] = 'category_id IN ['.implode(',', $this->database->categoryIds($category)).']';
        }
        if ($partType) {
            $filters[] = 'part_type_id IN ['.implode(',', $this->database->partTypeIds($partType)).']';
        }
        $vehicle = $productQueryPlan['product_filters'] ?? [];
        if (($vehicle['make_id'] ?? null) !== null) {
            $filters[] = 'make_ids = '.(int) $vehicle['make_id'];
        }
        if (($vehicle['model_id'] ?? null) !== null) {
            $filters[] = 'model_ids = '.(int) $vehicle['model_id'];
        }
        if (($vehicle['generation_id'] ?? null) !== null) {
            $filters[] = 'generation_ids = '.(int) $vehicle['generation_id'];
        }

        return $filters;
    }

    public function explain(string $query): array
    {
        $result = $this->candidates($query, null, null, 0, 20);
        $layout = $result['plan']['layout'] ?? null;
        $layoutEnabled = is_array($layout);
        $phoneticSource = $result['channel_sources']['phonetic'] ?? null;
        $phoneticEnabled = $phoneticSource !== null;
        $phoneticAccepted = $result['phonetic_context']['make_ids'] !== [] || $result['phonetic_context']['model_ids'] !== [];
        $rejectedReasons = array_values(array_unique(array_column(array_filter(
            $result['diagnostics'],
            static fn (array $decision): bool => ! ($decision['accepted'] ?? false),
        ), 'reason')));
        $layoutAccepted = collect($result['diagnostics'])->contains(
            static fn (array $decision): bool => ($decision['accepted'] ?? false)
                && in_array($decision['source'] ?? null, ['layout', 'layout_index'], true),
        );

        return [
            'query' => $result['plan'],
            'channel_status' => [
                'original' => ['enabled' => true, 'accepted' => null, 'reason' => 'primary'],
                'layout' => [
                    'enabled' => $layoutEnabled,
                    'accepted' => $layoutEnabled ? $layoutAccepted : null,
                    'reason' => $layoutEnabled ? 'reenriched_in_same_multi_search' : (($result['plan']['layout_alternative'] ?? null) !== null ? 'not_meaningful' : 'not_applicable'),
                ],
                'strict_transliteration' => [
                    'enabled' => ($result['channel_sources']['strict_transliteration'] ?? null) !== null,
                    'accepted' => null,
                    'reason' => (($result['channel_sources']['strict_transliteration'] ?? null) === 'layout' ? 'layout_reenriched_typo_disabled' : 'typo_disabled'),
                ],
                'phonetic' => [
                    'enabled' => $phoneticEnabled,
                    'accepted' => $phoneticAccepted,
                    'reason' => ($this->channelPlan($result['plan'], 'phonetic')['word_tokens'] ?? []) === [] ? 'no_word_tokens' : ($phoneticAccepted ? ($phoneticSource === 'layout' ? 'layout_accepted' : 'accepted') : ($rejectedReasons[0] ?? ($phoneticEnabled ? 'no_match' : 'not_applicable'))),
                ],
                'phonetic_prefix' => [
                    'enabled' => ($result['channel_sources']['phonetic_prefix'] ?? null) !== null,
                    'accepted' => collect($result['diagnostics'])->contains(fn (array $decision): bool => ($decision['reason'] ?? null) === 'prefix_accepted' && ($decision['accepted'] ?? false)),
                    'reason' => (($result['channel_sources']['phonetic_prefix'] ?? null) === 'layout' ? 'layout_php_confidence_gated' : 'php_confidence_gated'),
                ],
                'structured_exact' => [
                    'enabled' => ($result['plan']['structured_tokens'] ?? []) !== [],
                    'accepted' => null,
                    'reason' => 'token_level_exact',
                ],
            ],
            'product_query_plan' => $result['product_query_plan'] ?? app(CatalogProductQueryPlanner::class)->emptyPlan($query),
            'candidate_ids' => $result['ids'],
            'phonetic_context' => $result['phonetic_context'],
            'candidate_decisions' => $result['diagnostics'],
        ];
    }
}
