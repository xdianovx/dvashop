<?php

namespace App\Services\Search;

use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Support\Collection;

final class CatalogProductQueryPlanner
{
    private const MAX_TOKENS = 6;

    private const MAX_NGRAM_TOKENS = 3;

    private const MAX_PHRASES = 15;

    private const EVIDENCE_TIERS = ['strong', 'generated', 'phonetic'];

    private const CONFIDENCE_EPSILON = 0.0001;

    public function __construct(private readonly CatalogSearchNormalizer $normalizer) {}

    /**
     * Select the strongest non-empty evidence tier without collapsing duplicates
     * inside that tier; hierarchy is responsible for any later disambiguation.
     *
     * @param  array<string,array<int,array{id:int,title:string,source:string,channel:string,tier:string,confidence:float}>>  $candidatesByTier
     * @return array{ids:list<int>,selected_tier:?string,candidates_by_tier:array<string,list<array<string,mixed>>>,ignored_lower_tiers:list<string>}
     */
    public function selectCandidateTier(array $candidatesByTier): array
    {
        $normalized = [];
        $selectedTier = null;
        foreach (self::EVIDENCE_TIERS as $tier) {
            $normalized[$tier] = array_values($candidatesByTier[$tier] ?? []);
            if ($selectedTier === null && $normalized[$tier] !== []) {
                $selectedTier = $tier;
            }
        }

        $selected = $selectedTier === null ? [] : $normalized[$selectedTier];
        $selectedIndex = $selectedTier === null ? null : array_search($selectedTier, self::EVIDENCE_TIERS, true);
        $ignoredLowerTiers = [];
        if (is_int($selectedIndex)) {
            foreach (array_slice(self::EVIDENCE_TIERS, $selectedIndex + 1) as $lowerTier) {
                if ($normalized[$lowerTier] !== []) {
                    $ignoredLowerTiers[] = $lowerTier;
                }
            }
        }

        return [
            'ids' => array_values(array_unique(array_map(
                static fn (array $candidate): int => (int) $candidate['id'],
                $selected,
            ))),
            'selected_tier' => $selectedTier,
            'candidates_by_tier' => $normalized,
            'ignored_lower_tiers' => $ignoredLowerTiers,
        ];
    }

    /**
     * Select one evidence tier for the whole phrase across entity groups.
     * Only groups with DB-public candidates participate; specificity is applied later.
     *
     * @param  array<string,array<string,mixed>>  $channelPriority
     * @param  array<string,int>  $publicCandidateCounts
     * @return array{selected_tier:?string,eligible_groups:list<string>,ignored_groups_due_to_lower_tier:list<string>}
     */
    public function selectGlobalPhraseTier(array $channelPriority, array $publicCandidateCounts): array
    {
        $eligible = [];
        $ignored = [];
        $selectedTier = null;

        foreach (self::EVIDENCE_TIERS as $tier) {
            $groups = [];
            foreach (['makes', 'models', 'generations'] as $group) {
                if (($publicCandidateCounts[$group] ?? 0) <= 0) {
                    continue;
                }
                if (($channelPriority[$group]['selected_tier'] ?? null) === $tier) {
                    $groups[] = $group;
                }
            }

            if ($groups !== []) {
                $selectedTier = $tier;
                $eligible = $groups;
                break;
            }
        }

        if ($selectedTier !== null) {
            $selectedIndex = array_search($selectedTier, self::EVIDENCE_TIERS, true);
            foreach (['makes', 'models', 'generations'] as $group) {
                if (($publicCandidateCounts[$group] ?? 0) <= 0 || in_array($group, $eligible, true)) {
                    continue;
                }
                $groupTier = $channelPriority[$group]['selected_tier'] ?? null;
                $groupIndex = $groupTier === null ? false : array_search($groupTier, self::EVIDENCE_TIERS, true);
                if (is_int($selectedIndex) && is_int($groupIndex) && $groupIndex > $selectedIndex) {
                    $ignored[] = $group;
                }
            }
        }

        return [
            'selected_tier' => $selectedTier,
            'eligible_groups' => $eligible,
            'ignored_groups_due_to_lower_tier' => $ignored,
        ];
    }

    public function shouldPlan(string $query): bool
    {
        $tokens = $this->normalizer->classifiedTokens($query);

        return count($tokens) >= 2 && count($tokens) <= self::MAX_TOKENS;
    }

