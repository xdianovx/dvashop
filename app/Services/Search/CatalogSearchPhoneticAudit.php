<?php

namespace App\Services\Search;

final class CatalogSearchPhoneticAudit
{
    public function __construct(
        private readonly CatalogSearchNormalizer $normalizer,
        private readonly CatalogSearchPhoneticEncoder $phonetic,
        private readonly CatalogSearchConfidenceGate $gate,
    ) {}

    /**
     * @param  list<array{type:string,id:int,title:string}>  $entities
     * @return array<string, array<string,mixed>>
     */
    public function compare(array $entities): array
    {
        return [
            'metaphone' => $this->audit($entities, fn (string $token): ?string => $this->phonetic->rawMetaphone($token)),
            'soundex' => $this->audit($entities, fn (string $token): ?string => $this->phonetic->soundex($token)),
            'folded_metaphone' => $this->audit($entities, fn (string $token): ?string => $this->phonetic->encodeToken($token)),
        ];
    }

    /**
     * Canonical-only prefix safety audit. No Russian dictionary is involved: each
     * eligible canonical token contributes 3/4/5-character prefixes which are fed
     * through the same prefix confidence gate used by runtime search.
     *
     * @param  list<array{type:string,id:int,title:string}>  $entities
     * @return array{tests:int,unique_correct:int,ambiguous:int,false_positives:int}
     */
    public function prefix(array $entities): array
    {
        $tests = 0;
        $uniqueCorrect = 0;
        $ambiguous = 0;
        $falsePositives = 0;
        $prefixBuckets = [];

        // Precompute the same exact generated prefix signatures that are indexed at
        // runtime. This measures the actual collision surface of the safe prefix
        // channel instead of approximating it with full-word metaphone keys.
        foreach ($entities as $candidate) {
            foreach ($this->phonetic->prefixTerms($candidate['title']) as $signature) {
                $prefixBuckets[$signature][(int) $candidate['id']] = $candidate;
            }
        }

        foreach ($entities as $entity) {
            foreach ($this->normalizer->significantLatinTokens($entity['title']) as $token) {
                foreach ([3, 4, 5] as $length) {
                    if (strlen($token) <= $length) {
                        continue;
                    }
                    $prefix = substr($token, 0, $length);
                    $signature = $this->phonetic->prefixSignature($prefix);
                    if ($signature === null) {
                        continue;
                    }
                    $tests++;
                    $prefixCandidates = [];
                    foreach ($prefixBuckets[$signature] ?? [] as $candidate) {
                        $prefixEvaluation = $this->gate->evaluatePrefix([$prefix], [$candidate['title']], $candidate['title']);
                        if ($prefixEvaluation['accepted']) {
                            $prefixCandidates[] = ['id' => (int) $candidate['id'], 'score' => (float) $prefixEvaluation['score']];
                        }
                    }
                    $selection = $this->gate->selectPrefixUnambiguous($prefixCandidates);
                    $accepted = $selection['accepted_ids'][0] ?? null;
                    if ($accepted === (int) $entity['id']) {
                        $uniqueCorrect++;
                    } elseif ($accepted === null) {
                        $ambiguous++;
                    } else {
                        $falsePositives++;
                    }
                }
            }
        }

        return [
            'tests' => $tests,
            'unique_correct' => $uniqueCorrect,
            'ambiguous' => $ambiguous,
            'false_positives' => $falsePositives,
        ];
    }

    /**
     * @param  list<array{type:string,id:int,title:string}>  $entities
     * @return array<string,mixed>
     */
    private function audit(array $entities, callable $encoder): array
    {
        $buckets = [];
        $entityCount = 0;
        foreach ($entities as $entity) {
            $tokens = $this->normalizer->significantLatinTokens($entity['title']);
            $keys = [];
            foreach ($tokens as $token) {
                $key = $encoder($token);
                if ($key !== null && $key !== '') {
                    $keys[] = $key;
                }
            }
            if ($keys === []) {
                continue;
            }
            $entityCount++;
            $buckets[implode('-', $keys)][] = $entity;
        }

        $collisions = array_filter($buckets, static fn (array $bucket): bool => count($bucket) > 1);
        uasort($collisions, static fn (array $a, array $b): int => count($b) <=> count($a));

        return [
            'entity_count' => $entityCount,
            'unique_keys' => count($buckets),
            'collision_keys' => count($collisions),
            'ambiguous_entities' => array_sum(array_map('count', $collisions)),
            'max_bucket' => $buckets === [] ? 0 : max(array_map('count', $buckets)),
            'largest_buckets' => array_slice(array_map(
                static fn (array $bucket, string $key): array => [
                    'key' => $key,
                    'count' => count($bucket),
                    'entities' => array_map(static fn (array $entity): string => $entity['type'].'#'.$entity['id'].' '.$entity['title'], $bucket),
                ],
                $collisions,
                array_keys($collisions),
            ), 0, 10),
        ];
    }
}
