<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Validator;
use Transliterator;

final class SearchText
{
    public static function aliases(mixed $aliases): array
    {
        Validator::make(['search_aliases' => $aliases], [
            'search_aliases' => ['nullable', 'array', 'max:20'],
            'search_aliases.*' => ['string', 'max:100', 'not_regex:/[<>]/u'],
        ], [
            'search_aliases.max' => 'Укажите не более 20 поисковых названий.',
            'search_aliases.*.max' => 'Поисковое название не должно превышать 100 символов.',
            'search_aliases.*.not_regex' => 'Поисковые названия не должны содержать HTML.',
        ])->validate();

        return self::unique($aliases ?? []);
    }

    public static function unique(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
            if ($value !== '') {
                $result[mb_strtolower($value)] ??= $value;
            }
        }

        return array_values($result);
    }

    public static function transliterate(string $text): string
    {
        static $transliterator;
        $transliterator ??= Transliterator::create('Any-Latin; Latin-ASCII; Lower()');

        return trim($transliterator?->transliterate($text) ?: mb_strtolower($text));
    }

    public static function key(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
    }
}
