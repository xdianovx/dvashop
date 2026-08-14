<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'singleton_key',
    'store_name',
    'phone_display',
    'phone_href',
    'phone_caption',
    'public_email',
    'order_notification_email',
    'inquiry_notification_email',
    'work_hours',
    'legal_name',
    'inn',
    'ogrn',
    'legal_address',
    'vk_url',
    'telegram_url',
    'footer_copyright',
    'footer_disclaimer',
])]
class ShopSetting extends Model
{
    public const SINGLETON_KEY = 'default';

    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            if (! $this->exists) {
                if (($this->singleton_key ?? self::SINGLETON_KEY) !== self::SINGLETON_KEY) {
                    throw ValidationException::withMessages([
                        'singleton_key' => 'Настройки магазина должны использовать системный ключ default.',
                    ]);
                }

                $this->singleton_key = self::SINGLETON_KEY;

                if (static::query()
                    ->where('singleton_key', self::SINGLETON_KEY)
                    ->lockForUpdate()
                    ->exists()) {
                    throw $this->duplicateSingletonException();
                }
            } elseif ($this->isDirty('singleton_key')) {
                throw ValidationException::withMessages([
                    'singleton_key' => 'Системный ключ настроек магазина нельзя изменять.',
                ]);
            }

            try {
                return parent::save($options);
            } catch (QueryException $exception) {
                if (static::query()->where('singleton_key', self::SINGLETON_KEY)->exists()) {
                    throw $this->duplicateSingletonException();
                }

                throw $exception;
            }
        });
    }

    public function delete(): ?bool
    {
        throw ValidationException::withMessages([
            'settings' => 'Единственную запись настроек магазина нельзя удалить.',
        ]);
    }

    public function forceDelete(): never
    {
        throw ValidationException::withMessages([
            'settings' => 'Единственную запись настроек магазина нельзя удалить безвозвратно.',
        ]);
    }

    private function duplicateSingletonException(): ValidationException
    {
        return ValidationException::withMessages([
            'singleton_key' => 'Настройки магазина уже существуют. Создание второй записи запрещено.',
        ]);
    }
}