    /**
     * @return array{tokens:list<array{index:int,original:string,normalized:string,type:string}>,phrases:list<array{key:string,start:int,length:int,text:string}>}
     */
    public function phrases(string $query): array
    {
        $tokens = $this->tokens($query);
        $phrases = [];

        for ($length = min(self::MAX_NGRAM_TOKENS, count($tokens)); $length >= 1; $length--) {
            for ($start = 0; $start + $length <= count($tokens); $start++) {
                $slice = array_slice($tokens, $start, $length);
                $text = trim(implode(' ', array_column($slice, 'normalized')));
                if ($text === '') {
                    continue;
                }
                $phrases[] = [
                    'key' => $start.':'.$length,
                    'start' => $start,
                    'length' => $length,
                    'text' => $text,
                ];
                if (count($phrases) >= self::MAX_PHRASES) {
                    break 2;
                }
            }
        }

        return ['tokens' => $tokens, 'phrases' => $phrases];
    }

    /**
     * Collapse all bounded phrase results into one batch for the DB public-visibility gate.
     *
     * @param  array<string,array{makes:list<int>,models:list<int>,generations:list<int>}>  $resolutions
     * @return array{makes:list<int>,models:list<int>,generations:list<int>}
     */
    public function candidateIds(array $resolutions): array
    {
        return [
            'makes' => $this->ids($resolutions, 'makes'),
            'models' => $this->ids($resolutions, 'models'),
            'generations' => $this->ids($resolutions, 'generations'),
        ];
    }

