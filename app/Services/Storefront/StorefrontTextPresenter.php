<?php

namespace App\Services\Storefront;

use Illuminate\Support\Str;

final class StorefrontTextPresenter
{
    /** @return list<string> */
    public function lines(?string $text, ?string $breakBefore = null): array
    {
        $text = $this->plain($text);

        if ($text === null) {
            return [];
        }

        if ($breakBefore !== null) {
            $position = mb_stripos($text, $breakBefore);

            if ($position !== false && $position > 0) {
                return [
                    trim(mb_substr($text, 0, $position)),
                    trim(mb_substr($text, $position)),
                ];
            }
        }

        return [$text];
    }

    /**
     * @param  list<string>  $strongPhrases
     * @return list<array{text:string,strong:bool}>
     */
    public function segments(?string $text, array $strongPhrases): array
    {
        $text = $this->plain($text);

        if ($text === null) {
            return [];
        }

        $phrases = array_values(array_filter(array_map(
            fn (string $phrase): string => trim($phrase),
            $strongPhrases,
        ), fn (string $phrase): bool => $phrase !== ''));

        if ($phrases === []) {
            return [['text' => $text, 'strong' => false]];
        }

        usort($phrases, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
        $pattern = '/('.implode('|', array_map(fn (string $phrase): string => preg_quote($phrase, '/'), $phrases)).')/iu';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if (! is_array($parts) || $parts === []) {
            return [['text' => $text, 'strong' => false]];
        }

        return array_map(function (string $part) use ($phrases): array {
            $strong = collect($phrases)->contains(
                fn (string $phrase): bool => mb_strtolower($phrase) === mb_strtolower($part),
            );

            return ['text' => $part, 'strong' => $strong];
        }, $parts);
    }

    public function plain(?string $value): ?string
    {
        $value = Str::squish((string) $value);

        return $value === '' ? null : $value;
    }
}
