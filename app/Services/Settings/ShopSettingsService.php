<?php

namespace App\Services\Settings;

use App\Enums\AdminPermission;
use App\Models\ShopSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ShopSettingsService
{
    /** @var list<string> */
    public const EDITABLE_FIELDS = [
        'store_name',
        'phone_display',
        'phone_href',
        'phone_caption',
        'public_email',
        'order_notification_email',
        'work_hours',
        'legal_name',
        'inn',
        'ogrn',
        'legal_address',
        'vk_url',
        'telegram_url',
        'footer_copyright',
        'footer_disclaimer',
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'singleton_key' => ShopSetting::SINGLETON_KEY,
            'store_name' => 'AVTOPOROGI.ru',
            'phone_display' => '8 800 100 56 25',
            'phone_href' => '+78001005625',
            'phone_caption' => 'Бесплатный звонок',
            'public_email' => null,
            'order_notification_email' => null,
            'work_hours' => null,
            'legal_name' => 'ООО «АРТ ГРУПП»',
            'inn' => '7814593546',
            'ogrn' => '1137847459936',
            'legal_address' => '192082, Россия, г. Санкт-Петербург, ул. Туристская, д. 23 к. 2',
            'vk_url' => null,
            'telegram_url' => null,
            'footer_copyright' => '© 2026 ООО «АРТ ГРУПП»',
            'footer_disclaimer' => 'Сайт не является офертой',
        ];
    }

    public function current(): ShopSetting
    {
        return DB::transaction(fn (): ShopSetting => $this->lockCurrentOrCreate());
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, array $attributes): ShopSetting
    {
        $this->authorize($actor, AdminPermission::UpdateStoreSettings);

        return DB::transaction(function () use ($attributes): ShopSetting {
            $setting = $this->lockCurrentOrCreate();
            $validated = $this->validatedAttributes($setting, $attributes);

            $setting->forceFill($validated)->save();

            return $setting->refresh();
        });
    }

    private function lockCurrentOrCreate(): ShopSetting
    {
        $setting = ShopSetting::query()
            ->where('singleton_key', ShopSetting::SINGLETON_KEY)
            ->lockForUpdate()
            ->first();

        if ($setting instanceof ShopSetting) {
            return $setting;
        }

        try {
            return ShopSetting::query()->create(self::defaults());
        } catch (ValidationException $exception) {
            $setting = ShopSetting::query()
                ->where('singleton_key', ShopSetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->first();

            if ($setting instanceof ShopSetting) {
                return $setting;
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedAttributes(ShopSetting $setting, array $attributes): array
    {
        $unexpected = array_values(array_diff(array_keys($attributes), self::EDITABLE_FIELDS));

        if ($unexpected !== []) {
            throw ValidationException::withMessages(collect($unexpected)
                ->mapWithKeys(fn (string $field): array => [
                    $field => "Поле «{$field}» нельзя изменять через настройки магазина.",
                ])
                ->all());
        }

        $candidate = array_merge($setting->only(self::EDITABLE_FIELDS), $attributes);

        foreach ($candidate as $field => $value) {
            if (is_string($value)) {
                $candidate[$field] = trim($value);
            }
        }

        foreach (['public_email', 'order_notification_email'] as $field) {
            if (is_string($candidate[$field] ?? null)) {
                $candidate[$field] = mb_strtolower($candidate[$field]);
            }
        }

        if (is_string($candidate['phone_href'] ?? null) && $candidate['phone_href'] !== '') {
            if (! preg_match('/^\+?[0-9\s()\-]+$/', $candidate['phone_href'])) {
                throw ValidationException::withMessages([
                    'phone_href' => 'Телефон для ссылки может содержать только ведущий +, цифры, пробелы, скобки и дефисы.',
                ]);
            }

            $candidate['phone_href'] = preg_replace('/[\s()\-]+/', '', $candidate['phone_href']);
        }

        foreach ($candidate as $field => $value) {
            if ($value === '') {
                $candidate[$field] = null;
            }
        }

        $plainText = function (string $attribute, mixed $value, callable $fail): void {
            if (is_string($value) && strip_tags($value) !== $value) {
                $fail('Поле «:attribute» должно содержать обычный текст без HTML.');
            }
        };
        $httpUrl = function (string $attribute, mixed $value, callable $fail): void {
            if ($value === null) {
                return;
            }

            if (! is_string($value)
                || filter_var($value, FILTER_VALIDATE_URL) === false
                || ! in_array(mb_strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
                || blank(parse_url($value, PHP_URL_HOST))) {
                $fail('Поле «:attribute» должно содержать абсолютный URL с протоколом http или https.');
            }
        };

        return Validator::make($candidate, [
            'store_name' => ['required', 'string', 'max:255', $plainText],
            'phone_display' => ['nullable', 'string', 'max:100', $plainText],
            'phone_href' => ['nullable', 'string', 'regex:/^\+?\d{10,15}$/'],
            'phone_caption' => ['nullable', 'string', 'max:255', $plainText],
            'public_email' => ['nullable', 'string', 'email:filter', 'max:255'],
            'order_notification_email' => ['nullable', 'string', 'email:filter', 'max:255'],
            'work_hours' => ['nullable', 'string', 'max:255', $plainText],
            'legal_name' => ['nullable', 'string', 'max:255', $plainText],
            'inn' => ['nullable', 'string', 'regex:/^(?:\d{10}|\d{12})$/'],
            'ogrn' => ['nullable', 'string', 'regex:/^(?:\d{13}|\d{15})$/'],
            'legal_address' => ['nullable', 'string', 'max:2000', $plainText],
            'vk_url' => ['nullable', 'string', 'max:255', $httpUrl],
            'telegram_url' => ['nullable', 'string', 'max:255', $httpUrl],
            'footer_copyright' => ['nullable', 'string', 'max:2000', $plainText],
            'footer_disclaimer' => ['nullable', 'string', 'max:500', $plainText],
        ], [
            'required' => 'Поле «:attribute» обязательно.',
            'string' => 'Поле «:attribute» должно быть строкой.',
            'max' => 'Поле «:attribute» слишком длинное.',
            'email' => 'Поле «:attribute» должно содержать корректный email.',
            'phone_href.regex' => 'Телефон для ссылки должен содержать от 10 до 15 цифр и может начинаться с +.',
            'inn.regex' => 'ИНН должен содержать ровно 10 или 12 цифр.',
            'ogrn.regex' => 'ОГРН должен содержать ровно 13 или 15 цифр.',
        ], [
            'store_name' => 'название магазина',
            'phone_display' => 'отображаемый телефон',
            'phone_href' => 'телефон для ссылки',
            'phone_caption' => 'подпись телефона',
            'public_email' => 'публичный email',
            'order_notification_email' => 'email уведомлений о заказах',
            'work_hours' => 'режим работы',
            'legal_name' => 'юридическое название',
            'inn' => 'ИНН',
            'ogrn' => 'ОГРН',
            'legal_address' => 'юридический адрес',
            'vk_url' => 'ссылка ВКонтакте',
            'telegram_url' => 'ссылка Telegram',
            'footer_copyright' => 'copyright',
            'footer_disclaimer' => 'дисклеймер',
        ])->validate();
    }

    private function authorize(User $actor, AdminPermission $permission): void
    {
        if (! $actor->canPerformAdminAction($permission)) {
            throw new AuthorizationException('Недостаточно прав для изменения настроек магазина.');
        }
    }
}
