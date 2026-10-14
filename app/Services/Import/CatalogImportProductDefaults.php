<?php

namespace App\Services\Import;

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCharacteristic;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Services\Catalog\DefaultAutoPartOptionTemplateContract;
use App\Services\Catalog\ProductVariantAdminService;
use App\Services\Catalog\ProductVariantOptionGenerator;
use App\Support\CatalogText;
use LogicException;

final class CatalogImportProductDefaults
{
    public const TEMPLATE_SLUG = DefaultAutoPartOptionTemplateContract::TEMPLATE_SLUG;

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
        private readonly DefaultAutoPartOptionTemplateContract $optionContract,
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

        $this->optionContract->assertReadyForImport($template);

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

        $this->variantGenerator->createMissingVariants($product, DefaultAutoPartOptionTemplateContract::EXPECTED_COMBINATION_COUNT);

        $variants = $product->variants()
            ->with('optionValues.group')
            ->orderBy('id')
            ->get();

        if ($variants->count() !== DefaultAutoPartOptionTemplateContract::EXPECTED_COMBINATION_COUNT) {
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
