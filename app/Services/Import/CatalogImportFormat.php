<?php

namespace App\Services\Import;

use App\Support\CatalogText;
use InvalidArgumentException;

/** The workbook has two header rows; identity comes from named structural columns. */
final class CatalogImportFormat
{
    public const SHEET_NAME = 'Каталог (new)';

    private const FIELDS = [
        'марка' => 'make', 'модель' => 'model', 'поколение' => 'generation',
        'фото' => 'photo', 'годы' => 'years', 'годы выпуска' => 'years', 'кузов' => 'body', 'платформа' => 'platform',
        'рестайлинг' => 'restyling', 'ссылка на фото автомобиля' => 'photo',
    ];

    public static function sheetName(array $names): string
    {
        if (count($names) !== 1) {
            throw new InvalidArgumentException('Оставьте в XLSX один рабочий лист: источник данных должен быть однозначным.');
        }

        return (string) reset($names);
    }

    public static function isMetadata(string $title): bool
    {
        return isset(self::FIELDS[mb_strtolower(CatalogText::plain($title))]);
    }

    /** Legacy XLSX column A holds a photo URL or the literal "нет изображения". */
    private static function isLegacyPhotoColumn(array $headers, int $column): bool
    {
        return $column === 0
            && CatalogText::plain($headers[1][0] ?? '') === ''
            && CatalogText::plain($headers[2][0] ?? '') === ''
            && mb_strtolower(CatalogText::plain($headers[2][1] ?? '')) === 'марка';
    }

    /** Only a real HTTP(S) URL may be imported into photo URLs. */
    public static function isUnlabelledLeadingPhoto(array $headers, int $column, mixed $value): bool
    {
        if (! self::isLegacyPhotoColumn($headers, $column)) {
            return false;
        }

        $url = CatalogText::plain($value);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /** The original workbook explicitly marks missing photos in the same column. */
    public static function isUnlabelledMissingPhoto(array $headers, int $column, mixed $value): bool
    {
        return self::isLegacyPhotoColumn($headers, $column)
            && mb_strtolower(CatalogText::plain($value)) === 'нет изображения';
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
                throw new InvalidArgumentException("Обязательная служебная колонка {$field} отсутствует или повторяется во второй строке заголовков.");
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
        if ($missing = self::missingFields($row, $headers)) {
            throw new InvalidArgumentException('Не заполнены обязательные поля: '.implode(', ', $missing).'.');
        }
        // The same exact platform may already be part of the legacy generation designation.
        if ($platform !== '' && $generation !== $platform && ! str_ends_with($generation, ' ('.$platform.')')) {
            $generation .= ' ('.$platform.')';
        }
        $photos = [];
        if (self::isUnlabelledLeadingPhoto($headers, 0, $row[0] ?? null)) {
            $photos[] = CatalogText::plain($row[0]);
        }
        foreach ($columns['photo'] ?? [] as $index) {
            $url = CatalogText::plain($row[$index] ?? '');
            if (filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                $photos[] = $url;
            }
        }
        $photos = array_slice(array_values(array_unique($photos)), 0, 3);

        return array_replace($row, [
            0 => $photos[0] ?? '', 1 => $make, 2 => $model, 3 => $generation,
            4 => $field('years'), 5 => self::body($field('body')),
            '_details' => $row, '_catalog_v2' => true, '_photo_urls' => $photos,
            '_platform' => $platform, '_restyling' => $field('restyling'),
        ]);
    }

    /** Shared by preview and preparation; incomplete vehicles are never partly imported. */
    public static function missingFields(array $row, array $headers): array
    {
        $columns = self::columns($headers);
        $missing = [];
        foreach (['make' => 'марка', 'model' => 'модель', 'generation' => 'поколение', 'years' => 'годы выпуска', 'body' => 'кузов'] as $field => $label) {
            if (CatalogText::plain($row[$columns[$field][0]] ?? '') === '') {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    public static function body(string $body): string
    {
        return preg_replace('/\b(\d+)\s+двер(?:и|ей|ь)\b/ui', '$1 дв.', $body);
    }
}
