<?php

namespace App\Services\Import;

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductOptionTemplate;
use App\Models\VehicleGeneration;
use App\Services\Catalog\CatalogConfigurations;
use App\Support\CatalogText;
use Illuminate\Validation\ValidationException;
use LogicException;

final class CatalogImportProductDefaults
{
    private array $templates = [];

    /** Read-only preflight, before resolving headers can create any catalog entities. */
    public function assertHeadersReady(array $headers): void
    {
        $matrix = app(CatalogConfigurations::class);
        $matrix->assertReady();
        app(\App\Services\Catalog\CatalogMatrixPriceHistory::class)->sourceSha256();
        $paths = array_unique(array_map(fn ($header) => app(ImportPartTypeResolver::class)->structuralPath($header), $headers));
        $types = PartType::whereIn('full_slug', $paths)->get()->keyBy('full_slug');
        foreach ($paths as $path) {
            $type = $types->get($path);
            if (! $type || ! $matrix->template($type->id)) {
                throw ValidationException::withMessages(['configuration' => 'Нет установленной матрицы CSV для типа '.$path.'. Установите согласованные конфигурации до импорта.']);
            }
            $this->templateFor($type);
        }
    }

    public function templateFor(PartType $type): ProductOptionTemplate
    {
        if (isset($this->templates[$type->id])) {
            return $this->templates[$type->id];
        }
        $matrix = app(CatalogConfigurations::class);
        $matrix->assertReady();
        app(\App\Services\Catalog\CatalogMatrixPriceHistory::class)->sourceSha256();
        $template = $matrix->template($type->id);
        if (! $template || ! $template->is_active || (int) $template->part_type_id !== (int) $type->id
            || ! str_starts_with((string) $template->slug, 'matrix_')) {
            throw ValidationException::withMessages(['configuration' => 'У типа '.$type->full_slug.' нет разрешённых конфигураций CSV.']);
        }
        $combinations = $matrix->combinations($template);
        if ($combinations === null || count($combinations) !== $matrix->rows($type->id)->count()) {
            throw ValidationException::withMessages(['configuration' => 'Опции матрицы '.$type->full_slug.' неполны или отключены.']);
        }

        return $this->templates[$type->id] = $template;
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

        // The two import sources do not certify materials, standards, brands or
        // warranties. Never invent commercial claims for imported products.
        return sprintf('Деталь «%s» для %s %s. Доступные варианты и цены указаны на странице товара.',
            $detail, $make, $model);

    }

    public function initialize(Product $product, ProductOptionTemplate $template, ?int $importRunId = null): void
    {
        if ((int) $product->product_option_template_id !== (int) $template->getKey()) {
            $this->fail('Новый импортный товар не связан с проверенным системным шаблоном опций.');
        }

        app(CatalogConfigurations::class)->synchronize($product, $importRunId !== null ? 'import:'.$importRunId : 'import');

        // Vehicle and CSV sources contain no verified product characteristics.
        // Manually entered characteristics are owned by the administrator.

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
