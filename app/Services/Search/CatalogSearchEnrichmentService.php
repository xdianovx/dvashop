<?php

namespace App\Services\Search;

final class CatalogSearchEnrichmentService
{
    public function __construct(
        private readonly CatalogSearchNormalizer $normalizer,
        private readonly CatalogSearchPhoneticEncoder $phonetic,
    ) {}

    /**
     * @param  list<string>  $canonical
     * @param  list<string>  $aliases
     * @param  list<string>|null  $phoneticCanonical
     * @param  list<string>|null  $phoneticAliases
     * @return array{layout_terms:list<string>,strict_transliteration_terms:list<string>,phonetic_terms:list<string>,phonetic_prefix_terms:list<string>,structured_terms:list<string>}
     */
    public function document(array $canonical, array $aliases = [], ?array $phoneticCanonical = null, ?array $phoneticAliases = null): array
    {
        $all = SearchText::unique(array_merge($canonical, $aliases));
        $layout = [];
        $strict = [];
        foreach ($all as $term) {
            $alternative = $this->normalizer->keyboardLayoutAlternative($term);
            if ($alternative !== null) {
                $layout[] = $alternative;
            }
            $transliterated = $this->normalizer->strictTransliteration($term);
            if ($transliterated !== '') {
                $strict[] = $transliterated;
            }
        }
        $structured = $this->structuredTerms($all);

        $phonetic = [];
        $phoneticPrefixes = [];
        foreach (array_merge($phoneticCanonical ?? $canonical, $phoneticAliases ?? $aliases) as $term) {
            $phonetic = array_merge($phonetic, $this->phonetic->encode($term));
            $phoneticPrefixes = array_merge($phoneticPrefixes, $this->phonetic->prefixTerms($term));
        }

        return [
            'layout_terms' => SearchText::unique($layout),
            'strict_transliteration_terms' => SearchText::unique($strict),
            'phonetic_terms' => array_values(array_unique($phonetic)),
            'phonetic_prefix_terms' => array_values(array_unique($phoneticPrefixes)),
            'structured_terms' => array_values(array_unique($structured)),
        ];
    }

    /** @param list<string> $terms @return list<string> */
    public function structuredTerms(array $terms): array
    {
        $structured = [];
        foreach ($terms as $term) {
            foreach ($this->normalizer->structuredTokens((string) $term) as $token) {
                $structured[] = (string) $token;
            }
        }

        return array_values(array_unique($structured, SORT_STRING));
    }

    /**
     * @return array{
     *   original:string,normalized:string,tokens:list<string>,token_details:list<array<string,mixed>>,
     *   layout_alternative:?string,layout:?array<string,mixed>,layout_searched:bool,
     *   strict_transliteration:string,word_tokens:list<string>,structured_tokens:list<string>,
     *   phonetic_tokens:list<string>,phonetic_prefix_tokens:list<string>,phonetic_prefix_query_tokens:list<string>,short_code:bool,channels:list<string>
     * }
     */
    public function query(string $query): array
    {
        $original = mb_substr(trim($query), 0, 200);
        $primary = $this->representation($original);
        $layoutAlternative = $this->normalizer->keyboardLayoutAlternative($original);
        $layout = null;

        if ($layoutAlternative !== null && $this->layoutAlternativeIsMeaningful($original, $layoutAlternative, $primary)) {
            $layout = $this->representation($layoutAlternative);
        }

        $channels = $primary['channels'];
        if ($layout !== null) {
            $channels[] = 'layout';
        }

        return [
            'original' => $original,
            ...$primary,
            'layout_alternative' => $layoutAlternative,
            'layout' => $layout,
            'layout_searched' => $layout !== null,
            'channels' => array_values(array_unique($channels)),
        ];
    }

