<?php

namespace App\Services\Search;

use Normalizer;
use Transliterator;

final class CatalogSearchNormalizer
{
    public const TOKEN_WORD = 'word';

    public const TOKEN_SHORT_CODE = 'short_code';

    public const TOKEN_NUMERIC = 'numeric';

    private const RU_TO_LATIN = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z',
        'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
        'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh',
        'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    private const EN_KEYBOARD = "qwertyuiop[]asdfghjkl;'zxcvbnm,.`";

    private const RU_KEYBOARD = 'йцукенгшщзхъфывапролджэячсмитьбюё';

    public function normalize(string $value): string
    {
        $value = $this->unicodeBase($value);
        $value = preg_replace('~\s*(?:[-/_+]|\\\\)+\s*~u', ' ', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /** @return list<string> */
    public function tokens(string $value): array
    {
        $value = $this->normalize($value);

        return $value === '' ? [] : preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Tokenization for query semantics. Internal dashes are retained long enough to
     * classify values such as CX-5 as a single short-code token.
     *
     * @return list<array{original:string,normalized:string,transliteration:string,type:string,structured:?string}>
     */
    public function classifiedTokens(string $value): array
    {
        $source = $this->unicodeBasePreserveCase($value);
        if ($source === '') {
            return [];
        }

        preg_match_all('/[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*/u', $source, $matches);
        $result = [];
        foreach ($matches[0] ?? [] as $raw) {
            $parts = [(string) $raw];
            if (str_contains((string) $raw, '-')) {
                $compact = preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $raw) ?? '';
                $latinCompact = preg_replace('/[^a-z0-9]+/i', '', $this->strictTransliteration($compact)) ?? '';
                $isHyphenatedShortCode = preg_match('/[a-z]/i', $latinCompact) === 1 && preg_match('/\d/', $latinCompact) === 1;
                if (! $isHyphenatedShortCode) {
                    $parts = preg_split('/-+/u', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                }
            }

            foreach ($parts as $part) {
                $token = $this->classifyToken((string) $part);
                if ($token !== null) {
                    $result[] = $token;
                }
            }
        }

        return $result;
    }

    /** @return array{original:string,normalized:string,transliteration:string,type:string,structured:?string}|null */
    private function classifyToken(string $raw): ?array
    {
        $normalized = $this->normalize($raw);
        if ($normalized === '') {
            return null;
        }
        $compact = preg_replace('/[^\p{L}\p{N}]+/u', '', $raw) ?? '';
        $latin = preg_replace('/[^a-z0-9]+/i', '', $this->strictTransliteration($compact)) ?? '';
        if ($latin === '') {
            return null;
        }

        $type = match (true) {
            preg_match('/^\d+$/', $latin) === 1 => self::TOKEN_NUMERIC,
            preg_match('/[a-z]/i', $latin) === 1 && preg_match('/\d/', $latin) === 1 => self::TOKEN_SHORT_CODE,
            default => self::TOKEN_WORD,
        };

        return [
            'original' => $raw,
            'normalized' => $normalized,
            'transliteration' => $latin,
            'type' => $type,
            'structured' => $type === self::TOKEN_WORD ? null : strtolower($latin),
        ];
    }

    /** @return list<string> */
    public function structuredTokens(string $value): array
    {
        $tokens = [];
        foreach ($this->classifiedTokens($value) as $token) {
            if ($token['structured'] !== null) {
                $tokens[] = (string) $token['structured'];
            }
        }

        return array_values(array_unique($tokens, SORT_STRING));
    }

    public function strictTransliteration(string $value): string
    {
        $normalized = $this->normalize($value);
        $latin = strtr($normalized, self::RU_TO_LATIN);

        if (preg_match('/[^\x00-\x7F]/', $latin) && class_exists(Transliterator::class)) {
            static $transliterator;
            $transliterator ??= Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
            $latin = $transliterator?->transliterate($latin) ?: $latin;
        }

        $latin = mb_strtolower($latin);
        $latin = preg_replace('/[^a-z0-9]+/i', ' ', $latin) ?? $latin;

        return trim(preg_replace('/\s+/', ' ', $latin) ?? $latin);
    }

    public function keyboardLayoutAlternative(string $value): ?string
    {
        $sourceValue = $this->unicodeBase($value);
        $sourceValue = trim(preg_replace('/\s+/u', ' ', $sourceValue) ?? $sourceValue);
        if ($sourceValue === '') {
            return null;
        }

        $hasLatin = preg_match('/[a-z]/i', $sourceValue) === 1;
        $hasCyrillic = preg_match('/[а-яё]/iu', $sourceValue) === 1;
        if ($hasLatin === $hasCyrillic) {
            return null;
        }

        $from = $hasCyrillic ? self::RU_KEYBOARD : self::EN_KEYBOARD;
        $to = $hasCyrillic ? self::EN_KEYBOARD : self::RU_KEYBOARD;
        $source = preg_split('//u', $from, -1, PREG_SPLIT_NO_EMPTY);
        $target = preg_split('//u', $to, -1, PREG_SPLIT_NO_EMPTY);
        $map = array_combine($source, $target);
        if ($map === false) {
            return null;
        }

        $converted = $this->normalize(strtr($sourceValue, $map));
        $normalized = $this->normalize($sourceValue);

        return $converted !== '' && $converted !== $normalized ? $converted : null;
    }

    /**
     * Backwards-compatible whole-query flag: true only when every meaningful token
     * is structured. A digit in one token never disables word phonetics elsewhere.
     */
    public function isShortCode(string $value): bool
    {
        $tokens = $this->classifiedTokens($value);
        if ($tokens === []) {
            return false;
        }
        foreach ($tokens as $token) {
            if ($token['type'] === self::TOKEN_WORD) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function significantLatinTokens(string $value): array
    {
        $tokens = [];
        foreach ($this->classifiedTokens($value) as $token) {
            if ($token['type'] !== self::TOKEN_WORD || strlen($token['transliteration']) < 3) {
                continue;
            }
            $tokens[$token['transliteration']] = true;
        }

        return array_keys($tokens);
    }

    private function unicodeBase(string $value): string
    {
        return mb_strtolower($this->unicodeBasePreserveCase($value));
    }

    private function unicodeBasePreserveCase(string $value): string
    {
        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
        }

        return strtr($value, [
            "\u{00A0}" => ' ', "\u{2007}" => ' ', "\u{202F}" => ' ', "\u{200B}" => '',
            'ё' => 'е', 'Ё' => 'Е', '’' => "'", '‘' => "'", 'ʼ' => "'", '`' => "'",
            '‐' => '-', '‑' => '-', '‒' => '-', '–' => '-', '—' => '-', '―' => '-', '−' => '-',
        ]);
    }
}
