<?php

namespace App\Services\Catalog;

final class ProductCategoryCatalogRegistry
{
    /**
     * @return array<int, array{full_slug: string, parent_full_slug: ?string, title: string, slug: string, position: int}>
     */
    public function definitions(): array
    {
        return [
            [
                'full_slug' => 'kuzovnye-detali',
                'parent_full_slug' => null,
                'title' => 'Кузовные детали',
                'slug' => 'kuzovnye-detali',
                'position' => 10,
            ],
            [
                'full_slug' => 'kuzovnye-detali/remontnye-elementy-kuzova',
                'parent_full_slug' => 'kuzovnye-detali',
                'title' => 'Ремонтные элементы кузова',
                'slug' => 'remontnye-elementy-kuzova',
                'position' => 10,
            ],
            ...array_map(
                static fn (array $item): array => [
                    'full_slug' => 'kuzovnye-detali/remontnye-elementy-kuzova/'.$item[1],
                    'parent_full_slug' => 'kuzovnye-detali/remontnye-elementy-kuzova',
                    'title' => $item[0],
                    'slug' => $item[1],
                    'position' => $item[2],
                ],
                [
                    ['Пороги', 'porogi', 10],
                    ['Арки', 'arki', 20],
                    ['Лонжероны', 'lonzherony', 30],
                    ['Ремкомплекты пола', 'remkomplekty-pola', 40],
                    ['Заглушки', 'zaglushki', 50],
                    ['Усилители', 'usiliteli', 60],
                    ['Пенные вставки', 'pennye-vstavki', 70],
                ],
            ),
        ];
    }

    /** @return array<string, string> */
    public function legacyStorePaths(): array
    {
        return [
            'kuzovnye-detali/porogi' => 'kuzovnye-detali/remontnye-elementy-kuzova/porogi',
            'kuzovnye-detali/arki' => 'kuzovnye-detali/remontnye-elementy-kuzova/arki',
        ];
    }

    /** @return array<string, array{full_slug: string, parent_full_slug: ?string, title: string, slug: string, position: int}> */
    public function indexedDefinitions(): array
    {
        $indexed = [];

        foreach ($this->definitions() as $definition) {
            $indexed[$definition['full_slug']] = $definition;
        }

        return $indexed;
    }
}
