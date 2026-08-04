<?php

namespace Database\Seeders;

use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionTemplateItem;
use App\Models\ProductOptionValue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

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
        DB::transaction(function (): void {
            $template = ProductOptionTemplate::query()->firstOrCreate(
                ['slug' => 'default_auto_part'],
                [
                    'title' => 'Стандартные опции автодетали',
                    'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
                    'part_type_id' => null,
                    'is_default' => ! ProductOptionTemplate::query()
                        ->where('applies_to', ProductOptionGroup::APPLIES_AUTO_PART)
                        ->whereNull('part_type_id')
                        ->where('is_default', true)
                        ->exists(),
                    'is_active' => true,
                    'position' => 10,
                ],
            );

            $this->ensureCanonicalTemplateIsCompatible($template);

            foreach (self::GROUPS as $code => $definition) {
                $group = $this->resolveGroup($code, $definition);
                $this->ensureSystemGroupIsCompatible($group, $code);
                ProductOptionGroup::query()->whereKey($group->getKey())->lockForUpdate()->firstOrFail();

                foreach ($definition['values'] as $valueCode => $valueDefinition) {
                    $value = $this->resolveValue($group, $valueCode, $valueDefinition);

                    ProductOptionTemplateItem::query()->firstOrCreate(
                        [
                            'product_option_template_id' => $template->getKey(),
                            'product_option_group_id' => $group->getKey(),
                            'product_option_value_id' => $value->getKey(),
                        ],
                        ['position' => $definition['position'] + $valueDefinition['position']],
                    );
                }
            }
        });
    }

    private function ensureCanonicalTemplateIsCompatible(ProductOptionTemplate $template): void
    {
        if ($template->applies_to === ProductOptionGroup::APPLIES_AUTO_PART
            && $template->part_type_id === null) {
            return;
        }

        throw new LogicException(
            'Системный шаблон «default_auto_part» имеет несовместимую область применения. '
            .'Ожидаются applies_to=auto_part и пустой part_type_id.',
        );
    }

    private function ensureSystemGroupIsCompatible(ProductOptionGroup $group, string $code): void
    {
        if (in_array($group->applies_to, [
            ProductOptionGroup::APPLIES_ALL,
            ProductOptionGroup::APPLIES_AUTO_PART,
        ], true)) {
            return;
        }

        throw new LogicException(
            "Системная группа «{$code}» имеет несовместимую область применения «{$group->applies_to}».",
        );
    }

    /** @param array{title:string, position:int, values:array<string, array{title:string, position:int, default?:bool}>} $definition */
    private function resolveGroup(string $code, array $definition): ProductOptionGroup
    {
        $bySlug = ProductOptionGroup::query()->where('slug', $code)->first();
        $byCode = ProductOptionGroup::query()->where('code', $code)->first();

        if ($bySlug instanceof ProductOptionGroup
            && $byCode instanceof ProductOptionGroup
            && ! $bySlug->is($byCode)) {
            throw new LogicException("Конфликт системной группы «{$code}»: slug и code принадлежат разным записям.");
        }

        return $bySlug ?? $byCode ?? ProductOptionGroup::query()->create([
            'title' => $definition['title'],
            'slug' => $code,
            'code' => $code,
            'input_type' => 'radio',
            'applies_to' => ProductOptionGroup::APPLIES_AUTO_PART,
            'is_required' => true,
            'is_active' => true,
            'position' => $definition['position'],
        ]);
    }

    /** @param array{title:string, position:int, default?:bool} $definition */
    private function resolveValue(
        ProductOptionGroup $group,
        string $code,
        array $definition,
    ): ProductOptionValue {
        $bySlug = ProductOptionValue::query()
            ->where('product_option_group_id', $group->getKey())
            ->where('slug', $code)
            ->first();
        $byCode = ProductOptionValue::query()
            ->where('product_option_group_id', $group->getKey())
            ->where('code', $code)
            ->first();

        if ($bySlug instanceof ProductOptionValue
            && $byCode instanceof ProductOptionValue
            && ! $bySlug->is($byCode)) {
            throw new LogicException("Конфликт системного значения «{$code}» в группе «{$group->title}»: slug и code принадлежат разным записям.");
        }

        if ($bySlug instanceof ProductOptionValue || $byCode instanceof ProductOptionValue) {
            return $bySlug ?? $byCode;
        }

        $shouldBeDefault = (bool) ($definition['default'] ?? false)
            && ! $group->values()->where('is_default', true)->exists();

        return $group->values()->create(
            [
                'title' => $definition['title'],
                'slug' => $code,
                'code' => $code,
                'is_default' => $shouldBeDefault,
                'is_active' => true,
                'position' => $definition['position'],
            ],
        );
    }
}
