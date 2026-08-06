<?php

namespace App\Filament\Pages;

use App\Enums\AdminPermission;
use App\Filament\Pages\SiteContent\EditAboutPage;
use App\Filament\Pages\SiteContent\EditFaqPage;
use App\Filament\Pages\SiteContent\EditHomepagePage;
use App\Filament\Pages\SiteContent\EditHowPage;
use App\Filament\Pages\SiteContent\EditPartnersPage;
use App\Filament\Pages\SiteContent\EditPaymentPage;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class SitePagesPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'content/site-pages';

    protected string $view = 'filament.pages.site-content.index';

    public static function getNavigationGroup(): ?string
    {
        return 'Контент сайта';
    }

    public static function getNavigationLabel(): string
    {
        return 'Страницы сайта';
    }

    public function getTitle(): string
    {
        return 'Страницы сайта';
    }

    public function getSubheading(): ?string
    {
        return 'Выберите страницу и измените только тот текст и настройки, которые действительно используются на сайте.';
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && $user->canPerformAdminAction(AdminPermission::ViewHomepageContent)
            && $user->canPerformAdminAction(AdminPermission::ViewStaticContent)
            && $user->canPerformAdminAction(AdminPermission::ViewPaymentMethods)
            && $user->canPerformAdminAction(AdminPermission::ViewDeliveryMethods);
    }

    /** @return list<array{title: string, description: string, icon: string, url: string}> */
    public function cards(): array
    {
        return [
            [
                'title' => 'Главная',
                'description' => 'Секции, быстрые ссылки, витринные категории и показатели компании.',
                'icon' => 'heroicon-o-home',
                'url' => EditHomepagePage::getUrl(),
            ],
            [
                'title' => 'О нас',
                'description' => 'Первый экран, показатели, технологии точности и цель компании.',
                'icon' => 'heroicon-o-building-office-2',
                'url' => EditAboutPage::getUrl(),
            ],
            [
                'title' => 'Как мы работаем',
                'description' => 'Шесть фиксированных шагов оформления и получения заказа.',
                'icon' => 'heroicon-o-list-bullet',
                'url' => EditHowPage::getUrl(),
            ],
            [
                'title' => 'Оплата и доставка',
                'description' => 'Способы оплаты, доставки, активность и порядок показа.',
                'icon' => 'heroicon-o-truck',
                'url' => EditPaymentPage::getUrl(),
            ],
            [
                'title' => 'Вопросы и ответы',
                'description' => 'Категории FAQ и вопросы внутри них на одной странице.',
                'icon' => 'heroicon-o-question-mark-circle',
                'url' => EditFaqPage::getUrl(),
            ],
            [
                'title' => 'Партнёрам',
                'description' => 'Заголовок, преимущества, форматы сотрудничества и факты.',
                'icon' => 'heroicon-o-user-group',
                'url' => EditPartnersPage::getUrl(),
            ],
        ];
    }
}
