<?php

namespace App\Services\Search;

final class CatalogSearchConfidenceGate
{
    private const MAX_COLLISION_BUCKET = 6;

    public function __construct(
        private readonly CatalogSearchNormalizer $normalizer,
        private readonly CatalogSearchPhoneticEncoder $phonetic,
    ) {}

    /**
     * Full-word phonetic confidence. Prefixes deliberately use evaluatePrefix().
     *
     * @param  list<string>  $queryTokens
     * @param  list<string>  $candidateTerms
     * @return array{accepted:bool,score:float,reason:string}
     */
    public function evaluate(array $queryTokens, array $candidateTerms, ?string $primaryTerm = null): array
    {
        if ($queryTokens === []) {
            return ['accepted' => false, 'score' => 1.0, 'reason' => 'no_word_tokens'];
        }

        $candidateTokens = $this->candidateTokens($candidateTerms);
        $scores = [];
        $queryCount = count($queryTokens);
        foreach ($queryTokens as $queryToken) {
            $queryKey = $this->phonetic->encodeToken($queryToken);
            if ($queryKey === null) {
                return ['accepted' => false, 'score' => 1.0, 'reason' => 'short_word'];
            }
            $queryForm = $this->phonetic->comparisonForm($queryToken);
            $best = null;
            $bestCandidateLength = 0;
            foreach ($candidateTokens as [$candidateKey, $candidateForm, $candidateToken]) {
                if ($candidateKey !== $queryKey) {
                    continue;
                }
                // A 3-5 character proper-name token may be either a complete short
                // name or an unfinished prefix. Do not let it claim
                // a longer full word through metaphone; the dedicated prefix channel
                // handles that case with stricter exact prefix signatures.
                if ($queryCount === 1 && strlen($queryToken) <= 5 && strlen($candidateToken) !== strlen($queryToken)) {
                    continue;
                }
                if ($queryCount === 1 && strlen($queryToken) <= 5
                    && $this->phonetic->prefixComparisonForm($queryToken) !== $this->phonetic->prefixComparisonForm($candidateToken)) {
                    continue;
                }
                $length = max(strlen($queryForm), strlen($candidateForm), 1);
                $ratio = levenshtein($queryForm, $candidateForm) / $length;
                if ($best === null || $ratio < $best) {
                    $best = $ratio;
                    $bestCandidateLength = strlen($candidateForm);
                }
            }
            if ($best === null) {
                return ['accepted' => false, 'score' => 1.0, 'reason' => 'phonetic_mismatch'];
            }
            $maxRatio = max(strlen($queryForm), $bestCandidateLength) >= 7 ? 0.45 : 0.34;
            if ($best > $maxRatio) {
                return ['accepted' => false, 'score' => $best, 'reason' => 'distance'];
            }
            $scores[] = $best;
        }

        return $this->withPrimaryPenalty($queryTokens, $primaryTerm, max($scores ?: [1.0]), 'accepted');
    }

    /**
     * Prefix-aware confidence is intentionally stricter than typo search. It compares
     * a transliterated query token with an exact generic prefix-equivalence form.
     * Ambiguity is handled separately and never resolved by fuzzy score differences.
     *
     * @param  list<string>  $queryTokens
     * @param  list<string>  $candidateTerms
     * @return array{accepted:bool,score:float,reason:string}
     */
    public function evaluatePrefix(array $queryTokens, array $candidateTerms, ?string $primaryTerm = null): array
    {
        if ($queryTokens === []) {
            return ['accepted' => false, 'score' => 1.0, 'reason' => 'no_word_tokens'];
        }

        $candidateTokens = $this->candidateTokens($candidateTerms);
        $scores = [];
        $usedPrefix = false;
        foreach ($queryTokens as $queryToken) {
            $queryLength = strlen($queryToken);
            if ($queryLength < 3) {
                return ['accepted' => false, 'score' => 1.0, 'reason' => 'short_prefix'];
            }

            if ($queryLength > 5) {
                $queryKey = $this->phonetic->encodeToken($queryToken);
                $queryForm = $this->phonetic->comparisonForm($queryToken);
                if ($queryKey === null) {
                    return ['accepted' => false, 'score' => 1.0, 'reason' => 'phonetic_mismatch'];
                }
                $best = null;
                foreach ($candidateTokens as [$candidateKey, $candidateForm]) {
                    if ($candidateKey !== $queryKey) {
                        continue;
                    }
                    $length = max(strlen($queryForm), strlen($candidateForm), 1);
                    $ratio = levenshtein($queryForm, $candidateForm) / $length;
                    if ($best === null || $ratio < $best) {
                        $best = $ratio;
                    }
                }
                if ($best === null || $best > 0.45) {
                    return ['accepted' => false, 'score' => $best ?? 1.0, 'reason' => $best === null ? 'phonetic_mismatch' : 'distance'];
                }
                $scores[] = $best;

                continue;
            }

            $queryForm = $this->phonetic->prefixComparisonForm($queryToken);
            if ($queryForm === '') {
                return ['accepted' => false, 'score' => 1.0, 'reason' => 'prefix_mismatch'];
            }

            $best = null;
            $bestUsesPrefix = false;
            foreach ($candidateTokens as [, , $candidateToken]) {
                if (strlen($candidateToken) < $queryLength) {
                    continue;
                }
                $prefixToken = substr($candidateToken, 0, $queryLength);
                $prefixForm = $this->phonetic->prefixComparisonForm($prefixToken);
                if ($prefixForm === '' || $prefixForm !== $queryForm) {
                    continue;
                }

                $isPrefix = strlen($candidateToken) > $queryLength;
                $weighted = $isPrefix ? 0.04 : 0.0;
                if ($best === null || $weighted < $best) {
                    $best = $weighted;
                    $bestUsesPrefix = $isPrefix;
                }
            }
            if ($best === null) {
                return ['accepted' => false, 'score' => 1.0, 'reason' => 'prefix_mismatch'];
            }
            $usedPrefix = $usedPrefix || $bestUsesPrefix;
            $scores[] = $best;
        }

        if (! $usedPrefix) {
            return ['accepted' => false, 'score' => max($scores ?: [1.0]), 'reason' => 'not_prefix'];
        }

        return ['accepted' => true, 'score' => max($scores ?: [1.0]), 'reason' => 'prefix_accepted'];
    }