    /**
     * @param  array{tokens:list<array{index:int,original:string,normalized:string,type:string}>,phrases:list<array{key:string,start:int,length:int,text:string}>}  $phrasePlan
     * @param  array<string,array{makes:list<int>,models:list<int>,generations:list<int>}>  $resolutions
     * @param  array{makes:list<int>,models:list<int>,generations:list<int>}|null  $publicIds
     * @return array<string,mixed>
     */
    public function finalize(string $query, array $phrasePlan, array $resolutions, ?array $publicIds = null): array
    {
        $allIds = $this->candidateIds($resolutions);
        // Pure planner tests may pass null; production always supplies the DB-validated set.
        $publicIds ??= $allIds;
        $publicSets = [
            'makes' => array_fill_keys(array_map('intval', $publicIds['makes'] ?? []), true),
            'models' => array_fill_keys(array_map('intval', $publicIds['models'] ?? []), true),
            'generations' => array_fill_keys(array_map('intval', $publicIds['generations'] ?? []), true),
        ];

        // Hydrate the bounded raw candidate ids, not only the public subset. Public
        // ids remain the only candidates that may resolve or become Product filters;
        // raw non-public hierarchy is retained solely as an intent/conflict guard.
        // This keeps DB authority while avoiding a second SQL pass for stale evidence.
        $makeIds = $allIds['makes'];
        $modelIds = $allIds['models'];
        $generationIds = $allIds['generations'];

        /** @var Collection<int,VehicleMake> $makes */
        $makes = $makeIds === []
            ? collect()
            : VehicleMake::query()->whereKey($makeIds)->get()->keyBy(fn (VehicleMake $make): int => (int) $make->getKey());
        /** @var Collection<int,VehicleModel> $models */
        $models = $modelIds === []
            ? collect()
            : VehicleModel::query()->with('make')->whereKey($modelIds)->get()->keyBy(fn (VehicleModel $model): int => (int) $model->getKey());
        /** @var Collection<int,VehicleGeneration> $generations */
        $generations = $generationIds === []
            ? collect()
            : VehicleGeneration::query()->with('model.make')->whereKey($generationIds)->get()->keyBy(fn (VehicleGeneration $generation): int => (int) $generation->getKey());

        $states = [];
        foreach ($phrasePlan['phrases'] as $phrase) {
            $resolution = $resolutions[$phrase['key']] ?? ['makes' => [], 'models' => [], 'generations' => []];
            $states[$phrase['key']] = $this->phraseState($phrase, $resolution, $publicSets, $makes, $models, $generations);
        }

        $staleIntents = array_values(array_filter(array_map(
            static fn (array $state): ?array => $state['stale_intent'] ?? null,
            $states,
        )));

        $context = ['make_id' => null, 'model_id' => null, 'generation_id' => null];
        $consumed = [];
        $resolved = [];
        $resolvedKeys = [];
        $hierarchyNarrowing = [];
        $rejections = [];
        $sawConflict = false;
        $sawAmbiguity = false;

        while (true) {
            $choices = [];
            foreach ($states as $key => $state) {
                if (isset($resolvedKeys[$key]) || $this->overlapsConsumed($state['phrase'], $consumed)) {
                    continue;
                }
                $evaluation = $this->evaluateState($state, $context, $staleIntents);
                if (($evaluation['candidate'] ?? null) !== null) {
                    $choices[] = [
                        'key' => $key,
                        'state' => $state,
                        'candidate' => $evaluation['candidate'],
                        'evaluation' => $evaluation,
                    ];
                }
            }

            if ($choices === []) {
                break;
            }

            usort($choices, static function (array $left, array $right): int {
                $specificity = ['make' => 1, 'model' => 2, 'generation' => 3];

                return [
                    -$left['state']['phrase']['length'],
                    -($specificity[$left['candidate']['type']] ?? 0),
                    $left['state']['phrase']['start'],
                ] <=> [
                    -$right['state']['phrase']['length'],
                    -($specificity[$right['candidate']['type']] ?? 0),
                    $right['state']['phrase']['start'],
                ];
            });

            $choice = $choices[0];
            $phrase = $choice['state']['phrase'];
            $candidate = $choice['candidate'];
            $resolvedKeys[$choice['key']] = true;

            for ($index = $phrase['start']; $index < $phrase['start'] + $phrase['length']; $index++) {
                $consumed[$index] = true;
            }

            $before = $context;
            $context = [
                'make_id' => $candidate['make_id'] ?? $context['make_id'],
                'model_id' => $candidate['model_id'] ?? $context['model_id'],
                'generation_id' => $candidate['generation_id'] ?? $context['generation_id'],
            ];
            $resolved[] = $candidate + ['phrase' => $phrase['text']];
            $hierarchyNarrowing[] = [
                'phrase' => $phrase['text'],
                'type' => $candidate['type'],
                'candidates_before' => $choice['evaluation']['candidates_before'],
                'candidates_after' => $choice['evaluation']['candidates_after'],
                'context_before' => $before,
                'context_after' => $context,
                'reason' => 'resolved_after_hierarchy',
                'eligible_before_confidence' => $choice['evaluation']['eligible_before_confidence'] ?? [],
                'group_confidences' => $choice['evaluation']['group_confidences'] ?? [],
                'best_confidence' => $choice['evaluation']['best_confidence'] ?? null,
                'confidence_eligible_groups' => $choice['evaluation']['confidence_eligible_groups'] ?? [],
                'ignored_due_to_weaker_confidence' => $choice['evaluation']['ignored_due_to_weaker_confidence'] ?? [],
                'stale_intent_conflicts' => $choice['evaluation']['stale_intent_conflicts'] ?? [],
            ];
        }

        foreach ($states as $key => $state) {
            if (isset($resolvedKeys[$key]) || $this->overlapsConsumed($state['phrase'], $consumed)) {
                continue;
            }
            $evaluation = $this->evaluateState($state, $context, $staleIntents);
            $reason = $evaluation['reason'];
            if ($reason === 'hierarchy_conflict') {
                $sawConflict = true;
            }
            if ($reason === 'ambiguous_after_hierarchy') {
                $sawAmbiguity = true;
            }
            if ($reason !== 'no_candidates') {
                $rejections[] = [
                    'phrase' => $state['phrase']['text'],
                    'reason' => $reason,
                    'type' => $evaluation['type'],
                    'candidates_before' => $evaluation['candidates_before'],
                    'candidates_after' => $evaluation['candidates_after'],
                    'not_public' => $state['not_public'],
                    'eligible_before_confidence' => $evaluation['eligible_before_confidence'] ?? [],
                    'group_confidences' => $evaluation['group_confidences'] ?? [],
                    'best_confidence' => $evaluation['best_confidence'] ?? null,
                    'confidence_eligible_groups' => $evaluation['confidence_eligible_groups'] ?? [],
                    'ignored_due_to_weaker_confidence' => $evaluation['ignored_due_to_weaker_confidence'] ?? [],
                    'stale_intent_conflicts' => $evaluation['stale_intent_conflicts'] ?? [],
                ];
            }
        }

        $residual = [];
        $consumedTokens = [];
        foreach ($phrasePlan['tokens'] as $token) {
            if (isset($consumed[$token['index']])) {
                $consumedTokens[] = $token['normalized'];
            } else {
                $residual[] = $token['normalized'];
            }
        }

        $filters = array_filter([
            'make_id' => $context['make_id'],
            'model_id' => $context['model_id'],
            'generation_id' => $context['generation_id'],
        ], static fn (?int $id): bool => $id !== null && $id > 0);

        $hierarchyStatus = $sawConflict
            ? 'conflict'
            : ($filters !== [] ? 'coherent' : ($sawAmbiguity ? 'ambiguous' : 'none'));

        return [
            'active' => $filters !== [],
            'original_query' => $query,
            'candidate_phrases' => array_values(array_map(fn (array $state): array => $this->stateForExplain($state), $states)),
            'hierarchy_narrowing' => $hierarchyNarrowing,
            'rejections' => $rejections,
            'resolved_vehicle_entities' => $resolved,
            'consumed_tokens' => array_values($consumedTokens),
            'residual_product_terms' => array_values($residual),
            'residual_query' => implode(' ', $residual),
            'product_filters' => $filters,
            'hierarchy_status' => $hierarchyStatus,
            'phrase_count' => count($phrasePlan['phrases']),
        ];
    }

