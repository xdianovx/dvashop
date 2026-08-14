<?php

namespace App\Services\Import;

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCharacteristic;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionTemplateItem;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Services\Catalog\ProductVariantAdminService;
use App\Services\Catalog\ProductVariantOptionGenerator;
use App\Support\CatalogText;
use Illuminate\Support\Collection;
use LogicException;

final class CatalogImportProductDefaults
{
    public const TEMPLATE_SLUG = 'default_auto_part';

    /**
     * Canonical effective option inventory expected by imported auto parts.
     *
     * @var array<string, array<int, string>>
     */
    private const OPTION_CONTRACT = [
        'profile' => ['full', 'lower'],
        'position' => ['left', 'right', 'both'],
        'material' => ['galvanized', 'cold_rolled'],
        'thickness' => ['1mm', '1_5mm'],
    ];

    /** @var array<string, string> */
    private const DEFAULT_SIGNATURE = [
        'material' => 'galvanized',
        'position' => 'left',
        'profile' => 'full',
        'thickness' => '1mm',
    ];

    /** @var array<int, array{name:string,value:string,position:int}> */
    private const CHARACTERISTICS = [
        ['name' => 'Марка', 'value' => 'Автопороги.ру', 'position' => 10],
        ['name' => 'Производство', 'value' => 'Россия', 'position' => 20],
        ['name' => 'Материал', 'value' => 'Сталь ГОСТ 19904-90', 'position' => 30],
        ['name' => 'Сертификат', 'value' => '№0098556', 'position' => 40],
    ];

    private ?ProductOptionTemplate $cachedTemplate = null;

    public function __construct(
        private readonly ProductVariantOptionGenerator $variantGenerator,
        private readonly ProductVariantAdminService $variantAdmin,
    ) {}

    public function canonicalTemplate(): ProductOptionTemplate
    {
        if ($this->cachedTemplate instanceof ProductOptionTemplate) {
            return $this->cachedTemplate;
        }

        $template = ProductOptionTemplate::query()
            ->with(['items.group', 'items.value'])
            ->where('slug', self::TEMPLATE_SLUG)
            ->first();

        if (! $template instanceof ProductOptionTemplate) {
            $this->fail('Системный шаблон опций «default_auto_part» не найден.');
        }

        if (! $template->is_active
            || $template->applies_to !== ProductOptionGroup::APPLIES_AUTO_PART
            || $template->part_type_id !== null) {
            $this->fail('Системный шаблон опций «default_auto_part» неактивен или имеет несовместимую область применения.');
        }

        $this->validateEffectiveInventory($template, $template->items);

        $this->cachedTemplate = $template;

        return $template;
    }

    public function description(PartType $partType, VehicleGeneration $generation): string
    {
        $generation->loadMissing('model.make');
        $detail = $this->partTypeDisplayTitle($partType);
        $make = CatalogText::plain((string) $generation->model?->make?->title, 250);
        $model = CatalogText::plain((string) $generation->model?->title, 250);

        if ($detail === '' || $make === '' || $model === '') {
            $this->fail('Невозможно сформировать начальное описание импортного товара: не определены тип детали, марка или модель.');
        }

        return sprintf(
            "Ремкомплект «%s» для %s %s предназначен для кузовного ремонта при коррозии, деформации и незначительных повреждениях после ДТП. Имеет запас по длине 5 см для упрощения подгонки при установке. Ремкомплект выполнен из высококачественной стали ГОСТ 19904-90, что гарантирует срок службы до 10 лет.\n\nБлагодаря использованию современного оборудования и усиленному контролю качества, продукция полностью соответствует оригинальным деталям и имеет сертификат РосТест №РО30-4539.",
            $detail,
            $make,
            $model,
        );
    }