    /**
     * Prefix confidence follows EMPTY > WRONG: more than one viable entity is a
     * collision even if one candidate has a slightly prettier score.
     *
     * @param  array<int, array{id:int,score:float}>  $candidates
     * @return array{accepted_ids:list<int>,reason:string}
     */
    public function selectPrefixUnambiguous(array $candidates): array
    {
        $ids = [];
        foreach ($candidates as $candidate) {
            $id = (int) $candidate['id'];
            $ids[$id] = isset($ids[$id]) ? min($ids[$id], (float) $candidate['score']) : (float) $candidate['score'];
        }

        if ($ids === []) {
            return ['accepted_ids' => [], 'reason' => 'no_match'];
        }
        if (count($ids) !== 1) {
            return ['accepted_ids' => [], 'reason' => 'ambiguous_prefix'];
        }

        return ['accepted_ids' => [(int) array_key_first($ids)], 'reason' => 'accepted'];
    }

    /**
     * @param  array<int, array{id:int,score:float}>  $candidates
     * @return array{accepted_ids:list<int>,reason:string}
     */
    public function selectUnambiguous(array $candidates): array
    {
        if ($candidates === []) {
            return ['accepted_ids' => [], 'reason' => 'no_match'];
        }
        if (count($candidates) > self::MAX_COLLISION_BUCKET) {
            return ['accepted_ids' => [], 'reason' => 'collision'];
        }
        usort($candidates, static fn (array $a, array $b): int => $a['score'] <=> $b['score'] ?: $a['id'] <=> $b['id']);
        $best = $candidates[0]['score'];
        $bestIds = array_values(array_map(
            static fn (array $candidate): int => $candidate['id'],
            array_filter($candidates, static fn (array $candidate): bool => abs($candidate['score'] - $best) < 0.08),
        ));

        if (count($bestIds) !== 1) {
            return ['accepted_ids' => [], 'reason' => 'ambiguous'];
        }
        if (isset($candidates[1]) && ($candidates[1]['score'] - $best) < 0.12) {
            return ['accepted_ids' => [], 'reason' => 'collision'];
        }

        return ['accepted_ids' => $bestIds, 'reason' => 'accepted'];
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private function candidateTokens(array $candidateTerms): array
    {
        $tokens = [];
        foreach ($candidateTerms as $term) {
            foreach ($this->normalizer->significantLatinTokens((string) $term) as $token) {
                $key = $this->phonetic->encodeToken($token);
                if ($key !== null) {
                    $tokens[] = [$key, $this->phonetic->comparisonForm($token), $token];
                }
            }
        }

        return $tokens;
    }

    /** @return array{accepted:bool,score:float,reason:string} */
    private function withPrimaryPenalty(array $queryTokens, ?string $primaryTerm, float $score, string $reason): array
    {
        if ($primaryTerm !== null) {
            $primaryTokens = $this->normalizer->significantLatinTokens($primaryTerm);
            $matchedPrimaryQueryTokens = 0;
            foreach ($queryTokens as $queryToken) {
                $queryKey = $this->phonetic->encodeToken($queryToken);
                foreach ($primaryTokens as $primaryToken) {
                    if ($queryKey !== null && $queryKey === $this->phonetic->encodeToken($primaryToken)) {
                        $matchedPrimaryQueryTokens++;
                        break;
                    }
                }
            }
            $extraTokens = max(0, count($primaryTokens) - $matchedPrimaryQueryTokens);
            if ($extraTokens > 0) {
                $score += min(0.32, 0.16 * $extraTokens);
            }
        }

        return ['accepted' => true, 'score' => $score, 'reason' => $reason];
    }
}
