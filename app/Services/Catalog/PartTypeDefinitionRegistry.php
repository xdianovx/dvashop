<?php

namespace App\Services\Catalog;

final class PartTypeDefinitionRegistry
{
    /**
     * @return array<int, array{title: string, position: int, default_image_key: ?string, children?: array<int, array{title: string, position: int, default_image_key: ?string}>}>
     */
    public function definitions(): array
    {
        return [
            ['title' => 'Порог', 'position' => 10, 'default_image_key' => 'porog'],
            [
                'title' => 'Арка',
                'position' => 20,
                'default_image_key' => null,
                'children' => [
                    ['title' => 'Задняя', 'position' => 10, 'default_image_key' => 'arka-zadniaia'],
                    ['title' => 'Передняя', 'position' => 20, 'default_image_key' => 'arka-peredniaia'],
                    ['title' => 'Внутренняя', 'position' => 30, 'default_image_key' => 'arka-vnutrenniaia'],
                    ['title' => 'Внутренняя универсальная', 'position' => 40, 'default_image_key' => 'arka-vnutrenniaia-universalnaia'],
                    ['title' => 'Карман задняя', 'position' => 50, 'default_image_key' => 'arka-karman-zadniaia'],
                ],
            ],
            [
                'title' => 'Пенка',
                'position' => 30,
                'default_image_key' => null,
                'children' => [
                    ['title' => 'Задней двери', 'position' => 10, 'default_image_key' => 'penka-zadnei-dveri'],
                    ['title' => 'Передней двери', 'position' => 20, 'default_image_key' => 'penka-perednei-dveri'],
                    ['title' => 'Багажника', 'position' => 30, 'default_image_key' => 'penka-bagaznika'],
                ],
            ],
            ['title' => 'Лонжерон', 'position' => 40, 'default_image_key' => 'lonzeron'],
            ['title' => 'Ремкомплект пола', 'position' => 50, 'default_image_key' => 'remkomplekt-pola'],
            ['title' => 'Торцевая заглушка', 'position' => 60, 'default_image_key' => 'torcevaia-zagluska'],
            [
                'title' => 'Усилитель',
                'position' => 70,
                'default_image_key' => null,
                'children' => [
                    ['title' => 'соединитель порогов', 'position' => 10, 'default_image_key' => 'usilitel-soedinitel-porogov'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{full_slug: string, parent_full_slug: ?string, title: string, position: int, default_image_key: ?string}>
     */
    public function flattened(PartTypeTreeService $tree): array
    {
        $items = [];

        foreach ($this->definitions() as $root) {
            $rootSlug = $tree->slugForTitle($root['title']);
            $items[$rootSlug] = [
                'full_slug' => $rootSlug,
                'parent_full_slug' => null,
                'title' => $root['title'],
                'position' => $root['position'],
                'default_image_key' => $root['default_image_key'],
            ];

            foreach ($root['children'] ?? [] as $child) {
                $childSlug = $tree->slugForTitle($child['title']);
                $fullSlug = $rootSlug.'/'.$childSlug;
                $items[$fullSlug] = [
                    'full_slug' => $fullSlug,
                    'parent_full_slug' => $rootSlug,
                    'title' => $child['title'],
                    'position' => $child['position'],
                    'default_image_key' => $child['default_image_key'],
                ];
            }
        }

        return $items;
    }
}
