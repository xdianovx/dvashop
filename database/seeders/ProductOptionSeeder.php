<?php

namespace Database\Seeders;

use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionTemplateItem;
use App\Models\ProductOptionValue;
use Illuminate\Database\Seeder;

class ProductOptionSeeder extends Seeder
{
    /**
     * @var array<string, array{title:string, position:int, values:array<string, array{title:string, position:int, default?:bool}>}>
     */
    private const GROUPS = [
        'profile' => [
            'title' => 'Профиль',
            'position' => 10,
            'values' => [
                'full' => ['title' => 'Полный', 'position' => 10, 'default' => true],
                'lower' => ['title' => 'Нижняя часть', 'position' => 20],
            ],
        ],
        'position' => [
            'title' => 'Положение',
            'position' => 20,
            'values' => [
                'left' => ['title' => 'Левый', 'position' => 10],
                'right' => ['title' => 'Правый', 'position' => 20],
                'both' => ['title' => 'Левый + Правый', 'position' => 30, 'default' => true],
            ],
        ],
        'material' => [
            'title' => 'Материал',
            'position' => 30,
            'values' => [
                'galvanized' => ['title' => 'Оцинковка', 'position' => 10, 'default' => true],
                'cold_rolled' => ['title' => 'Х/С сталь', 'position' => 20],
            ],
        ],
        'thickness' => [
            'title' => 'Толщина металла',
            'position' => 40,
            'values' => [
                '1mm' => ['title' => '1 мм', 'position' => 10, 'default' => true],
                '1_5mm' => ['title' => '1,5 мм', 'position' => 20],
            ],
        ],
    ];

    public function run(): void
    {
        $template = ProductOptionTemplate::query()->updateOrCreate(
            ['slug' => 'default_auto_part'],
            [
                'title' => 'Стандартные опции автодетали',
                'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
                'part_type_id' => null,
                'is_default' => true,
                'is_active' => true,
                'position' => 10,
            ],
        );

        foreach (self::GROUPS as $code => $definition) {
            $group = ProductOptionGroup::query()->updateOrCreate(
                ['slug' => $code],
                [
                    'title' => $definition['title'],
                    'code' => $code,
                    'input_type' => 'radio',
                    'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
                    'is_required' => true,
                    'is_active' => true,
                    'position' => $definition['position'],
                ],
            );

            foreach ($definition['values'] as $valueCode => $valueDefinition) {
                $value = ProductOptionValue::query()->updateOrCreate(
                    [
                        'product_option_group_id' => $group->getKey(),
                        'slug' => $valueCode,
                    ],
                    [
                        'title' => $valueDefinition['title'],
                        'code' => $valueCode,
                        'is_default' => (bool) ($valueDefinition['default'] ?? false),
                        'is_active' => true,
                        'position' => $valueDefinition['position'],
                    ],
                );

                ProductOptionTemplateItem::query()->updateOrCreate(
                    [
                        'product_option_template_id' => $template->getKey(),
                        'product_option_group_id' => $group->getKey(),
                        'product_option_value_id' => $value->getKey(),
                    ],
                    ['position' => $definition['position'] + $valueDefinition['position']],
                );
            }
        }
    }
}
