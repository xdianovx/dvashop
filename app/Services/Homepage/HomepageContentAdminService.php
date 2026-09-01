<?php

namespace App\Services\Homepage;

use App\Enums\AdminPermission;
use App\Enums\HomepageCategoryCardCode;
use App\Enums\HomepageMetricCode;
use App\Enums\HomepageStoryMediaType;
use App\Enums\NavigationLinkType;
use App\Models\HomepageCategoryCard;
use App\Models\HomepageMetric;
use App\Models\HomepageSection;
use App\Models\HomepageStoryGroup;
use App\Models\HomepageStoryItem;
use App\Models\PartType;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Media\MediaFileCleanupService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class HomepageContentAdminService
{
    private const STORY_DIRECTORY = 'uploads/homepage/stories/';

    private const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const VIDEO_MIME_TYPES = ['video/mp4', 'video/webm'];

    public function __construct(private readonly MediaFileCleanupService $mediaCleanup) {}

    /** @param array<string, mixed> $attributes */
    public function updateSection(User $actor, HomepageSection $section, array $attributes): HomepageSection
    {
        /** @var HomepageSection $updated */
        $updated = $this->updateRecord(
            $actor,
            $section,
            $attributes,
            fn (array $data, Model $record): array => $this->validateSection($data, $record),
        );

        return $updated;
    }

    public function setSectionActive(User $actor, HomepageSection $section, bool $active): HomepageSection
    {
        /** @var HomepageSection $updated */
        $updated = $this->setActive($actor, $section, $active);

        return $updated;
    }

    public function saveStories(User $actor, mixed $rows): void
    {
        if (! $actor->canPerformAdminAction(AdminPermission::ManageHomepageContent)) {
            throw new AuthorizationException('Недостаточно прав для управления сторис главной страницы.');
        }

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw ValidationException::withMessages(['stories' => 'Передайте список кружков сторис.']);
        }

        $oldPaths = $this->persistedStoryMediaPaths();
        $submittedPaths = $this->storyMediaPathsFromRows($rows);

        try {
            DB::transaction(fn () => $this->syncStories($rows));
        } catch (Throwable $exception) {
            foreach (array_diff($submittedPaths, $oldPaths) as $path) {
                $this->mediaCleanup->deletePath($path);
            }

            throw $exception;
        }

        $newPaths = $this->persistedStoryMediaPaths();
        foreach (array_diff($oldPaths, $newPaths) as $path) {
            $this->mediaCleanup->deletePathAfterCommit($path);
        }
        foreach (array_diff($newPaths, $oldPaths) as $path) {
            $this->mediaCleanup->deletePathAfterRollback($path);
        }
    }

    /** @param array<string, mixed> $attributes */
    public function updateCategoryCard(User $actor, HomepageCategoryCard $card, array $attributes): HomepageCategoryCard
    {
        /** @var HomepageCategoryCard $updated */
        $updated = $this->updateRecord(
            $actor,
            $card,
            $attributes,
            fn (array $data, Model $record): array => $this->validateCategoryCard($data, $record),
        );

        return $updated;
    }

    public function setCategoryCardActive(User $actor, HomepageCategoryCard $card, bool $active): HomepageCategoryCard
    {
        return $this->updateCategoryCard($actor, $card, ['is_active' => $active]);
    }

    /** @param array<int|string, mixed> $ids */
    public function reorderCategoryCards(User $actor, array $ids): void
    {
        $this->reorder($actor, HomepageCategoryCard::class, $ids, 'карточки категорий');
    }

    /** @param array<string, mixed> $attributes */
    public function updateMetric(User $actor, HomepageMetric $metric, array $attributes): HomepageMetric
    {
        /** @var HomepageMetric $updated */
        $updated = $this->updateRecord(
            $actor,
            $metric,
            $attributes,
            fn (array $data, Model $record): array => $this->validateMetric($data, $record),
        );

        return $updated;
    }

    public function setMetricActive(User $actor, HomepageMetric $metric, bool $active): HomepageMetric
    {
        /** @var HomepageMetric $updated */
        $updated = $this->setActive($actor, $metric, $active);

        return $updated;
    }

    /** @param array<int|string, mixed> $ids */
    public function reorderMetrics(User $actor, array $ids): void
    {
        $this->reorder($actor, HomepageMetric::class, $ids, 'показатели главной страницы');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  Closure(array<string, mixed>, Model): array<string, mixed>  $validator
     */
    private function updateRecord(
        User $actor,
        Model $record,
        array $attributes,
        Closure $validator,
    ): Model {
        $this->authorize($actor, 'update', $record);

        return DB::transaction(function () use ($record, $attributes, $validator): Model {
            $locked = $record->newQuery()->whereKey($record)->lockForUpdate()->firstOrFail();
            $validated = $validator($attributes, $locked);
            $locked->fill($validated)->save();

            return $locked->refresh();
        });
    }

    private function setActive(User $actor, Model $record, bool $active): Model
    {
        $this->authorize($actor, 'update', $record);

        return DB::transaction(function () use ($record, $active): Model {
            $locked = $record->newQuery()->whereKey($record)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['is_active' => $active])->save();

            return $locked->refresh();
        });
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int|string, mixed>  $ids
     */
    private function reorder(User $actor, string $modelClass, array $ids, string $label): void
    {
        $this->authorize($actor, 'reorder', $modelClass);
        $validatedIds = $this->validateReorderIds($ids, $label);

        DB::transaction(function () use ($modelClass, $validatedIds, $label): void {
            $records = $modelClass::query()->orderBy('id')->lockForUpdate()->get();

            if ($records->pluck('id')->sort()->values()->all() !== collect($validatedIds)->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'ids' => "Сортировка должна содержать все существующие {$label} без пропусков и посторонних записей.",
                ]);
            }

            foreach ($validatedIds as $position => $id) {
                $modelClass::query()->whereKey($id)->update(['position' => $position]);
            }
        });
    }

    /** @param array<int|string, mixed> $ids
     * @return list<int>
     */
    private function validateReorderIds(array $ids, string $label): array
    {
        if ($ids === []) {
            throw ValidationException::withMessages(['ids' => "Передайте {$label} для сортировки."]);
        }

        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                throw ValidationException::withMessages(['ids' => 'Идентификаторы сортировки должны быть положительными целыми числами.']);
            }
        }

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['ids' => 'Список сортировки содержит повторяющиеся записи.']);
        }

        return array_values($ids);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validateSection(array $attributes, Model $record): array
    {
        $fields = ['title', 'is_active'];
        $this->rejectUnexpected($attributes, $fields, 'секции главной страницы');
        $candidate = array_merge($record->only($fields), $attributes);
        $candidate['title'] = $this->trimNullable($candidate['title'] ?? null);

        $validated = Validator::make($candidate, [
            'title' => ['nullable', 'string', 'max:255', $this->plainTextRule('Название секции')],
            'is_active' => ['required', 'boolean'],
        ], $this->messages())->validate();

        $validated['is_active'] = (bool) $validated['is_active'];

        return $validated;
    }

    /** @param list<mixed> $rows */
    private function syncStories(array $rows): void
    {
        $groups = HomepageStoryGroup::query()->with('items')->orderBy('id')->lockForUpdate()->get();
        $groupsById = $groups->keyBy(fn (HomepageStoryGroup $group): int => (int) $group->getKey());
        $seenGroupIds = [];
        $seenItemIds = [];

        foreach ($rows as $groupIndex => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(["stories.{$groupIndex}" => 'Данные кружка должны быть массивом.']);
            }

            unset($row['_label']);
            $this->rejectUnexpected($row, ['id', 'title', 'cover_image_path', 'is_active', 'items'], "stories.{$groupIndex}");
            $groupId = $this->nullableRecordId($row['id'] ?? null, "stories.{$groupIndex}.id");

            if ($groupId !== null) {
                if (in_array($groupId, $seenGroupIds, true)) {
                    throw ValidationException::withMessages(["stories.{$groupIndex}.id" => 'Кружок указан в форме несколько раз.']);
                }
                $group = $groupsById->get($groupId);
                if (! $group instanceof HomepageStoryGroup) {
                    throw ValidationException::withMessages(["stories.{$groupIndex}.id" => 'Кружок не существует.']);
                }
                $seenGroupIds[] = $groupId;
            } else {
                $group = new HomepageStoryGroup;
            }

            $groupData = Validator::make([
                'title' => $this->trimNullable($row['title'] ?? null),
                'cover_image_path' => $this->trimNullable($row['cover_image_path'] ?? null),
                'is_active' => $row['is_active'] ?? null,
            ], [
                'title' => ['required', 'string', 'max:255', $this->plainTextRule('Название кружка')],
                'cover_image_path' => ['nullable', 'string', 'max:1024'],
                'is_active' => ['required', 'boolean'],
            ], $this->messages())->validate();

            $groupData['is_active'] = (bool) $groupData['is_active'];
            if ($groupData['is_active'] && $groupData['cover_image_path'] === null) {
                throw ValidationException::withMessages(["stories.{$groupIndex}.cover_image_path" => 'Для показываемого кружка загрузите обложку.']);
            }
            if ($groupData['cover_image_path'] !== null) {
                $this->assertManagedMedia($groupData['cover_image_path'], HomepageStoryMediaType::Image, true, "stories.{$groupIndex}.cover_image_path");
            }

            $group->fill([...$groupData, 'position' => $groupIndex])->save();
            if ($groupId === null) {
                $seenGroupIds[] = (int) $group->getKey();
            }

            $this->syncStoryItems(
                group: $group,
                rows: $row['items'] ?? null,
                groupIndex: $groupIndex,
                seenItemIds: $seenItemIds,
            );
        }

        HomepageStoryGroup::query()->whereNotIn('id', $seenGroupIds)->delete();
    }

    /**
     * @param  list<int>  $seenItemIds
     */
    private function syncStoryItems(HomepageStoryGroup $group, mixed $rows, int $groupIndex, array &$seenItemIds): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw ValidationException::withMessages(["stories.{$groupIndex}.items" => 'Передайте список сторис кружка.']);
        }

        $items = $group->exists
            ? HomepageStoryItem::query()->where('homepage_story_group_id', $group->getKey())->orderBy('id')->lockForUpdate()->get()
            : collect();
        $itemsById = $items->keyBy(fn (HomepageStoryItem $item): int => (int) $item->getKey());
        $groupItemIds = [];

        foreach ($rows as $itemIndex => $row) {
            $path = "stories.{$groupIndex}.items.{$itemIndex}";
            if (! is_array($row)) {
                throw ValidationException::withMessages([$path => 'Данные сторис должны быть массивом.']);
            }

            unset($row['_label']);
            $this->rejectUnexpected($row, [
                'id', 'media_type', 'media_path', 'alt_text', 'cta_label', 'cta_url',
                'open_in_new_tab', 'duration_seconds', 'is_active',
            ], $path);
            $itemId = $this->nullableRecordId($row['id'] ?? null, "{$path}.id");

            if ($itemId !== null) {
                if (in_array($itemId, $seenItemIds, true)) {
                    throw ValidationException::withMessages(["{$path}.id" => 'Сторис указана в форме несколько раз.']);
                }
                $item = $itemsById->get($itemId);
                if (! $item instanceof HomepageStoryItem) {
                    throw ValidationException::withMessages(["{$path}.id" => 'Сторис не существует или относится к другому кружку.']);
                }
                $seenItemIds[] = $itemId;
            } else {
                $item = new HomepageStoryItem(['homepage_story_group_id' => $group->getKey()]);
            }

            $mediaType = $this->backedValue($row['media_type'] ?? null);
            $data = Validator::make([
                'media_type' => $mediaType,
                'media_path' => $this->trimNullable($row['media_path'] ?? null),
                'alt_text' => $this->trimNullable($row['alt_text'] ?? null),
                'cta_label' => $this->trimNullable($row['cta_label'] ?? null),
                'cta_url' => $this->trimNullable($row['cta_url'] ?? null),
                'open_in_new_tab' => $row['open_in_new_tab'] ?? false,
                'duration_seconds' => $row['duration_seconds'] ?? null,
                'is_active' => $row['is_active'] ?? null,
            ], [
                'media_type' => ['required', Rule::enum(HomepageStoryMediaType::class)],
                'media_path' => ['required', 'string', 'max:1024'],
                'alt_text' => ['nullable', 'string', 'max:255', $this->plainTextRule('Альтернативный текст')],
                'cta_label' => ['nullable', 'string', 'max:255', $this->plainTextRule('Текст кнопки')],
                'cta_url' => ['nullable', 'string', 'max:2048'],
                'open_in_new_tab' => ['required', 'boolean'],
                'duration_seconds' => ['nullable', 'integer', 'min:3', 'max:60'],
                'is_active' => ['required', 'boolean'],
            ], $this->messages())->validate();

            $type = HomepageStoryMediaType::from($data['media_type']);
            $this->assertManagedMedia($data['media_path'], $type, false, "{$path}.media_path");
            $data['cta_url'] = $this->safeCtaUrl($data['cta_url'], "{$path}.cta_url");
            $data['cta_label'] = $data['cta_url'] === null ? null : ($data['cta_label'] ?? 'Посмотреть');
            $data['open_in_new_tab'] = $data['cta_url'] !== null && (bool) $data['open_in_new_tab'];
            $data['duration_seconds'] = $type === HomepageStoryMediaType::Image
                ? (int) ($data['duration_seconds'] ?? 10)
                : null;
            $data['is_active'] = (bool) $data['is_active'];

            $item->fill([...$data, 'homepage_story_group_id' => $group->getKey(), 'position' => $itemIndex])->save();
            $groupItemIds[] = (int) $item->getKey();
            if ($itemId === null) {
                $seenItemIds[] = (int) $item->getKey();
            }
        }

        HomepageStoryItem::query()
            ->where('homepage_story_group_id', $group->getKey())
            ->whereNotIn('id', $groupItemIds)
            ->delete();
    }

    private function assertManagedMedia(string $path, HomepageStoryMediaType $type, bool $cover, string $field): void
    {
        $path = trim($path);
        if (! str_starts_with($path, self::STORY_DIRECTORY)
            || str_contains($path, '..')
            || str_contains($path, '\\')
            || str_starts_with($path, '/')) {
            throw ValidationException::withMessages([$field => 'Файл должен находиться в управляемом каталоге сторис.']);
        }

        $storage = Storage::disk('public');
        if (! $storage->exists($path)) {
            throw ValidationException::withMessages([$field => 'Загруженный файл не найден.']);
        }

        $mime = $storage->mimeType($path);
        $allowed = $type === HomepageStoryMediaType::Image ? self::IMAGE_MIME_TYPES : self::VIDEO_MIME_TYPES;
        $extensions = $type === HomepageStoryMediaType::Image ? ['jpg', 'jpeg', 'png', 'webp'] : ['mp4', 'webm'];
        $maxBytes = ($type === HomepageStoryMediaType::Image || $cover) ? 10 * 1024 * 1024 : 90 * 1024 * 1024;

        if (! is_string($mime) || ! in_array(mb_strtolower($mime), $allowed, true)
            || ! in_array(mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true)
            || $storage->size($path) > $maxBytes) {
            throw ValidationException::withMessages([$field => 'Файл имеет недопустимый тип или размер.']);
        }
    }

    private function safeCtaUrl(?string $url, string $field): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//') || str_contains($url, '\\')
            || preg_match('/[\x00-\x1F\x7F]/u', $url) === 1) {
            throw ValidationException::withMessages([$field => 'Укажите безопасную внутреннюю или http/https ссылку.']);
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (! in_array($scheme, ['http', 'https'], true) || blank(parse_url($url, PHP_URL_HOST))) {
                throw ValidationException::withMessages([$field => 'Разрешены только внутренние ссылки и абсолютные http/https URL.']);
            }
        }

        return $url;
    }

    private function nullableRecordId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw ValidationException::withMessages([$field => 'Идентификатор должен быть положительным целым числом.']);
    }

    /** @return list<string> */
    private function persistedStoryMediaPaths(): array
    {
        return HomepageStoryGroup::query()->pluck('cover_image_path')
            ->merge(HomepageStoryItem::query()->pluck('media_path'))
            ->filter(fn (mixed $path): bool => is_string($path) && str_starts_with($path, self::STORY_DIRECTORY))
            ->unique()
            ->values()
            ->all();
    }

    /** @param list<mixed> $rows
     * @return list<string>
     */
    private function storyMediaPathsFromRows(array $rows): array
    {
        return collect($rows)->flatMap(function (mixed $row): array {
            if (! is_array($row)) {
                return [];
            }

            $paths = [$row['cover_image_path'] ?? null];
            foreach (is_array($row['items'] ?? null) ? $row['items'] : [] as $item) {
                $paths[] = is_array($item) ? ($item['media_path'] ?? null) : null;
            }

            return $paths;
        })->filter(fn (mixed $path): bool => is_string($path) && str_starts_with($path, self::STORY_DIRECTORY))
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validateCategoryCard(array $attributes, Model $record): array
    {
        $fields = [
            'code',
            'title',
            'link_type',
            'route_name',
            'product_category_id',
            'part_type_id',
            'url',
            'open_in_new_tab',
            'is_active',
            'position',
        ];
        $this->rejectUnexpected($attributes, $fields, 'карточки категории');
        $candidate = array_merge($record->only($fields), $attributes);
        $candidate['code'] = $this->backedValue($candidate['code'] ?? null);
        $candidate['link_type'] = $this->backedValue($candidate['link_type'] ?? null);
        $candidate['title'] = $this->trimNullable($candidate['title'] ?? null);
        $candidate['route_name'] = $this->trimNullable($candidate['route_name'] ?? null);
        $candidate['url'] = $this->trimNullable($candidate['url'] ?? null);
        $candidate['product_category_id'] = $this->nullablePositiveId($candidate['product_category_id'] ?? null, 'product_category_id');
        $candidate['part_type_id'] = $this->nullablePositiveId($candidate['part_type_id'] ?? null, 'part_type_id');
        $this->ensureCodeUnchanged($candidate['code'] ?? null, $record, 'карточки категории');

        $validated = Validator::make($candidate, [
            'code' => ['required', Rule::enum(HomepageCategoryCardCode::class)],
            'title' => ['required', 'string', 'max:255', $this->plainTextRule('Название')],
            'link_type' => ['nullable', Rule::enum(NavigationLinkType::class)],
            'route_name' => ['nullable', 'string', 'max:255'],
            'product_category_id' => ['nullable', 'integer', 'min:1'],
            'part_type_id' => ['nullable', 'integer', 'min:1'],
            'url' => ['nullable', 'string', 'max:2048'],
            'open_in_new_tab' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'position' => ['required', 'integer', 'min:0'],
        ], $this->messages())->validate();

        if ($validated['product_category_id'] !== null && $validated['part_type_id'] !== null) {
            throw ValidationException::withMessages([
                'product_category_id' => 'Карточка не может одновременно вести в категорию магазина и тип детали.',
            ]);
        }

        if (($validated['url'] ?? null) !== null || ($validated['link_type'] ?? null) === NavigationLinkType::Url->value) {
            throw ValidationException::withMessages(['url' => 'Внешние ссылки для витринных карточек не поддерживаются.']);
        }

        $hasRelation = $validated['product_category_id'] !== null || $validated['part_type_id'] !== null;
        if ($hasRelation && (($validated['link_type'] ?? null) !== null || ($validated['route_name'] ?? null) !== null)) {
            throw ValidationException::withMessages(['route_name' => 'Каталожная связь не может сочетаться с маршрутом.']);
        }

        if (($validated['link_type'] ?? null) !== null || ($validated['route_name'] ?? null) !== null) {
            if (($validated['link_type'] ?? null) !== NavigationLinkType::Route->value
                || ($validated['route_name'] ?? null) !== 'catalog.index'
                || ! Route::has('catalog.index')) {
                throw ValidationException::withMessages(['route_name' => 'Для витринной карточки разрешён только существующий маршрут всего каталога.']);
            }
        }

        if ($validated['product_category_id'] !== null) {
            $category = ProductCategory::withTrashed()->find($validated['product_category_id']);
            if (! $category instanceof ProductCategory) {
                throw ValidationException::withMessages(['product_category_id' => 'Категория магазина не найдена.']);
            }
            $same = (int) $record->getRawOriginal('product_category_id') === (int) $category->getKey();
            if (($category->trashed() || ! $category->is_active) && ! $same) {
                throw ValidationException::withMessages(['product_category_id' => 'Нельзя назначить неактивную или удалённую категорию магазина.']);
            }
            if ($category->trashed() || ! $category->is_active) {
                $validated['is_active'] = false;
            }
        }

        if ($validated['part_type_id'] !== null) {
            $partType = PartType::withTrashed()->find($validated['part_type_id']);
            if (! $partType instanceof PartType) {
                throw ValidationException::withMessages(['part_type_id' => 'Тип детали не найден.']);
            }
            $same = (int) $record->getRawOriginal('part_type_id') === (int) $partType->getKey();
            if (($partType->trashed() || ! $partType->is_active) && ! $same) {
                throw ValidationException::withMessages(['part_type_id' => 'Нельзя назначить неактивный или удалённый тип детали.']);
            }
            if ($partType->trashed() || ! $partType->is_active) {
                $validated['is_active'] = false;
            }
        }

        $hasDestination = $hasRelation
            || (($validated['link_type'] ?? null) === NavigationLinkType::Route->value
                && ($validated['route_name'] ?? null) === 'catalog.index');

        if (! $hasDestination) {
            $validated['link_type'] = null;
            $validated['route_name'] = null;
            $validated['is_active'] = false;
        }

        $validated['url'] = null;
        $validated['open_in_new_tab'] = false;
        $validated['is_active'] = (bool) $validated['is_active'];
        $validated['position'] = (int) $validated['position'];

        return $validated;
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validateMetric(array $attributes, Model $record): array
    {
        $fields = ['code', 'prefix', 'value', 'suffix', 'text', 'is_active', 'position'];
        $this->rejectUnexpected($attributes, $fields, 'показателя главной страницы');
        $candidate = array_merge($record->only($fields), $attributes);
        $candidate['code'] = $this->backedValue($candidate['code'] ?? null);

        foreach (['prefix', 'value', 'suffix', 'text'] as $field) {
            $candidate[$field] = $this->trimNullable($candidate[$field] ?? null);
        }

        $this->ensureCodeUnchanged($candidate['code'] ?? null, $record, 'показателя');
        $plainText = $this->plainTextRule('Текстовое поле');
        $validated = Validator::make($candidate, [
            'code' => ['required', Rule::enum(HomepageMetricCode::class)],
            'prefix' => ['nullable', 'string', 'max:32', $plainText],
            'value' => ['required', 'string', 'max:64', $plainText],
            'suffix' => ['nullable', 'string', 'max:64', $plainText],
            'text' => ['required', 'string', 'max:500', $plainText],
            'is_active' => ['required', 'boolean'],
            'position' => ['required', 'integer', 'min:0'],
        ], $this->messages())->validate();

        $validated['is_active'] = (bool) $validated['is_active'];
        $validated['position'] = (int) $validated['position'];

        return $validated;
    }

    /** @param array<string, mixed> $attributes
     * @param  list<string>  $allowed
     */
    private function rejectUnexpected(array $attributes, array $allowed, string $entity): void
    {
        $unexpected = array_values(array_diff(array_keys($attributes), $allowed));
        if ($unexpected !== []) {
            throw ValidationException::withMessages(collect($unexpected)
                ->mapWithKeys(fn (string $field): array => [$field => "Поле «{$field}» нельзя изменять в {$entity}."])
                ->all());
        }
    }

    private function ensureCodeUnchanged(mixed $candidate, Model $record, string $entity): void
    {
        if ($candidate !== $record->getRawOriginal('code')) {
            throw ValidationException::withMessages(['code' => "Системный код {$entity} нельзя изменять."]);
        }
    }

    private function backedValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    private function trimNullable(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nullablePositiveId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw ValidationException::withMessages([$field => 'Выберите существующую запись.']);
    }

    private function plainTextRule(string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($label): void {
            if (is_string($value) && strip_tags($value) !== $value) {
                $fail("{$label} должно содержать обычный текст без HTML.");
            }
        };
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'required' => 'Поле «:attribute» обязательно.',
            'string' => 'Поле «:attribute» должно быть строкой.',
            'max' => 'Поле «:attribute» слишком длинное.',
            'integer' => 'Поле «:attribute» должно быть целым числом.',
            'min' => 'Поле «:attribute» не может быть отрицательным.',
            'boolean' => 'Поле «:attribute» должно быть логическим значением.',
            'enum' => 'Для поля «:attribute» выбрано недопустимое значение.',
        ];
    }

    private function authorize(User $actor, string $ability, mixed $target): void
    {
        if (! $actor->can($ability, $target)) {
            throw new AuthorizationException('Недостаточно прав для управления содержимым главной страницы.');
        }
    }
}
