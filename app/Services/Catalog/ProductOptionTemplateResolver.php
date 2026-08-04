<?php

namespace App\Services\Catalog;

use App\Enums\ProductType;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;

final class ProductOptionTemplateResolver
{
    public function resolveDefaultForAutoPart(?int $partTypeId): ?ProductOptionTemplate
    {
        if ($partTypeId !== null) {
            $specific = $this->activeDefault(
                ProductOptionGroup::APPLIES_AUTO_PART,
                $partTypeId,
            );

            if ($specific instanceof ProductOptionTemplate) {
                return $specific;
            }
        }

        $autoPart = $this->activeDefault(ProductOptionGroup::APPLIES_AUTO_PART, null);

        if ($autoPart instanceof ProductOptionTemplate) {
            return $autoPart;
        }

        $all = $this->activeDefault(ProductOptionGroup::APPLIES_ALL, null);

        if ($all instanceof ProductOptionTemplate) {
            return $all;
        }

        $legacy = ProductOptionTemplate::query()
            ->where('slug', 'default_auto_part')
            ->where('is_active', true)
            ->first();

        return $legacy instanceof ProductOptionTemplate
            && $this->isCompatible($legacy, ProductType::AutoPart, $partTypeId)
                ? $legacy
                : null;
    }

    public function isCompatible(
        ProductOptionTemplate $template,
        ProductType|string|null $productType,
        ?int $partTypeId,
    ): bool {
        $type = $productType instanceof ProductType ? $productType->value : (string) $productType;

        if ($type === ProductType::AutoPart->value) {
            return in_array($template->applies_to, [
                ProductOptionGroup::APPLIES_ALL,
                ProductOptionGroup::APPLIES_AUTO_PART,
            ], true) && ($template->part_type_id === null || (int) $template->part_type_id === $partTypeId);
        }

        if ($type === ProductType::Generic->value) {
            return in_array($template->applies_to, [
                ProductOptionGroup::APPLIES_ALL,
                ProductOptionGroup::APPLIES_GENERIC,
            ], true) && $template->part_type_id === null;
        }

        return false;
    }

    private function activeDefault(string $appliesTo, ?int $partTypeId): ?ProductOptionTemplate
    {
        return ProductOptionTemplate::query()
            ->where('applies_to', $appliesTo)
            ->when(
                $partTypeId === null,
                fn ($query) => $query->whereNull('part_type_id'),
                fn ($query) => $query->where('part_type_id', $partTypeId),
            )
            ->where('is_default', true)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }
}
