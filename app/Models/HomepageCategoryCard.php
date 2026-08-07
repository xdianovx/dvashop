<?php

namespace App\Models;

use App\Enums\HomepageCategoryCardCode;
use App\Enums\NavigationLinkType;
use Database\Factories\HomepageCategoryCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

#[Fillable([
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
])]
class HomepageCategoryCard extends Model
{
    /** @use HasFactory<HomepageCategoryCardFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $card): void {
            $rawCode = $card->getAttributes()['code'] ?? null;

            if (! is_string($rawCode) || HomepageCategoryCardCode::tryFrom($rawCode) === null) {
                throw ValidationException::withMessages(['code' => 'Выбран неизвестный код категории главной страницы.']);
            }

            if ($card->exists && $card->isDirty('code')) {
                throw ValidationException::withMessages(['code' => 'Системный код категории нельзя изменять.']);
            }

            $card->route_name = blank($card->route_name) ? null : trim((string) $card->route_name);
            $card->url = blank($card->url) ? null : trim((string) $card->url);

            $card->validateDestination();
        });
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class)->withTrashed();
    }

    public function partType(): BelongsTo
    {
        return $this->belongsTo(PartType::class)->withTrashed();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages(['homepage_category_card' => 'Карточку категории нельзя удалить.']);
    }

    public function forceDelete(): never
    {
        throw ValidationException::withMessages(['homepage_category_card' => 'Карточку категории нельзя удалить безвозвратно.']);
    }

    public function replicate(?array $except = null)
    {
        throw ValidationException::withMessages(['homepage_category_card' => 'Карточку категории нельзя копировать.']);
    }

    private function validateDestination(): void
    {
        $productCategoryId = $this->product_category_id;
        $partTypeId = $this->part_type_id;
        $linkType = $this->link_type;
        $routeName = $this->route_name;

        if ($productCategoryId !== null && $partTypeId !== null) {
            throw ValidationException::withMessages([
                'product_category_id' => 'Карточка не может одновременно вести в категорию магазина и тип детали.',
            ]);
        }

        if ($this->url !== null || $linkType === NavigationLinkType::Url) {
            throw ValidationException::withMessages(['url' => 'Внешние ссылки для витринных карточек не поддерживаются.']);
        }

        $hasRelation = $productCategoryId !== null || $partTypeId !== null;

        if ($hasRelation && ($linkType !== null || $routeName !== null)) {
            throw ValidationException::withMessages(['route_name' => 'Каталожная связь не может сочетаться с маршрутом.']);
        }

        if ($linkType !== null || $routeName !== null) {
            if ($linkType !== NavigationLinkType::Route || $routeName !== 'catalog.index' || ! Route::has('catalog.index')) {
                throw ValidationException::withMessages(['route_name' => 'Для витринной карточки разрешён только существующий маршрут всего каталога.']);
            }
        }

        if ($productCategoryId !== null) {
            $this->validateProductCategory($productCategoryId);
        }

        if ($partTypeId !== null) {
            $this->validatePartType($partTypeId);
        }

        $hasDestination = $hasRelation
            || ($linkType === NavigationLinkType::Route && $routeName === 'catalog.index');

        $this->url = null;
        $this->open_in_new_tab = false;

        if (! $hasDestination) {
            $this->link_type = null;
            $this->route_name = null;
            $this->is_active = false;
        }
    }

    private function validateProductCategory(int $productCategoryId): void
    {
        $category = ProductCategory::withTrashed()->find($productCategoryId);

        if (! $category instanceof ProductCategory) {
            throw ValidationException::withMessages(['product_category_id' => 'Категория магазина не найдена.']);
        }

        $sameAsCurrent = $this->exists
            && (int) $this->getRawOriginal('product_category_id') === $productCategoryId;

        if (($category->trashed() || ! $category->is_active) && ! $sameAsCurrent) {
            throw ValidationException::withMessages([
                'product_category_id' => 'Нельзя назначить неактивную или удалённую категорию магазина.',
            ]);
        }

        if ($category->trashed() || ! $category->is_active) {
            $this->is_active = false;
        }
    }

    private function validatePartType(int $partTypeId): void
    {
        $partType = PartType::withTrashed()->find($partTypeId);

        if (! $partType instanceof PartType) {
            throw ValidationException::withMessages(['part_type_id' => 'Тип детали не найден.']);
        }

        $sameAsCurrent = $this->exists
            && (int) $this->getRawOriginal('part_type_id') === $partTypeId;

        if (($partType->trashed() || ! $partType->is_active) && ! $sameAsCurrent) {
            throw ValidationException::withMessages([
                'part_type_id' => 'Нельзя назначить неактивный или удалённый тип детали.',
            ]);
        }

        if ($partType->trashed() || ! $partType->is_active) {
            $this->is_active = false;
        }
    }

    /** @return Attribute<HomepageCategoryCardCode, HomepageCategoryCardCode|string> */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): HomepageCategoryCardCode {
                $code = is_string($value) ? HomepageCategoryCardCode::tryFrom($value) : null;

                if ($code === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный код категории главной страницы.']);
                }

                return $code;
            },
            set: function (mixed $value): string {
                $raw = $value instanceof HomepageCategoryCardCode ? $value->value : $value;

                if (! is_string($raw) || HomepageCategoryCardCode::tryFrom($raw) === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный код категории главной страницы.']);
                }

                return $raw;
            },
        );
    }

    protected function casts(): array
    {
        return [
            'link_type' => NavigationLinkType::class,
            'product_category_id' => 'integer',
            'part_type_id' => 'integer',
            'open_in_new_tab' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
