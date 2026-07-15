<?php

namespace Database\Seeders;

use App\Models\PartType;
use App\Services\Catalog\PartTypeCategoryResolver;
use App\Services\Catalog\PartTypeTreeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PartTypeSeeder extends Seeder
{
    public function run(PartTypeTreeService $tree, PartTypeCategoryResolver $resolver): void
    {
        DB::transaction(function () use ($tree, $resolver): void {
            foreach ($this->definitions() as $rootDefinition) {
                $root = $this->persist(
                    definition: $rootDefinition,
                    parent: null,
                    tree: $tree,
                    resolver: $resolver,
                );

                foreach ($rootDefinition['children'] ?? [] as $childDefinition) {
                    $this->persist(
                        definition: $childDefinition,
                        parent: $root,
                        tree: $tree,
                        resolver: $resolver,
                    );
                }
            }
        });
    }

    /**
     * @param  array{title: string, position: int, default_image_key: ?string, children?: array<int, array{title: string, position: int, default_image_key: ?string}>}  $definition
     */
    private function persist(
        array $definition,
        ?PartType $parent,
        PartTypeTreeService $tree,
        PartTypeCategoryResolver $resolver,
    ): PartType {
        $localSlug = $tree->slugForTitle($definition['title']);
        $fullSlug = $parent instanceof PartType ? $parent->full_slug.'/'.$localSlug : $localSlug;
        $partType = PartType::withTrashed()->where('full_slug', $fullSlug)->first() ?? new PartType;

        $partType->forceFill([
            'parent_id' => $parent?->getKey(),
            'title' => $definition['title'],
            'position' => $definition['position'],
            'is_active' => true,
            'default_image_key' => $definition['default_image_key'],
        ]);

        $tree->save($partType);

        if ($partType->trashed()) {
            $partType->restoreQuietly();
        }

        $resolution = $resolver->resolve($partType);
        $partType->forceFill(['product_category_id' => $resolution->category->getKey()])->saveQuietly();

        return $partType;
    }

    /**
     * @return array<int, array{title: string, position: int, default_image_key: ?string, children?: array<int, array{title: string, position: int, default_image_key: ?string}>}>
     */
    private function definitions(): array
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
}
