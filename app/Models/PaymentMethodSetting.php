<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Database\Factories\PaymentMethodSettingFactory;
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
    'is_active',
    'position',
])]
class PaymentMethodSetting extends Model
{
    /** @use HasFactory<PaymentMethodSettingFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $setting): void {
            $rawCode = $setting->getAttributes()['code'] ?? null;

            if (! is_string($rawCode) || PaymentMethod::tryFrom($rawCode) === null) {
                throw ValidationException::withMessages([
                    'code' => 'Выбран неизвестный способ оплаты.',
                ]);
            }

            if ($setting->exists && $setting->isDirty('code')) {
                throw ValidationException::withMessages([
                    'code' => 'Системный код способа оплаты нельзя изменять.',
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
            'payment_method' => 'Настройку способа оплаты нельзя удалить.',
        ]);
    }

    public function forceDelete(): never
    {
        throw ValidationException::withMessages([
            'payment_method' => 'Настройку способа оплаты нельзя удалить безвозвратно.',
        ]);
    }

    public function replicate(?array $except = null)
    {
        throw ValidationException::withMessages([
            'payment_method' => 'Настройку способа оплаты нельзя копировать.',
        ]);
    }

    /** @return Attribute<PaymentMethod, PaymentMethod|string> */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): PaymentMethod {
                $code = is_string($value) ? PaymentMethod::tryFrom($value) : null;

                if ($code === null) {
                    throw ValidationException::withMessages([
                        'code' => 'Выбран неизвестный способ оплаты.',
                    ]);
                }

                return $code;
            },
            set: function (mixed $value): string {
                $rawCode = $value instanceof PaymentMethod ? $value->value : $value;

                if (! is_string($rawCode) || PaymentMethod::tryFrom($rawCode) === null) {
                    throw ValidationException::withMessages([
                        'code' => 'Выбран неизвестный способ оплаты.',
                    ]);
                }

                return $rawCode;
            },
        );
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
