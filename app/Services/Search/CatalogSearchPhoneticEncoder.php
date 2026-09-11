<?php

namespace App\Services\Search;

final class CatalogSearchPhoneticEncoder
{
    private const PREFIX_MIN_LENGTH = 3;

    private const PREFIX_MAX_LENGTH = 5;

    public function __construct(private readonly CatalogSearchNormalizer $normalizer) {}

    public function encodeToken(string $token): ?string
    {
        $latin = $this->normalizer->strictTransliteration($token);
        if ($latin === '' || str_contains($latin, ' ') || strlen($latin) < 3 || preg_match('/\d/', $latin)) {
            return null;
        }

        $folded = $this->fold($latin);
        $key = metaphone($folded);

        return $key === '' ? null : $key;
    }

    /** @return list<string> */
    public function encode(string $value): array
    {
        $keys = [];
        foreach ($this->normalizer->significantLatinTokens($value) as $token) {
            $key = $this->encodeToken($token);
            if ($key !== null) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    public function comparisonForm(string $token): string
    {
        return $this->fold($this->normalizer->strictTransliteration($token));
    }

    public function prefixComparisonForm(string $token): string
    {
        $latin = $this->fold($this->normalizer->strictTransliteration($token));
        if ($latin === '' || str_contains($latin, ' ') || preg_match('/\d/', $latin)) {
            return '';
        }

        // Generic Latin spelling equivalences for prefix matching. This deliberately
        // lives outside the full-word phonetic encoder so real-catalog collision
        // characteristics of folded metaphone stay unchanged.
        $latin = preg_replace('/c(?=[ei])/i', 's', $latin) ?? $latin;
        $latin = str_replace(['c', 'q'], ['k', 'k'], $latin);

        return $latin;
    }

    public function prefixSignature(string $token): ?string
    {
        $latin = $this->normalizer->strictTransliteration($token);
        $length = strlen($latin);
        if ($length < self::PREFIX_MIN_LENGTH || $length > self::PREFIX_MAX_LENGTH || str_contains($latin, ' ') || preg_match('/\d/', $latin)) {
            return null;
        }
        $form = $this->prefixComparisonForm($latin);

        return $form === '' ? null : 'px'.$length.$form;
    }

    /** @return list<string> */
    public function prefixTerms(string $value): array
    {
        $terms = [];
        foreach ($this->normalizer->significantLatinTokens($value) as $token) {
            $max = min(self::PREFIX_MAX_LENGTH, strlen($token));
            for ($length = self::PREFIX_MIN_LENGTH; $length <= $max; $length++) {
                $signature = $this->prefixSignature(substr($token, 0, $length));
                if ($signature !== null) {
                    $terms[$signature] = true;
                }
            }
        }

        return array_keys($terms);
    }

    public function rawMetaphone(string $token): ?string
    {
        $latin = $this->normalizer->strictTransliteration($token);
        if ($latin === '' || strlen($latin) < 3 || preg_match('/\d/', $latin)) {
            return null;
        }

        $key = metaphone($latin);

        return $key === '' ? null : $key;
    }

    public function soundex(string $token): ?string
    {
        $latin = $this->normalizer->strictTransliteration($token);
        if ($latin === '' || strlen($latin) < 3 || preg_match('/\d/', $latin)) {
            return null;
        }

        return soundex($latin);
    }

    private function fold(string $value): string
    {
        // Generic proper-name equivalences only. No vehicle/entity dictionary lives here.
        return str_replace(
            ['ph', 'kh', 'sh', 'ts', 'ck', 'w', 'x', 'y'],
            ['f', 'h', 's', 'z', 'k', 'v', 'ks', 'i'],
            $value,
        );
    }
}
