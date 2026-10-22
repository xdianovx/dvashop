<?php

namespace App\Services\Catalog;

use App\Support\CatalogText;
use RuntimeException;

/** Structural aliases only. All configuration prices come from the supplied CSV. */
final class CatalogConfigurationSource
{
    public const TYPES = [
        'Лонжерон' => 'lonzheron', 'Усилитель' => 'usilitel', 'Соединитель' => 'soedinitel',
        'Торцевая заглушка' => 'tortsevaia-zaglushka', 'Ремкомплект пола' => 'remkomplekt-pola',
        'Барабан' => 'arka/vnutrenniaia-universalnaia', 'Арка внутренняя универсальная' => 'arka/vnutrenniaia-universalnaia', 'Арка внутренняя' => 'arka/vnutrenniaia',
        'Задняя арка' => 'arka/zadniaia', 'Передняя арка' => 'arka/peredniaia',
        'Пенка багажника' => 'penka/bagazhnika', 'Передняя пенка двери' => 'penka/perednei-dveri',
        'Задняя пенка двери' => 'penka/zadnei-dveri',
        'Стандартный порог' => 'porog', 'Увеличенный порог' => 'porog',
    ];

    /** Explicit source-to-catalog mapping; legacy inner-arch identity remains supported. */
    public static function targetPaths(array $row): array
    {
        return match ($row['type']) {
            'porog' => ['porog', $row['options']['profile'] === 'standard' ? 'porog/standartnyi' : 'porog/uvelichennyi'],
            'arka/vnutrenniaia' => ['arka/vnutrenniaia', 'arka/vnutrenniaia-zadniaia', 'arka/vnutrenniaia-peredniaia'],
            default => [$row['type']],
        };
    }

    public static function managedPaths(): array
    {
        return array_values(array_unique([...array_values(self::TYPES), 'porog/standartnyi', 'porog/uvelichennyi',
            'arka/vnutrenniaia-zadniaia', 'arka/vnutrenniaia-peredniaia']));
    }

    public const VALUES = [
        'profile' => ['Стандартный' => 'standard', 'Увеличенный' => 'increased'],
        'position' => ['Левый' => 'left', 'Правый' => 'right', 'Левый + Правый' => 'both'],
        'material' => ['ХКС' => 'cold_rolled', 'Оцинковка' => 'galvanized'],
        'thickness' => ['0,8 мм' => '0_8mm', '1 мм' => '1mm', '1,5 мм' => '1_5mm'],
    ];

    public static function signature(array $options): string
    {
        ksort($options);

        return hash('sha256', json_encode($options, JSON_THROW_ON_ERROR));
    }

    public function read(string $path): array
    {
        $file = fopen($path, 'rb');
        if ($file === false) {
            throw new RuntimeException('Не найден CSV конфигураций.');
        }
        $rows = [];
        $seen = [];
        $lineNumber = 1;
        try {
            $header = array_map(fn ($v) => CatalogText::plain(ltrim((string) $v, "\xEF\xBB\xBF")), fgetcsv($file, escape: '') ?: []);
            if ($header !== ['Название', 'Профиль', 'Положение', 'Материал', 'Толщина металла', 'Цена']) {
                throw new RuntimeException('Неожиданная структура CSV конфигураций.');
            }
            while (($line = fgetcsv($file, escape: '')) !== false) {
                $lineNumber++;
                if ($line === [null]) {
                    continue;
                }
                $line = array_map(fn ($v) => CatalogText::plain((string) $v), $line);
                if (count($line) !== 6 || $line[0] === '') {
                    throw new RuntimeException('CSV, строка '.$lineNumber.': пустой тип детали или неверное количество колонок.');
                }
                $options = [];
                foreach (array_keys(self::VALUES) as $i => $group) {
                    $value = $line[$i + 1];
                    if ($value === '-') {
                        continue;
                    }
                    $options[$group] = self::VALUES[$group][$value] ?? throw new RuntimeException('Неизвестная характеристика: '.$value);
                }
                $signature = self::signature($options);
                // First reuse the established catalogue aliases. For future approved
                // types, resolve their *structural* path from the existing catalogue
                // definition registry instead of introducing per-name import hacks.
                $type = self::TYPES[$line[0]] ?? null;
                if ($type === null) {
                    $type = app(\App\Services\Import\ImportPartTypeResolver::class)->structuralPath([
                        'title' => $line[0],
                    ]);
                    $definitions = app(PartTypeDefinitionRegistry::class)->flattened(app(PartTypeTreeService::class));
                    if (! isset($definitions[$type])) {
                        throw new RuntimeException('CSV, строка '.$lineNumber.': неизвестная или неоднозначная группа «'.$line[0].'».');
                    }
                }
                if ($type === 'porog' && ! isset($options['profile'])) {
                    throw new RuntimeException('CSV, строка '.$lineNumber.': для порога обязателен профиль.');
                }
                if (isset($seen[$type.':'.$signature])) {
                    throw new RuntimeException('CSV, строка '.$lineNumber.': повторная комбинация для «'.$line[0].'».');
                }
                $seen[$type.':'.$signature] = true;
                $rows[] = ['group' => $line[0], 'type' => $type, 'signature' => $signature, 'options' => $options,
                    'price' => app(CatalogBulkPrices::class)->price($line[5])];
            }
        } finally {
            fclose($file);
        }
        if ($rows === []) {
            throw new RuntimeException('Пустой CSV конфигураций.');
        }

        return $rows;
    }
}