    /**
     * @return array{
     *   normalized:string,tokens:list<string>,token_details:list<array<string,mixed>>,strict_transliteration:string,
     *   word_tokens:list<string>,structured_tokens:list<string>,phonetic_tokens:list<string>,phonetic_prefix_tokens:list<string>,
     *   phonetic_prefix_query_tokens:list<string>,short_code:bool,channels:list<string>
     * }
     */
    private function representation(string $value): array
    {
        $normalized = $this->normalizer->normalize($value);
        $strict = $this->normalizer->strictTransliteration($normalized);
        $classified = $this->normalizer->classifiedTokens($value);
        $wordTokens = [];
        $structuredTokens = [];
        $phoneticTokens = [];
        $phoneticPrefixTokens = [];
        $phoneticPrefixQueryTokens = [];
        $details = [];
        $hasCyrillicWord = false;

        foreach ($classified as $token) {
            $channels = ['original'];
            $phonetic = null;
            if ($token['type'] === CatalogSearchNormalizer::TOKEN_WORD) {
                $channels[] = 'strict_transliteration';
                $isCyrillic = preg_match('/[а-яё]/iu', $token['original']) === 1;
                if ($isCyrillic) {
                    $hasCyrillicWord = true;
                }
                if (strlen($token['transliteration']) >= 3) {
                    $wordTokens[] = $token['transliteration'];
                    $phonetic = $this->phonetic->encodeToken($token['transliteration']);
                    if ($phonetic !== null) {
                        $phoneticTokens[] = $phonetic;
                        $prefixSignature = $isCyrillic ? $this->phonetic->prefixSignature($token['transliteration']) : null;
                        $phoneticPrefixQueryTokens[] = $prefixSignature ?? $phonetic;
                        if ($prefixSignature !== null) {
                            $phoneticPrefixTokens[] = $prefixSignature;
                        }
                        if ($isCyrillic) {
                            $channels[] = 'phonetic';
                            if ($prefixSignature !== null) {
                                $channels[] = 'phonetic_prefix';
                            }
                        }
                    }
                }
            } elseif ($token['structured'] !== null) {
                $structuredTokens[] = (string) $token['structured'];
                $channels[] = 'structured_exact';
            }

            $details[] = $token + [
                'phonetic' => $phonetic,
                'channels' => array_values(array_unique($channels)),
            ];
        }

        $wordTokens = array_values(array_unique($wordTokens));
        $structuredTokens = array_values(array_unique($structuredTokens, SORT_STRING));
        $phoneticTokens = array_values(array_unique($phoneticTokens));
        $phoneticPrefixTokens = array_values(array_unique($phoneticPrefixTokens));
        $phoneticPrefixQueryTokens = array_values(array_unique($phoneticPrefixQueryTokens));
        $channels = ['original'];
        if ($strict !== '' && $strict !== $normalized) {
            $channels[] = 'strict_transliteration';
        }
        if ($hasCyrillicWord && $phoneticTokens !== []) {
            $channels[] = 'phonetic';
        }
        if ($hasCyrillicWord && $phoneticPrefixTokens !== []) {
            $channels[] = 'phonetic_prefix';
        }

        return [
            'normalized' => $normalized,
            'tokens' => $this->normalizer->tokens($normalized),
            'token_details' => $details,
            'strict_transliteration' => $strict,
            'word_tokens' => $wordTokens,
            'structured_tokens' => $structuredTokens,
            'phonetic_tokens' => $phoneticTokens,
            'phonetic_prefix_tokens' => $phoneticPrefixTokens,
            'phonetic_prefix_query_tokens' => $phoneticPrefixQueryTokens,
            'short_code' => $this->normalizer->isShortCode($value),
            'channels' => array_values(array_unique($channels)),
        ];
    }

    /** @param array<string,mixed> $primary */
    private function layoutAlternativeIsMeaningful(string $original, string $alternative, array $primary): bool
    {
        // Mixed structured queries and pure short codes keep their
        // exact token semantics; keyboard conversion is deliberately not guessed.
        if (($primary['structured_tokens'] ?? []) !== [] || ($primary['word_tokens'] ?? []) === []) {
            return false;
        }

        $alternativeRepresentation = $this->representation($alternative);
        if (($alternativeRepresentation['structured_tokens'] ?? []) !== [] || ($alternativeRepresentation['word_tokens'] ?? []) === []) {
            return false;
        }

        $originalCompact = preg_replace('/[^\p{L}]+/u', '', $original) ?? '';
        if (mb_strlen($originalCompact) <= 3 && preg_match('/^[a-z]+$/i', $originalCompact) === 1 && $this->vowelRatio($originalCompact) === 0.0) {
            return false;
        }

        $sourceScore = $this->vowelRatio($original);
        $alternativeScore = $this->vowelRatio($alternative);

        return $alternativeScore >= 0.15 && $alternativeScore >= $sourceScore + 0.10;
    }

    private function vowelRatio(string $value): float
    {
        $letters = preg_replace('/[^\p{L}]+/u', '', mb_strtolower($value)) ?? '';
        $length = mb_strlen($letters);
        if ($length === 0) {
            return 0.0;
        }

        if (preg_match('/[а-яё]/u', $letters) === 1) {
            preg_match_all('/[аеёиоуыэюя]/u', $letters, $matches);
        } else {
            preg_match_all('/[aeiouy]/', $letters, $matches);
        }

        return count($matches[0] ?? []) / $length;
    }
}