    public function initialize(Product $product, ProductOptionTemplate $template): void
    {
        if ((int) $product->product_option_template_id !== (int) $template->getKey()) {
            $this->fail('Новый импортный товар не связан с проверенным системным шаблоном опций.');
        }

        $this->variantGenerator->createMissingVariants($product, 24);

        $variants = $product->variants()
            ->with('optionValues.group')
            ->orderBy('id')
            ->get();

        if ($variants->count() !== 24) {
            $this->fail('Системный шаблон опций должен создавать ровно 24 варианта импортного товара.');
        }

        foreach ($variants as $variant) {
            if ($variant->optionValues->count() !== 4) {
                $this->fail('Каждый импортный вариант должен содержать ровно четыре системные опции.');
            }
        }

        $defaultVariant = $variants->first(
            fn (ProductVariant $variant): bool => $this->variantSignature($variant) === self::DEFAULT_SIGNATURE,
        );

        if (! $defaultVariant instanceof ProductVariant) {
            $this->fail('Не найден вариант по умолчанию full + left + galvanized + 1mm.');
        }

        $this->variantAdmin->setDefault($product, $defaultVariant);

        if ($product->characteristics()->exists()) {
            $this->fail('Новый импортный товар неожиданно содержит характеристики до начальной инициализации.');
        }

        $product->characteristics()->createMany(array_map(
            static fn (array $item): array => [
                ...$item,
                'unit' => null,
                'source_type' => ProductCharacteristic::SOURCE_IMPORT,
                'is_visible' => true,
            ],
            self::CHARACTERISTICS,
        ));
    }

    /** @param Collection<int, ProductOptionTemplateItem> $items */
    private function validateEffectiveInventory(ProductOptionTemplate $template, Collection $items): void
    {
        $effective = $items->filter(function (ProductOptionTemplateItem $item): bool {
            return $item->group instanceof ProductOptionGroup
                && $item->value instanceof ProductOptionValue
                && $item->group->is_active
                && $item->value->is_active;
        })->values();

        if ($effective->count() !== 9) {
            $this->fail('Системный шаблон «default_auto_part» должен содержать ровно 9 активных selectable values.');
        }

        $actual = [];
        foreach ($effective as $item) {
            $group = $item->group;
            $value = $item->value;

            if (! $group instanceof ProductOptionGroup || ! $value instanceof ProductOptionValue) {
                $this->fail('Системный шаблон содержит повреждённую связь группы или значения опции.');
            }

            if ((int) $value->product_option_group_id !== (int) $group->getKey()) {
                $this->fail('Системный шаблон содержит значение, принадлежащее другой группе опций.');
            }

            $groupCode = (string) $group->code;
            $valueCode = (string) $value->code;

            if (! array_key_exists($groupCode, self::OPTION_CONTRACT)
                || ! in_array($valueCode, self::OPTION_CONTRACT[$groupCode], true)) {
                $this->fail('Системный шаблон «default_auto_part» содержит неожиданную активную группу или значение.');
            }

            if (! in_array($group->applies_to, [
                ProductOptionGroup::APPLIES_ALL,
                ProductOptionGroup::APPLIES_AUTO_PART,
            ], true)) {
                $this->fail("Системная группа «{$groupCode}» несовместима с автодеталями.");
            }

            $actual[$groupCode][] = $valueCode;
        }

        ksort($actual);
        $expected = self::OPTION_CONTRACT;
        ksort($expected);

        foreach ($actual as &$values) {
            sort($values);
        }
        unset($values);

        foreach ($expected as &$values) {
            sort($values);
        }
        unset($values);

        if ($actual !== $expected) {
            $this->fail('Активный inventory шаблона «default_auto_part» не соответствует canonical contract 4 groups / 9 values / 24 combinations.');
        }

        if (count($this->variantGenerator->combinationsForTemplate($template)) !== 24) {
            $this->fail('Системный шаблон «default_auto_part» должен создавать ровно 24 комбинации.');
        }
    }

    /** @return array<string, string> */
    private function variantSignature(ProductVariant $variant): array
    {
        return $variant->optionValues
            ->mapWithKeys(function (ProductOptionValue $value): array {
                $groupCode = (string) $value->group?->code;

                return $groupCode === '' ? [] : [$groupCode => (string) $value->code];
            })
            ->sortKeys()
            ->all();
    }

    private function partTypeDisplayTitle(PartType $partType): string
    {
        $segments = array_values(array_filter(array_map(
            static fn (string $segment): string => CatalogText::plain($segment, 250),
            preg_split('#\s*/\s*#u', (string) ($partType->full_title ?: $partType->title)) ?: [],
        ), static fn (string $segment): bool => $segment !== ''));

        $title = implode(' ', array_map(
            fn (string $segment, int $index): string => $index === 0 ? $segment : $this->lowerFirst($segment),
            $segments,
            array_keys($segments),
        ));

        return CatalogText::plain($title !== '' ? $title : $partType->title, 250);
    }

    private function lowerFirst(string $value): string
    {
        $value = CatalogText::plain($value, 250);

        if ($value === '') {
            return '';
        }

        return mb_strtolower(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }

    private function fail(string $message): never
    {
        throw new LogicException($message);
    }
}
