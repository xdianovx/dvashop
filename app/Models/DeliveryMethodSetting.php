<?php

namespace App\Models;

use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
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
    'price_mode',
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

            $rawMode = $setting->getAttributes()['price_mode'] ?? null;
            $mode = is_string($rawMode) ? DeliveryPriceMode::tryFrom($rawMode) : null;

            if ($mode === null) {
                throw ValidationException::withMessages([
                    'price_mode' => 'Выбран неизвестный режим стоимости доставки.',
                ]);
            }

            $price = round((float) $setting->base_price, 2);

            if ($mode === DeliveryPriceMode::Fixed && $price <= 0) {
                throw ValidationException::withMessages([
                    'base_price' => 'Для фиксированной доставки укажите стоимость больше нуля.',
                ]);
            }

            if ($mode !== DeliveryPriceMode::Fixed && $price !== 0.0) {
                throw ValidationException::withMessages([
                    'base_price' => 'Для бесплатной доставки и доставки по запросу стоимость должна быть равна нулю.',
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

    public function priceLabel(): string
    {
        return $this->price_mode->storefrontPriceText($this->base_price);
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

    /** @return Attribute<DeliveryPriceMode, DeliveryPriceMode|string> */
    protected function priceMode(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): DeliveryPriceMode {
                $mode = is_string($value) ? DeliveryPriceMode::tryFrom($value) : null;

                if ($mode === null) {
                    throw ValidationException::withMessages([
                        'price_mode' => 'Выбран неизвестный режим стоимости доставки.',
                    ]);
                }

                return $mode;
            },
            set: function (mixed $value): string {
                $rawMode = $value instanceof DeliveryPriceMode ? $value->value : $value;

                if (! is_string($rawMode) || DeliveryPriceMode::tryFrom($rawMode) === null) {
                    throw ValidationException::withMessages([
                        'price_mode' => 'Выбран неизвестный режим стоимости доставки.',
                    ]);
                }

                return $rawMode;
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
