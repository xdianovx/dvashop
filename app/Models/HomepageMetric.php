<?php

namespace App\Models;

use App\Enums\HomepageMetricCode;
use Database\Factories\HomepageMetricFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

#[Fillable(['code', 'prefix', 'value', 'suffix', 'text', 'is_active', 'position'])]
class HomepageMetric extends Model
{
    /** @use HasFactory<HomepageMetricFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $metric): void {
            $rawCode = $metric->getAttributes()['code'] ?? null;
            if (! is_string($rawCode) || HomepageMetricCode::tryFrom($rawCode) === null) {
                throw ValidationException::withMessages(['code' => 'Выбран неизвестный код показателя главной страницы.']);
            }
            if ($metric->exists && $metric->isDirty('code')) {
                throw ValidationException::withMessages(['code' => 'Системный код показателя нельзя изменять.']);
            }
        });
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
        throw ValidationException::withMessages(['homepage_metric' => 'Показатель главной страницы нельзя удалить.']);
    }

    public function forceDelete(): never
    {
        throw ValidationException::withMessages(['homepage_metric' => 'Показатель главной страницы нельзя удалить безвозвратно.']);
    }

    public function replicate(?array $except = null)
    {
        throw ValidationException::withMessages(['homepage_metric' => 'Показатель главной страницы нельзя копировать.']);
    }

    /** @return Attribute<HomepageMetricCode, HomepageMetricCode|string> */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): HomepageMetricCode {
                $code = is_string($value) ? HomepageMetricCode::tryFrom($value) : null;
                if ($code === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный код показателя главной страницы.']);
                }

                return $code;
            },
            set: function (mixed $value): string {
                $raw = $value instanceof HomepageMetricCode ? $value->value : $value;
                if (! is_string($raw) || HomepageMetricCode::tryFrom($raw) === null) {
                    throw ValidationException::withMessages(['code' => 'Выбран неизвестный код показателя главной страницы.']);
                }

                return $raw;
            },
        );
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'position' => 'integer'];
    }
}
