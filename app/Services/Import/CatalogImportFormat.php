<?php

namespace App\Services\Import;

use App\Support\CatalogText;
use InvalidArgumentException;

/** The workbook has two header rows; identity comes from named structural columns. */
final class CatalogImportFormat
{
    private const FIELDS = [
        'марка' => 'make', 'модель' => 'model', 'поколение' => 'generation',
        'фото' => 'photo', 'годы' => 'years', 'годы выпуска' => 'years', 'кузов' => 'body', 'платформа' => 'platform',
        'рестайлинг' => 'restyling', 'ссылка на фото автомобиля' => 'photo',
    ];

    public static function sheetName(array $names): string
    {
        return array_values($names)[0] ?? '';
    }

    public static function isMetadata(string $title): bool
    {
        return isset(self::FIELDS[mb_strtolower(CatalogText::plain($title))]);
    }

    /** @return array<string, array<int, int>> */
    public static function columns(array $headers): array
    {
        $columns = [];
        foreach ($headers[2] ?? [] as $index => $title) {
            $field = self::FIELDS[mb_strtolower(CatalogText::plain($title))] ?? null;
            if ($field !== null) {
                $columns[$field][] = $index;
            }
        }
        foreach (['make', 'model', 'generation', 'years', 'body'] as $field) {
            if (count($columns[$field] ?? []) !== 1) {
                throw new InvalidArgumentException("Обязательная служебная колонка {$field} отсутствует или повторяется на первом листе.");
            }
        }
        foreach (['platform', 'restyling'] as $field) {
            if (count($columns[$field] ?? []) > 1) {
                throw new InvalidArgumentException("Служебная колонка {$field} повторяется.");
            }
        }

        return $columns;
    }

    public static function validateHeaders(array $headers): void
    {
        self::columns($headers);
    }

    public static function normalize(array $row, array $headers): array
    {
        $columns = self::columns($headers);
        $field = static fn (string $name): string => CatalogText::plain($row[$columns[$name][0] ?? -1] ?? '');
        $make = $field('make');
        $model = $field('model');
        $generation = $field('generation');
        $platform = $field('platform');
        if ($make === '' || $model === '' || ($generation === '' && $platform === '')) {
            throw new InvalidArgumentException('Не заполнены марка, модель или поколение/платформа автомобиля.');
        }
        $generation = $generation !== '' ? $generation : $platform;
        // The same exact platform may already be part of the legacy generation designation.
        if ($platform !== '' && $generation !== $platform && ! str_ends_with($generation, ' ('.$platform.')')) {
            $generation .= ' ('.$platform.')';
        }
        if ($field('restyling') !== '') {
            $generation .= ' ['.$field('restyling').']';
        }
        $photo = '';
        foreach ($columns['photo'] ?? [] as $index) {
            if (CatalogText::plain($row[$index] ?? '') !== '') {
                $photo = CatalogText::plain($row[$index]);
                break;
            }
        }

        return array_replace($row, [
            0 => $photo, 1 => $make, 2 => $model, 3 => $generation,
            4 => $field('years'), 5 => self::body($field('body')),
            '_details' => $row, '_catalog_v2' => true,
            '_platform' => $platform, '_restyling' => $field('restyling'),
        ]);
    }

    public static function body(string $body): string
    {
        return preg_replace('/\b(\d+)\s+двер(?:и|ей|ь)\b/ui', '$1 дв.', $body);
    }
}
