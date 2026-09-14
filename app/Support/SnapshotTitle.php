<?php

namespace App\Support;

final class SnapshotTitle
{
    public static function withoutOptionSummary(string $title, string $summary): string
    {
        $suffix = ' — '.$summary;

        // Only remove an exact saved option summary, never arbitrary title text.
        if ($summary !== '' && str_ends_with($title, $suffix)) {
            $baseTitle = substr($title, 0, -strlen($suffix));

            if (filled($baseTitle)) {
                return $baseTitle;
            }
        }

        // Generated variant titles and saved options can differ only in ordering.
        // Compare the complete trailing list; never remove individual title fragments.
        $separator = strrpos($title, ' — ');
        if ($summary !== '' && $separator !== false) {
            $baseTitle = substr($title, 0, $separator);
            $trailing = substr($title, $separator + strlen(' — '));
            $expected = explode('; ', $summary);
            $actual = explode('; ', $trailing);

            // Keep ambiguous free-form values intact instead of guessing their boundaries.
            if (filled($baseTitle) && count($expected) > 1 && count($actual) === count($expected)
                && count(array_filter($expected, static fn (string $option): bool => str_contains($option, ': '))) === count($expected)) {
                sort($expected, SORT_STRING);
                sort($actual, SORT_STRING);

                if ($actual === $expected) {
                    return $baseTitle;
                }
            }
        }

        return $title;
    }
}
