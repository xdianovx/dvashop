<?php

namespace App\Filament\Pages;

use App\Enums\AdminPermission;
use App\Models\User;
use App\Services\Settings\ShopSettingsService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ShopSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'settings/shop';

    protected string $view = 'filament.pages.shop-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return 'Настройки';
    }

    public static function getNavigationLabel(): string
    {
        return 'Настройки магазина';
    }

    public function getTitle(): string
    {
        return 'Настройки магазина';
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && $user->canPerformAdminAction(AdminPermission::ViewStoreSettings);
    }

    public function canUpdate(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && $user->canPerformAdminAction(AdminPermission::UpdateStoreSettings);
    }

    public function mount(ShopSettingsService $settings): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill($settings->current()->only(ShopSettingsService::EDITABLE_FIELDS));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->disabled(fn (): bool => ! $this->canUpdate())
            ->components([
                Section::make('Основное')
                    ->schema([
                        TextInput::make('store_name')
                            ->label('Название магазина')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('work_hours')
                            ->label('Режим работы')
                            ->maxLength(255),
                    ])->columns(2),
                Section::make('Контакты')
                    ->schema([
                        TextInput::make('phone_display')->label('Отображаемый телефон')->maxLength(100),
                        TextInput::make('phone_href')->label('Телефон для tel:')->maxLength(32),
                        TextInput::make('phone_caption')->label('Подпись телефона')->maxLength(255),
                        TextInput::make('public_email')->label('Публичный email')->email()->maxLength(255),
                        TextInput::make('order_notification_email')->label('Email уведомлений о заказах')->email()->maxLength(255),
                    ])->columns(2),
                Section::make('Реквизиты')
                    ->schema([
                        TextInput::make('legal_name')->label('Юридическое название')->maxLength(255),
                        TextInput::make('inn')->label('ИНН')->maxLength(12),
                        TextInput::make('ogrn')->label('ОГРН')->maxLength(15),
                        Textarea::make('legal_address')->label('Юридический адрес')->rows(3)->maxLength(2000)->columnSpanFull(),
                    ])->columns(2),
                Section::make('Социальные сети')
                    ->schema([
                        TextInput::make('vk_url')->label('ВКонтакте')->url()->maxLength(255),
                        TextInput::make('telegram_url')->label('Telegram')->url()->maxLength(255),
                    ])->columns(2),
                Section::make('Подвал')
                    ->schema([
                        Textarea::make('footer_copyright')->label('Copyright')->rows(3)->maxLength(2000),
                        Textarea::make('footer_disclaimer')->label('Disclaimer')->rows(3)->maxLength(500),
                    ])->columns(2),
            ]);
    }

    public function save(ShopSettingsService $settings): void
    {
        $actor = Filament::auth()->user();

        if (! $actor instanceof User) {
            throw new AuthorizationException('Необходимо войти в административную панель.');
        }

        try {
            $setting = $settings->update($actor, $this->form->getState());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [
                    str_starts_with($field, 'data.') ? $field : 'data.'.$field => $messages,
                ])
                ->all());
        }

        $this->form->fill($setting->only(ShopSettingsService::EDITABLE_FIELDS));

        Notification::make()
            ->success()
            ->title('Настройки магазина сохранены')
            ->send();
    }
}
