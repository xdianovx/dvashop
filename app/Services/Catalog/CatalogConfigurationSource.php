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
        'Барабан' => 'arka/vnutrenniaia-universalnaia', 'Арка внутренняя' => 'arka/vnutrenniaia',
        'Задняя арка' => 'arka/zadniaia', 'Передняя арка' => 'arka/peredniaia',
        'Пенка багажника' => 'penka/bagazhnika', 'Передняя пенка двери' => 'penka/perednei-dveri',
        'Задняя пенка двери' => 'penka/zadnei-dveri',
        'Стандартный порог' => 'porog', 'Увеличенный порог' => 'porog',
    ];

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
        try {
            $header = array_map(fn ($v) => CatalogText::plain(ltrim((string) $v, "\xEF\xBB\xBF")), fgetcsv($file, escape: '') ?: []);
            if ($header !== ['Название', 'Профиль', 'Положение', 'Материал', 'Толщина металла', 'Цена']) {
                throw new RuntimeException('Неожиданная структура CSV конфигураций.');
            }
            while (($line = fgetcsv($file, escape: '')) !== false) {
                if ($line === [null]) {
                    continue;
                }
                $line = array_map(fn ($v) => CatalogText::plain((string) $v), $line);
                if (count($line) !== 6 || ! isset(self::TYPES[$line[0]])) {
                    throw new RuntimeException('Неизвестная группа или повреждённая строка CSV.');
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
                $type = self::TYPES[$line[0]];
                if (isset($seen[$type.':'.$signature])) {
                    throw new RuntimeException('Повтор комбинации CSV.');
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
