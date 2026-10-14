<?php

namespace App\Services\Catalog;

use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionTemplateItem;
use App\Models\ProductOptionValue;
use Illuminate\Support\Collection;
use LogicException;

final class DefaultAutoPartOptionTemplateContract
{
    public const TEMPLATE_SLUG = 'default_auto_part';

    public const EXPECTED_VALUE_COUNT = 9;

    public const EXPECTED_COMBINATION_COUNT = 24;

    /**
     * Canonical membership of the system template. Membership and runtime
     * active state are intentionally separate concerns: seeders may restore
     * membership without overriding an administrator's is_active choice,
     * while imports require the whole canonical set to be active.
     *
     * @var array<string, array<int, string>>
     */
    public const OPTION_CONTRACT = [
        'profile' => ['full', 'lower'],
        'position' => ['left', 'right', 'both'],
        'material' => ['galvanized', 'cold_rolled'],
        'thickness' => ['1mm', '1_5mm'],
    ];

    public function __construct(
        private readonly ProductOptionCombinationCalculator $combinationCalculator,
    ) {}

    public function assertCompatibleTemplate(ProductOptionTemplate $template): void
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

    public function assertReadyForImport(ProductOptionTemplate $template): void
    {
        $this->assertCompatibleTemplate($template);

        if (! $template->is_active) {
            throw new LogicException('Системный шаблон опций «default_auto_part» неактивен.');
        }

        $items = $this->loadedItems($template);
        $actual = $this->membership($items);
        $expected = $this->normalizedContract();

        if ($items->count() !== self::EXPECTED_VALUE_COUNT || $actual !== $expected) {
            throw new LogicException(
                'Системный шаблон «default_auto_part» должен содержать ровно 9 активных canonical selectable values '
                .'в 4 группах и не содержать legacy/лишних membership.',
            );
        }

        foreach ($items as $item) {
            $group = $item->group;
            $value = $item->value;

            if (! $group instanceof ProductOptionGroup || ! $value instanceof ProductOptionValue) {
                throw new LogicException('Системный шаблон содержит повреждённую связь группы или значения опции.');
            }

            if ((int) $value->product_option_group_id !== (int) $group->getKey()) {
                throw new LogicException('Системный шаблон содержит значение, принадлежащее другой группе опций.');
            }

            $groupCode = $this->recordCode($group->code, $group->slug);

            if (! in_array($group->applies_to, [
                ProductOptionGroup::APPLIES_ALL,
                ProductOptionGroup::APPLIES_AUTO_PART,
            ], true)) {
                throw new LogicException("Системная группа «{$groupCode}» несовместима с автодеталями.");
            }

            if (! $group->is_active || ! $value->is_active) {
                throw new LogicException(
                    'Все 9 canonical selectable values шаблона «default_auto_part» и их группы должны быть активны для импорта.',
                );
            }
        }

        if ($this->combinationCalculator->countForTemplate($template) !== self::EXPECTED_COMBINATION_COUNT) {
            throw new LogicException('Системный шаблон «default_auto_part» должен создавать ровно 24 комбинации.');
        }
    }

    /**
     * Remove only non-canonical membership from the system template. The
     * option values themselves are preserved, as are memberships in custom
     * templates. If a non-canonical value is used by any variant, stop rather
     * than silently changing a live option model.
     */
    public function normalizeMembership(ProductOptionTemplate $template): void
    {
        $this->assertCompatibleTemplate($template);

        foreach ($this->loadedItems($template) as $item) {
            $group = $item->group;
            $value = $item->value;

            if (! $group instanceof ProductOptionGroup || ! $value instanceof ProductOptionValue) {
                throw new LogicException('Системный шаблон содержит повреждённую связь группы или значения опции.');
            }

            if ($this->isCanonicalPair($group, $value)) {
                continue;
            }

            if ($value->variantOptionValues()->exists()) {
                $groupCode = $this->recordCode($group->code, $group->slug);
                $valueCode = $this->recordCode($value->code, $value->slug);

                throw new LogicException(
                    "Нельзя удалить legacy membership «{$groupCode}/{$valueCode}» из default_auto_part: значение используется вариантами товаров.",
                );
            }

            $item->delete();
        }

        $template->unsetRelation('items');
    }

    /** @return array<string, array<int, string>> */
    public function optionContract(): array
    {
        return self::OPTION_CONTRACT;
    }

    /** @param Collection<int, ProductOptionTemplateItem> $items */
    private function membership(Collection $items): array
    {
        $actual = [];

        foreach ($items as $item) {
            $group = $item->group;
            $value = $item->value;

            if (! $group instanceof ProductOptionGroup || ! $value instanceof ProductOptionValue) {
                throw new LogicException('Системный шаблон содержит повреждённую связь группы или значения опции.');
            }

            if ((int) $value->product_option_group_id !== (int) $group->getKey()) {
                throw new LogicException('Системный шаблон содержит значение, принадлежащее другой группе опций.');
            }

            $groupCode = $this->recordCode($group->code, $group->slug);
            $valueCode = $this->recordCode($value->code, $value->slug);
            $actual[$groupCode][] = $valueCode;
        }

        return $this->normalizeMap($actual);
    }

    /** @return array<string, array<int, string>> */
    private function normalizedContract(): array
    {
        return $this->normalizeMap(self::OPTION_CONTRACT);
    }

    /** @param array<string, array<int, string>> $map */
    private function normalizeMap(array $map): array
    {
        ksort($map);

        foreach ($map as &$values) {
            sort($values);
        }
        unset($values);

        return $map;
    }

    private function isCanonicalPair(ProductOptionGroup $group, ProductOptionValue $value): bool
    {
        $groupCode = $this->recordCode($group->code, $group->slug);
        $valueCode = $this->recordCode($value->code, $value->slug);

        return array_key_exists($groupCode, self::OPTION_CONTRACT)
            && in_array($valueCode, self::OPTION_CONTRACT[$groupCode], true);
    }

    /** @return Collection<int, ProductOptionTemplateItem> */
    private function loadedItems(ProductOptionTemplate $template): Collection
    {
        $template->load(['items.group', 'items.value']);

        return $template->items;
    }

    private function recordCode(?string $code, string $slug): string
    {
        $code = trim((string) $code);

        return $code !== '' ? $code : $slug;
    }
}
