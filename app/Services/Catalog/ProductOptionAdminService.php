<?php

namespace App\Services\Catalog;

use App\Enums\AdminPermission;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionTemplateItem;
use App\Models\ProductOptionValue;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProductOptionAdminService
{
    public function __construct(
        private readonly ProductOptionCombinationCalculator $combinationCalculator,
        private readonly ProductOptionTemplateResolver $templateResolver,
    ) {}

    /** @param array<string, mixed> $data */
    public function createGroup(User $actor, array $data): ProductOptionGroup
    {
        $this->authorize($actor, AdminPermission::CreateCatalog);

        return DB::transaction(fn (): ProductOptionGroup => ProductOptionGroup::query()->create(
            $this->validatedGroupData($data),
        ));
    }

    /** @param array<string, mixed> $data */
    public function updateGroup(User $actor, ProductOptionGroup $group, array $data): ProductOptionGroup
    {
        $this->authorize($actor, AdminPermission::UpdateCatalog);

        return DB::transaction(function () use ($group, $data): ProductOptionGroup {
            $locked = ProductOptionGroup::query()->lockForUpdate()->findOrFail($group->getKey());
            $validated = $this->validatedGroupData($data, $locked);

            if ($this->keysChanged($locked, $validated) && $this->groupIsUsed($locked)) {
                throw ValidationException::withMessages([
                    'slug' => 'Нельзя изменить slug или code используемой группы опций.',
                ]);
            }

            $this->ensureGroupScopeCompatibleWithTemplates($locked, $validated['applies_to']);
            $locked->update($validated);

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function createValue(User $actor, ProductOptionGroup $group, array $data): ProductOptionValue
    {
        $this->authorize($actor, AdminPermission::CreateCatalog);

        return DB::transaction(function () use ($group, $data): ProductOptionValue {
            $lockedGroup = ProductOptionGroup::query()->lockForUpdate()->findOrFail($group->getKey());
            $validated = $this->validatedValueData($data, $lockedGroup);
            $this->ensureSingleDefaultValue($lockedGroup, $validated);

            return $lockedGroup->values()->create($validated);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateValue(User $actor, ProductOptionValue $value, array $data): ProductOptionValue
    {
        $this->authorize($actor, AdminPermission::UpdateCatalog);
        $groupId = (int) $value->product_option_group_id;

        return DB::transaction(function () use ($value, $data, $groupId): ProductOptionValue {
            $group = ProductOptionGroup::query()->lockForUpdate()->findOrFail($groupId);
            $locked = ProductOptionValue::query()->lockForUpdate()->findOrFail($value->getKey());

            if (isset($data['product_option_group_id'])
                && (int) $data['product_option_group_id'] !== (int) $locked->product_option_group_id) {
                throw ValidationException::withMessages([
                    'product_option_group_id' => 'Нельзя перенести значение в другую группу опций.',
                ]);
            }

            if ((int) $locked->product_option_group_id !== (int) $group->getKey()) {
                throw ValidationException::withMessages([
                    'product_option_group_id' => 'Группа значения была изменена. Повторите операцию.',
                ]);
            }

            $validated = $this->validatedValueData($data, $group, $locked);
            $this->ensureSingleDefaultValue($group, $validated, $locked);

            if ($this->keysChanged($locked, $validated) && $this->valueIsUsed($locked)) {
                throw ValidationException::withMessages([
                    'slug' => 'Нельзя изменить slug или code используемого значения опции.',
                ]);
            }

            $locked->update($validated);

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createTemplate(User $actor, array $data, array $items): ProductOptionTemplate
    {
        $this->authorize($actor, AdminPermission::CreateCatalog);

        return DB::transaction(function () use ($data, $items): ProductOptionTemplate {
            $validated = $this->validatedTemplateData($data);
            $validatedItems = $this->validatedTemplateItems($items, $validated['applies_to']);
            $this->ensureCombinationLimit($validated, $validatedItems);
            $this->clearOtherDefaultTemplates($validated);

            $template = ProductOptionTemplate::query()->create($validated);
            $this->replaceTemplateItems($template, $validatedItems);

            return $template->refresh()->load('items');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function updateTemplate(
        User $actor,
        ProductOptionTemplate $template,
        array $data,
        array $items,
    ): ProductOptionTemplate {
        $this->authorize($actor, AdminPermission::UpdateCatalog);

        return DB::transaction(function () use ($template, $data, $items): ProductOptionTemplate {
            $locked = ProductOptionTemplate::query()->lockForUpdate()->findOrFail($template->getKey());
            $validated = $this->validatedTemplateData($data, $locked);
            $validatedItems = $this->validatedTemplateItems($items, $validated['applies_to'], $locked);
            $this->ensureCombinationLimit($validated, $validatedItems);
            $this->ensureTemplateScopeCompatibleWithProducts($locked, $validated);
            $this->clearOtherDefaultTemplates($validated, $locked);

            $locked->update($validated);
            $this->replaceTemplateItems($locked, $validatedItems);

            return $locked->refresh()->load('items');
        });
    }

    public function setGroupActive(User $actor, ProductOptionGroup $group, bool $isActive): ProductOptionGroup
    {
        return $this->updateGroup($actor, $group, [...$group->attributesToArray(), 'is_active' => $isActive]);
    }

    public function setValueActive(User $actor, ProductOptionValue $value, bool $isActive): ProductOptionValue
    {
        return $this->updateValue($actor, $value, [...$value->attributesToArray(), 'is_active' => $isActive]);
    }

    public function setTemplateActive(User $actor, ProductOptionTemplate $template, bool $isActive): ProductOptionTemplate
    {
        $this->authorize($actor, AdminPermission::UpdateCatalog);

        return DB::transaction(function () use ($template, $isActive): ProductOptionTemplate {
            $locked = ProductOptionTemplate::query()->lockForUpdate()->findOrFail($template->getKey());

            if ($isActive) {
                $this->ensureCombinationLimit(
                    ['is_active' => true],
                    $locked->items()->get()->map->attributesToArray()->all(),
                );
            }

            if (! $isActive && $locked->is_default) {
                throw ValidationException::withMessages([
                    'is_active' => 'Шаблон по умолчанию должен оставаться активным. Сначала снимите признак «По умолчанию».',
                ]);
            }

            $locked->forceFill(['is_active' => $isActive])->save();

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    private function validatedGroupData(array $data, ?ProductOptionGroup $group = null): array
    {
        return Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('product_option_groups', 'slug')->ignore($group)],
            'code' => ['nullable', 'string', 'max:255', Rule::unique('product_option_groups', 'code')->ignore($group)],
            'description' => ['nullable', 'string'],
            'input_type' => ['required', Rule::in(['radio', 'select'])],
            'applies_to' => ['required', Rule::in([
                ProductOptionGroup::APPLIES_ALL,
                ProductOptionGroup::APPLIES_AUTO_PART,
                ProductOptionGroup::APPLIES_GENERIC,
            ])],
            'is_required' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'position' => ['required', 'integer', 'min:0'],
        ], $this->messages())->validate();
    }

    /** @param array<string, mixed> $data */
    private function validatedValueData(
        array $data,
        ProductOptionGroup $group,
        ?ProductOptionValue $value = null,
    ): array {
        $data['product_option_group_id'] = $group->getKey();

        return Arr::except(Validator::make($data, [
            'product_option_group_id' => ['required', 'integer', Rule::in([(int) $group->getKey()])],
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_option_values', 'slug')
                    ->where('product_option_group_id', $group->getKey())
                    ->ignore($value),
            ],
            'code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('product_option_values', 'code')
                    ->where('product_option_group_id', $group->getKey())
                    ->ignore($value),
            ],
            'description' => ['nullable', 'string'],
            'is_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'position' => ['required', 'integer', 'min:0'],
        ], $this->messages())->validate(), ['product_option_group_id']);
    }

    /** @param array<string, mixed> $data */
    private function validatedTemplateData(array $data, ?ProductOptionTemplate $template = null): array
    {
        $validated = Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('product_option_templates', 'slug')->ignore($template)],
            'applies_to' => ['required', Rule::in([
                ProductOptionGroup::APPLIES_ALL,
                ProductOptionGroup::APPLIES_AUTO_PART,
                ProductOptionGroup::APPLIES_GENERIC,
            ])],
            'part_type_id' => ['nullable', 'integer', Rule::exists('part_types', 'id')],
            'is_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'position' => ['required', 'integer', 'min:0'],
        ], $this->messages())->validate();

        $validated['part_type_id'] = filled($validated['part_type_id'] ?? null)
            ? (int) $validated['part_type_id']
            : null;

        if ($validated['part_type_id'] !== null
            && $validated['applies_to'] !== ProductOptionGroup::APPLIES_AUTO_PART) {
            throw ValidationException::withMessages([
                'part_type_id' => 'Тип детали можно указать только для шаблона автодеталей.',
            ]);
        }

        if ($validated['is_default'] && ! $validated['is_active']) {
            throw ValidationException::withMessages([
                'is_default' => 'Шаблон по умолчанию должен быть активным.',
            ]);
        }

        return $validated;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{product_option_group_id:int, product_option_value_id:int, position:int}>
     */
    private function validatedTemplateItems(
        array $items,
        string $appliesTo,
        ?ProductOptionTemplate $template = null,
    ): array {
        $validated = Validator::make(['items' => array_values($items)], [
            'items' => ['array'],
            'items.*.product_option_group_id' => ['required', 'integer', Rule::exists('product_option_groups', 'id')],
            'items.*.product_option_value_id' => ['required', 'integer', Rule::exists('product_option_values', 'id')],
            'items.*.position' => ['required', 'integer', 'min:0'],
        ], $this->messages())->validate()['items'] ?? [];
        $seen = [];
        $existingPairs = $template?->items()
            ->get(['product_option_group_id', 'product_option_value_id'])
            ->mapWithKeys(fn (ProductOptionTemplateItem $item): array => [
                ((int) $item->product_option_group_id).':'.((int) $item->product_option_value_id) => true,
            ])
            ->all() ?? [];

        foreach ($validated as $index => &$item) {
            $groupId = (int) $item['product_option_group_id'];
            $valueId = (int) $item['product_option_value_id'];
            $group = ProductOptionGroup::query()->findOrFail($groupId);
            $value = ProductOptionValue::query()->findOrFail($valueId);

            if ((int) $value->product_option_group_id !== $groupId) {
                throw ValidationException::withMessages([
                    "template_items.{$index}.product_option_value_id" => 'Значение не принадлежит выбранной группе опций.',
                ]);
            }

            if (! in_array($group->applies_to, [ProductOptionGroup::APPLIES_ALL, $appliesTo], true)) {
                throw ValidationException::withMessages([
                    "template_items.{$index}.product_option_group_id" => 'Группа несовместима с областью применения шаблона.',
                ]);
            }

            if ($value->is_default) {
                $defaultKey = 'default:'.$groupId;

                if (isset($seen[$defaultKey])) {
                    throw ValidationException::withMessages([
                        "template_items.{$index}.product_option_value_id" => 'В одной группе шаблона может быть только одно значение по умолчанию.',
                    ]);
                }

                $seen[$defaultKey] = true;
            }

            $pair = $groupId.':'.$valueId;

            if ((! $group->is_active || ! $value->is_active) && ! isset($existingPairs[$pair])) {
                throw ValidationException::withMessages([
                    "template_items.{$index}.product_option_value_id" => 'В новый элемент шаблона можно добавить только активную группу и активное значение.',
                ]);
            }

            if (isset($seen[$pair])) {
                throw ValidationException::withMessages([
                    "template_items.{$index}.product_option_value_id" => 'Значение уже добавлено в шаблон.',
                ]);
            }

            $seen[$pair] = true;
            $item = [
                'product_option_group_id' => $groupId,
                'product_option_value_id' => $valueId,
                'position' => (int) $item['position'],
            ];
        }
        unset($item);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $templateData
     * @param  array<int, array<string, mixed>>  $items
     */
    private function ensureCombinationLimit(array $templateData, array $items): void
    {
        if (! ($templateData['is_active'] ?? false)) {
            return;
        }

        $combinationCount = $this->combinationCalculator->countForItems($items);

        if ($combinationCount === 0) {
            throw ValidationException::withMessages([
                'template_items' => 'Активный шаблон должен содержать хотя бы одну активную группу и одно активное значение.',
            ]);
        }

        if ($combinationCount > ProductOptionCombinationCalculator::MAX_COMBINATIONS) {
            throw ValidationException::withMessages([
                'template_items' => 'Активный шаблон не может содержать более '
                    .ProductOptionCombinationCalculator::MAX_COMBINATIONS.' комбинаций.',
            ]);
        }
    }

    /** @param array<int, array<string, mixed>> $items */
    private function replaceTemplateItems(ProductOptionTemplate $template, array $items): void
    {
        $template->items()->delete();

        foreach ($items as $item) {
            $template->items()->create($item);
        }
    }

    /** @param array<string, mixed> $data */
    private function keysChanged(ProductOptionGroup|ProductOptionValue $record, array $data): bool
    {
        return (string) $record->slug !== (string) ($data['slug'] ?? $record->slug)
            || (string) ($record->code ?? '') !== (string) ($data['code'] ?? '');
    }

    private function groupIsUsed(ProductOptionGroup $group): bool
    {
        return $group->templateItems()->exists() || $group->variantOptionValues()->exists();
    }

    private function valueIsUsed(ProductOptionValue $value): bool
    {
        return $value->templateItems()->exists() || $value->variantOptionValues()->exists();
    }

    private function ensureGroupScopeCompatibleWithTemplates(ProductOptionGroup $group, string $appliesTo): void
    {
        if ($group->applies_to === $appliesTo || $appliesTo === ProductOptionGroup::APPLIES_ALL) {
            return;
        }

        $hasConflict = ProductOptionTemplate::query()
            ->whereHas('items', fn ($items) => $items->where('product_option_group_id', $group->getKey()))
            ->where('applies_to', '!=', $appliesTo)
            ->exists();

        if ($hasConflict) {
            throw ValidationException::withMessages([
                'applies_to' => 'Нельзя изменить область применения: группа используется несовместимыми шаблонами.',
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function ensureTemplateScopeCompatibleWithProducts(
        ProductOptionTemplate $template,
        array $data,
    ): void {
        if ((string) $template->applies_to === (string) $data['applies_to']
            && (int) ($template->part_type_id ?? 0) === (int) ($data['part_type_id'] ?? 0)) {
            return;
        }

        $candidate = $template->replicate()->forceFill($data);
        $products = $template->products()
            ->lockForUpdate()
            ->get(['id', 'product_type', 'part_type_id']);

        foreach ($products as $product) {
            if (! $this->templateResolver->isCompatible(
                $candidate,
                $product->product_type,
                $product->part_type_id === null ? null : (int) $product->part_type_id,
            )) {
                throw ValidationException::withMessages([
                    'applies_to' => 'Нельзя изменить область применения или тип детали: шаблон назначен несовместимым товаром.',
                ]);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function clearOtherDefaultTemplates(
        array $data,
        ?ProductOptionTemplate $template = null,
    ): void {
        if (! $data['is_default']) {
            return;
        }

        $scope = ProductOptionTemplate::query()
            ->where('applies_to', $data['applies_to'])
            ->when(
                $data['part_type_id'] === null,
                fn ($query) => $query->whereNull('part_type_id'),
                fn ($query) => $query->where('part_type_id', $data['part_type_id']),
            )
            ->when($template, fn ($query) => $query->whereKeyNot($template->getKey()));

        $scope->lockForUpdate()->get();
        $scope->where('is_default', true)->update(['is_default' => false]);
    }

    /** @param array<string, mixed> $data */
    private function ensureSingleDefaultValue(
        ProductOptionGroup $group,
        array $data,
        ?ProductOptionValue $value = null,
    ): void {
        if (! ($data['is_default'] ?? false)) {
            return;
        }

        $hasAnotherDefault = $group->values()
            ->where('is_default', true)
            ->when($value, fn ($query) => $query->where('id', '!=', $value->getKey()))
            ->exists();

        if ($hasAnotherDefault) {
            throw ValidationException::withMessages([
                'is_default' => 'В группе может быть только одно значение по умолчанию.',
            ]);
        }
    }

    private function authorize(User $actor, AdminPermission $permission): void
    {
        if (! $actor->canPerformAdminAction($permission)) {
            throw new AuthorizationException('Недостаточно прав для управления опциями товаров.');
        }
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'required' => 'Поле «:attribute» обязательно.',
            'unique' => 'Такое значение поля «:attribute» уже используется.',
            'exists' => 'Выбранное значение поля «:attribute» не существует.',
            'integer' => 'Поле «:attribute» должно быть целым числом.',
            'min' => 'Поле «:attribute» не может быть отрицательным.',
            'in' => 'Выбрано недопустимое значение поля «:attribute».',
        ];
    }
}
