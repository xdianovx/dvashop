<?php

namespace App\Filament\Pages\SiteContent;

use App\Enums\AdminPermission;
use App\Filament\Support\SiteContentEditorPage;
use App\Models\User;
use App\Services\SiteContent\SitePageContentAdminService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EditPaymentPage extends SiteContentEditorPage
{
    protected static ?string $slug = 'content/site-pages/payment';

    public function getTitle(): string
    {
        return 'Оплата и доставка';
    }

    protected static function viewPermissions(): array
    {
        return [AdminPermission::ViewPaymentMethods, AdminPermission::ViewDeliveryMethods];
    }

    protected static function updatePermissions(): array
    {
        return [AdminPermission::ManagePaymentMethods, AdminPermission::ManageDeliveryMethods];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->disabled(fn (): bool => ! $this->canUpdate())
            ->components([
                Section::make('Способы оплаты')
                    ->schema([
                        Repeater::make('payment_methods')
                            ->label('Оплата')
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('_label')->dehydrated(false),
                                TextInput::make('title')->label('Название')->required()->maxLength(255),
                                Toggle::make('is_active')->label('Показывать на сайте'),
                                Textarea::make('description')->label('Описание')->rows(4)->maxLength(5000)->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['title'] ?? $state['_label'] ?? 'Способ оплаты'))
                            ->columnSpanFull(),
                    ]),
                Section::make('Способы доставки')
                    ->schema([
                        Repeater::make('delivery_methods')
                            ->label('Доставка')
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('_label')->dehydrated(false),
                                TextInput::make('title')->label('Название')->required()->maxLength(255),
                                TextInput::make('base_price')
                                    ->label('Базовая стоимость')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01)
                                    ->prefix('₽')
                                    ->required(),
                                Toggle::make('is_active')->label('Показывать на сайте'),
                                Textarea::make('description')->label('Описание')->rows(4)->maxLength(5000)->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['title'] ?? $state['_label'] ?? 'Способ доставки'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function loadState(SitePageContentAdminService $service): array
    {
        return $service->paymentState();
    }

    protected function persistState(SitePageContentAdminService $service, User $actor, array $data): void
    {
        $service->savePayment($actor, $data);
    }

    protected function successNotificationTitle(): string
    {
        return 'Способы оплаты и доставки сохранены';
    }
}