    /** @return array<string,mixed> */
    public function emptyPlan(string $query): array
    {
        return [
            'active' => false,
            'original_query' => $query,
            'candidate_phrases' => [],
            'hierarchy_narrowing' => [],
            'rejections' => [],
            'resolved_vehicle_entities' => [],
            'consumed_tokens' => [],
            'residual_product_terms' => $this->normalizer->tokens($query),
            'residual_query' => $this->normalizer->normalize($query),
            'product_filters' => [],
            'hierarchy_status' => 'none',
            'phrase_count' => 0,
        ];
    }

    /** @return list<array{index:int,original:string,normalized:string,type:string}> */
    private function tokens(string $query): array
    {
        $tokens = [];
        foreach (array_slice($this->normalizer->classifiedTokens($query), 0, self::MAX_TOKENS) as $index => $token) {
            $tokens[] = [
                'index' => $index,
                'original' => (string) $token['original'],
                'normalized' => (string) $token['normalized'],
                'type' => (string) $token['type'],
            ];
        }

        return $tokens;
    }

    /** @param array<string,array{makes:list<int>,models:list<int>,generations:list<int>}> $resolutions */
    private function ids(array $resolutions, string $group): array
    {
        $ids = [];
        foreach ($resolutions as $resolution) {
            foreach ($resolution[$group] ?? [] as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    private function phraseState(array $phrase, array $resolution, array $publicSets, Collection $makes, Collection $models, Collection $generations): array
    {
        $raw = [
            'make' => array_values(array_unique(array_map('intval', $resolution['makes'] ?? []))),
            'model' => array_values(array_unique(array_map('intval', $resolution['models'] ?? []))),
            'generation' => array_values(array_unique(array_map('intval', $resolution['generations'] ?? []))),
        ];
        $channelPriority = $resolution['channel_priority'] ?? [];

        $rawMakeCandidates = [];
        foreach ($raw['make'] as $id) {
            if (($make = $makes->get($id)) instanceof VehicleMake) {
                $rawMakeCandidates[] = $this->makeCandidate($make);
            }
        }
        $rawModelCandidates = [];
        foreach ($raw['model'] as $id) {
            if (($model = $models->get($id)) instanceof VehicleModel && $model->make) {
                $rawModelCandidates[] = $this->modelCandidate($model);
            }
        }
        $rawGenerationCandidates = [];
        foreach ($raw['generation'] as $id) {
            if (($generation = $generations->get($id)) instanceof VehicleGeneration && $generation->model?->make) {
                $rawGenerationCandidates[] = $this->generationCandidate($generation);
            }
        }

        $rawCandidates = [
            'make' => $this->attachEvidence($rawMakeCandidates, $channelPriority['makes'] ?? []),
            'model' => $this->attachEvidence($rawModelCandidates, $channelPriority['models'] ?? []),
            'generation' => $this->attachEvidence($rawGenerationCandidates, $channelPriority['generations'] ?? []),
        ];
        $rawGlobalTier = $this->selectGlobalPhraseTier($channelPriority, [
            'makes' => count($rawCandidates['make']),
            'models' => count($rawCandidates['model']),
            'generations' => count($rawCandidates['generation']),
        ]);
        $rawCandidates = $this->filterCandidatesToGlobalTier($rawCandidates, $rawGlobalTier);

        $candidates = [
            'make' => array_values(array_filter(
                $rawCandidates['make'],
                static fn (array $candidate): bool => isset($publicSets['makes'][(int) $candidate['id']]),
            )),
            'model' => array_values(array_filter(
                $rawCandidates['model'],
                static fn (array $candidate): bool => isset($publicSets['models'][(int) $candidate['id']]),
            )),
            'generation' => array_values(array_filter(
                $rawCandidates['generation'],
                static fn (array $candidate): bool => isset($publicSets['generations'][(int) $candidate['id']]),
            )),
        ];
        $globalTier = $this->selectGlobalPhraseTier($channelPriority, [
            'makes' => count($candidates['make']),
            'models' => count($candidates['model']),
            'generations' => count($candidates['generation']),
        ]);
        $candidates = $this->filterCandidatesToGlobalTier($candidates, $globalTier);

        $state = [
            'phrase' => $phrase,
            'raw' => $raw,
            'raw_candidates' => $rawCandidates,
            'channel_priority' => $channelPriority,
            'raw_global_channel_priority' => $rawGlobalTier,
            'global_channel_priority' => $globalTier,
            'candidates' => $candidates,
            'not_public' => [
                'makes' => array_values(array_diff($raw['make'], array_keys($publicSets['makes']))),
                'models' => array_values(array_diff($raw['model'], array_keys($publicSets['models']))),
                'generations' => array_values(array_diff($raw['generation'], array_keys($publicSets['generations']))),
            ],
        ];
        $state['stale_intent'] = $this->staleIntent($state, $publicSets);

        return $state;
    }

    /**
     * @param  array{make:list<array<string,mixed>>,model:list<array<string,mixed>>,generation:list<array<string,mixed>>}  $candidates
     * @param  array{selected_tier:?string,eligible_groups:list<string>,ignored_groups_due_to_lower_tier:list<string>}  $globalTier
     * @return array{make:list<array<string,mixed>>,model:list<array<string,mixed>>,generation:list<array<string,mixed>>}
     */
    private function filterCandidatesToGlobalTier(array $candidates, array $globalTier): array
    {
        if (($globalTier['selected_tier'] ?? null) === null) {
            return $candidates;
        }

        $groupByType = ['make' => 'makes', 'model' => 'models', 'generation' => 'generations'];
        foreach ($groupByType as $type => $group) {
            if (! in_array($group, $globalTier['eligible_groups'] ?? [], true)) {
                $candidates[$type] = [];
            }
        }

        return $candidates;
    }

    /** @param array{makes:array<int,bool>,models:array<int,bool>,generations:array<int,bool>} $publicSets */
    private function staleIntent(array $state, array $publicSets): ?array
    {
        if (($state['raw_global_channel_priority']['selected_tier'] ?? null) === null) {
            return null;
        }

        $rawState = $state;
        $rawState['candidates'] = $state['raw_candidates'];
        $evaluation = $this->evaluateState($rawState, [
            'make_id' => null,
            'model_id' => null,
            'generation_id' => null,
        ]);
        $candidate = $evaluation['candidate'] ?? null;
        if (! is_array($candidate)) {
            return null;
        }

        $group = match ($candidate['type'] ?? null) {
            'make' => 'makes',
            'model' => 'models',
            'generation' => 'generations',
            default => null,
        };
        $id = (int) ($candidate['id'] ?? 0);
        if ($group === null || $id <= 0 || isset($publicSets[$group][$id])) {
            return null;
        }

        $tier = $candidate['evidence']['tier'] ?? null;
        if (! is_string($tier) || ! in_array($tier, self::EVIDENCE_TIERS, true)) {
            return null;
        }

        return [
            'phrase' => $state['phrase']['text'],
            'type' => $candidate['type'],
            'id' => $id,
            'title' => $candidate['title'] ?? null,
            'make_id' => $candidate['make_id'] ?? null,
            'model_id' => $candidate['model_id'] ?? null,
            'generation_id' => $candidate['generation_id'] ?? null,
            'evidence' => $candidate['evidence'],
            'reason' => 'not_public',
        ];
    }

    /** @return array<string,mixed> */
    private function evaluateState(array $state, array $context, array $staleIntents = []): array
    {
        $specificity = ['make' => 1, 'model' => 2, 'generation' => 3];
        $compatibleByType = [];
        $hadCandidates = false;
        $firstCandidateType = null;

        foreach (['generation', 'model', 'make'] as $type) {
            $candidates = $state['candidates'][$type] ?? [];
            if ($candidates === []) {
                continue;
            }

            $hadCandidates = true;
            $firstCandidateType ??= $type;
            $compatibleByType[$type] = array_values(array_filter(
                $candidates,
                fn (array $candidate): bool => $this->coherent($candidate, $context),
            ));
        }

        $compatibleByType = array_filter(
            $compatibleByType,
            static fn (array $candidates): bool => $candidates !== [],
        );

        $staleIntentConflicts = [];
        if ($compatibleByType !== [] && $staleIntents !== []) {
            foreach ($compatibleByType as $type => $candidates) {
                $kept = [];
                foreach ($candidates as $candidate) {
                    $blocking = [];
                    foreach ($staleIntents as $intent) {
                        if ($this->staleIntentBlocksCandidate($intent, $candidate)) {
                            $blocking[] = $this->staleIntentForExplain($intent);
                        }
                    }
                    if ($blocking === []) {
                        $kept[] = $candidate;

                        continue;
                    }
                    $staleIntentConflicts[] = [
                        'candidate' => [
                            'type' => $candidate['type'] ?? $type,
                            'id' => $candidate['id'] ?? null,
                            'title' => $candidate['title'] ?? null,
                            'evidence' => $candidate['evidence'] ?? [],
                        ],
                        'blocked_by' => $blocking,
                    ];
                }
                $compatibleByType[$type] = $kept;
            }
            $compatibleByType = array_filter(
                $compatibleByType,
                static fn (array $candidates): bool => $candidates !== [],
            );
        }

        if ($compatibleByType === []) {
            $hasRaw = ($state['raw']['make'] ?? []) !== [] || ($state['raw']['model'] ?? []) !== [] || ($state['raw']['generation'] ?? []) !== [];
            $blockedByStaleIntent = $staleIntentConflicts !== [];

            return [
                'candidate' => null,
                'type' => $firstCandidateType,
                'reason' => $blockedByStaleIntent ? 'not_public' : ($hadCandidates ? 'hierarchy_conflict' : ($hasRaw ? 'not_public' : 'no_candidates')),
                'candidates_before' => $firstCandidateType === null ? ($hasRaw ? array_sum(array_map('count', $state['raw'])) : 0) : count($state['candidates'][$firstCandidateType] ?? []),
                'candidates_after' => 0,
                'confidence_applied' => false,
                'eligible_before_confidence' => [],
                'group_confidences' => [],
                'best_confidence' => null,
                'confidence_eligible_groups' => [],
                'ignored_due_to_weaker_confidence' => [],
                'stale_intent_conflicts' => $staleIntentConflicts,
            ];
        }

        $eligibleBeforeConfidence = array_map(
            static fn (string $type): string => $type.'s',
            array_keys($compatibleByType),
        );

        // Confidence ranks entity groups only. Never prune sibling candidates inside
        // one group: hierarchy/ambiguity rules must retain the entire candidate set.
        $groupConfidences = [];
        $confidenceApplied = true;
        foreach ($compatibleByType as $type => $candidates) {
            $confidences = [];
            foreach ($candidates as $candidate) {
                $confidence = $candidate['evidence']['confidence'] ?? null;
                if (! is_numeric($confidence)) {
                    $confidenceApplied = false;
                    break 2;
                }
                $confidences[] = (float) $confidence;
            }
            $groupConfidences[$type.'s'] = min($confidences);
        }

        $bestConfidence = null;
        $confidenceEligibleByType = $compatibleByType;
        $ignoredByConfidence = [];
        if ($confidenceApplied) {
            $bestConfidence = min($groupConfidences);
            $confidenceEligibleByType = [];

            foreach ($compatibleByType as $type => $candidates) {
                $group = $type.'s';
                $groupConfidence = $groupConfidences[$group];
                if (abs($groupConfidence - $bestConfidence) <= self::CONFIDENCE_EPSILON) {
                    $confidenceEligibleByType[$type] = $candidates;

                    continue;
                }

                $ignoredByConfidence[] = [
                    'group' => $group,
                    'best_confidence' => $groupConfidence,
                    'candidate_count' => count($candidates),
                ];
            }
        }

        $selectedType = null;
        $bestSpecificity = -1;
        foreach ($confidenceEligibleByType as $type => $_candidates) {
            $typeSpecificity = $specificity[$type] ?? 0;
            if ($typeSpecificity > $bestSpecificity) {
                $bestSpecificity = $typeSpecificity;
                $selectedType = $type;
            }
        }

        $selectedCandidates = $selectedType === null ? [] : $confidenceEligibleByType[$selectedType];
        $beforeForType = $selectedType === null ? 0 : count($state['candidates'][$selectedType] ?? []);
        $afterForType = count($selectedCandidates);
        $confidenceEligibleGroups = array_map(
            static fn (string $type): string => $type.'s',
            array_keys($confidenceEligibleByType),
        );

        if (count($selectedCandidates) === 1) {
            return [
                'candidate' => $selectedCandidates[0],
                'type' => $selectedType,
                'reason' => 'resolved_after_hierarchy',
                'candidates_before' => $beforeForType,
                'candidates_after' => $afterForType,
                'confidence_applied' => $confidenceApplied,
                'eligible_before_confidence' => $eligibleBeforeConfidence,
                'group_confidences' => $groupConfidences,
                'best_confidence' => $bestConfidence,
                'confidence_eligible_groups' => $confidenceEligibleGroups,
                'ignored_due_to_weaker_confidence' => $ignoredByConfidence,
                'stale_intent_conflicts' => $staleIntentConflicts,
            ];
        }

        return [
            'candidate' => null,
            'type' => $selectedType,
            'reason' => 'ambiguous_after_hierarchy',
            'candidates_before' => $beforeForType,
            'candidates_after' => $afterForType,
            'confidence_applied' => $confidenceApplied,
            'eligible_before_confidence' => $eligibleBeforeConfidence,
            'group_confidences' => $groupConfidences,
            'best_confidence' => $bestConfidence,
            'confidence_eligible_groups' => $confidenceEligibleGroups,
            'ignored_due_to_weaker_confidence' => $ignoredByConfidence,
            'stale_intent_conflicts' => $staleIntentConflicts,
        ];
    }

    private function staleIntentBlocksCandidate(array $intent, array $candidate): bool
    {
        $guardContext = [
            'make_id' => $intent['make_id'] ?? null,
            'model_id' => $intent['model_id'] ?? null,
            'generation_id' => $intent['generation_id'] ?? null,
        ];
        if ($this->coherent($candidate, $guardContext)) {
            return false;
        }

        $intentTier = $intent['evidence']['tier'] ?? null;
        $candidateTier = $candidate['evidence']['tier'] ?? null;
        $intentRank = is_string($intentTier) ? array_search($intentTier, self::EVIDENCE_TIERS, true) : false;
        $candidateRank = is_string($candidateTier) ? array_search($candidateTier, self::EVIDENCE_TIERS, true) : false;
        if (! is_int($intentRank) || ! is_int($candidateRank)) {
            return false;
        }
        if ($intentRank < $candidateRank) {
            return true;
        }
        if ($intentRank > $candidateRank) {
            return false;
        }

        $intentConfidence = $intent['evidence']['confidence'] ?? null;
        $candidateConfidence = $candidate['evidence']['confidence'] ?? null;
        if (is_numeric($intentConfidence) && is_numeric($candidateConfidence)) {
            return (float) $intentConfidence <= (float) $candidateConfidence + self::CONFIDENCE_EPSILON;
        }

        return true;
    }

    private function staleIntentForExplain(array $intent): array
    {
        return [
            'phrase' => $intent['phrase'] ?? null,
            'reason' => 'not_public',
            'type' => $intent['type'] ?? null,
            'id' => $intent['id'] ?? null,
            'title' => $intent['title'] ?? null,
            'make_id' => $intent['make_id'] ?? null,
            'model_id' => $intent['model_id'] ?? null,
            'generation_id' => $intent['generation_id'] ?? null,
            'evidence' => $intent['evidence'] ?? [],
        ];
    }

    /** @param list<array<string,mixed>> $candidates */
    private function attachEvidence(array $candidates, array $priority): array
    {
        $selectedTier = is_string($priority['selected_tier'] ?? null) ? $priority['selected_tier'] : null;
        $evidenceById = [];
        if ($selectedTier !== null) {
            foreach ($priority['candidates_by_tier'][$selectedTier] ?? [] as $evidence) {
                $id = (int) ($evidence['id'] ?? 0);
                if ($id > 0) {
                    $evidenceById[$id] = $evidence;
                }
            }
        }

        return array_map(static function (array $candidate) use ($selectedTier, $evidenceById): array {
            $evidence = $evidenceById[(int) ($candidate['id'] ?? 0)] ?? null;
            $candidate['evidence'] = [
                'tier' => $selectedTier,
                'source' => is_array($evidence) ? ($evidence['source'] ?? null) : null,
                'channel' => is_array($evidence) ? ($evidence['channel'] ?? null) : null,
                'confidence' => is_array($evidence) && is_numeric($evidence['confidence'] ?? null)
                    ? (float) $evidence['confidence']
                    : null,
            ];

            return $candidate;
        }, $candidates);
    }

    private function overlapsConsumed(array $phrase, array $consumed): bool
    {
        for ($index = $phrase['start']; $index < $phrase['start'] + $phrase['length']; $index++) {
            if (isset($consumed[$index])) {
                return true;
            }
        }

        return false;
    }

    private function coherent(array $candidate, array $context): bool
    {
        if ($context['make_id'] !== null && $candidate['make_id'] !== null && $candidate['make_id'] !== $context['make_id']) {
            return false;
        }
        if ($context['model_id'] !== null && $candidate['model_id'] !== null && $candidate['model_id'] !== $context['model_id']) {
            return false;
        }
        if ($context['generation_id'] !== null && $candidate['generation_id'] !== null && $candidate['generation_id'] !== $context['generation_id']) {
            return false;
        }

        return true;
    }

    private function makeCandidate(VehicleMake $make): array
    {
        return [
            'type' => 'make',
            'id' => (int) $make->getKey(),
            'title' => (string) $make->title,
            'make_id' => (int) $make->getKey(),
            'model_id' => null,
            'generation_id' => null,
        ];
    }

    private function modelCandidate(VehicleModel $model): array
    {
        return [
            'type' => 'model',
            'id' => (int) $model->getKey(),
            'title' => (string) $model->title,
            'make_id' => (int) $model->vehicle_make_id,
            'model_id' => (int) $model->getKey(),
            'generation_id' => null,
        ];
    }

    private function generationCandidate(VehicleGeneration $generation): array
    {
        return [
            'type' => 'generation',
            'id' => (int) $generation->getKey(),
            'title' => (string) $generation->title,
            'make_id' => (int) $generation->model->vehicle_make_id,
            'model_id' => (int) $generation->vehicle_model_id,
            'generation_id' => (int) $generation->getKey(),
        ];
    }

    private function stateForExplain(array $state): array
    {
        return [
            'key' => $state['phrase']['key'],
            'phrase' => $state['phrase']['text'],
            'start' => $state['phrase']['start'],
            'length' => $state['phrase']['length'],
            'make_candidates' => $state['candidates']['make'],
            'model_candidates' => $state['candidates']['model'],
            'generation_candidates' => $state['candidates']['generation'],
            'raw_candidates' => $state['raw_candidates'] ?? ['make' => [], 'model' => [], 'generation' => []],
            'stale_intent' => $state['stale_intent'] ?? null,
            'channel_priority' => $state['channel_priority'] ?? [],
            'raw_global_channel_priority' => $state['raw_global_channel_priority'] ?? [
                'selected_tier' => null,
                'eligible_groups' => [],
                'ignored_groups_due_to_lower_tier' => [],
            ],
            'global_channel_priority' => $state['global_channel_priority'] ?? [
                'selected_tier' => null,
                'eligible_groups' => [],
                'ignored_groups_due_to_lower_tier' => [],
            ],
            'not_public' => $state['not_public'],
        ];
    }
}
