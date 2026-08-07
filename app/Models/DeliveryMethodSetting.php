<?php

namespace App\Models;

use App\Enums\DeliveryMethod;
use Database\Factories\DeliveryMethodSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'code',
    'title',
    'description',
    'page_title',
    'page_description',
    'base_price',
    'is_active',
    'position',
])]
class DeliveryMethodSetting extends Model
{
    /** @use HasFactory<DeliveryMethodSettingFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $setting): void {
            $rawCode = $setting->getAttributes()['code'] ?? null;

            if (! is_string($rawCode) || DeliveryMethod::tryFrom($rawCode) === null) {
                throw ValidationException::withMessages([
                    'code' => 'Выбран неизвестный способ доставки.',
                ]);
            }

            if ($setting->exists && $setting->isDirty('code')) {
                throw ValidationException::withMessages([
                    'code' => 'Системный код способа доставки нельзя изменять.',
                ]);
            }
        });
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages([
            'delivery_method' => 'Настройку способа доставки нельзя удалить.',
        ]);
    }

    public function forceDelete(): never
    {
        throw ValidationException::withMessages([
            'delivery_method' => 'Настройку способа доставки нельзя удалить безвозвратно.',
        ]);
    }

    public function replicate(?array $except = null)
    {
        throw ValidationException::withMessages([
            'delivery_method' => 'Настройку способа доставки нельзя копировать.',
        ]);
    }

    /** @return Attribute<DeliveryMethod, DeliveryMethod|string> */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): DeliveryMethod {
                $code = is_string($value) ? DeliveryMethod::tryFrom($value) : null;

                if ($code === null) {
                    throw ValidationException::withMessages([
                        'code' => 'Выбран неизвестный способ доставки.',
                    ]);
                }

                return $code;
            },
            set: function (mixed $value): string {
                $rawCode = $value instanceof DeliveryMethod ? $value->value : $value;

                if (! is_string($rawCode) || DeliveryMethod::tryFrom($rawCode) === null) {
                    throw ValidationException::withMessages([
                        'code' => 'Выбран неизвестный способ доставки.',
                    ]);
                }

                return $rawCode;
            },
        );
    }

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
