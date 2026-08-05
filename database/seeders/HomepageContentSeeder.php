<?php

namespace Database\Seeders;

use App\Enums\HomepageCategoryCardCode;
use App\Enums\HomepageMetricCode;
use App\Enums\HomepageQuickLinkCode;
use App\Enums\HomepageSectionCode;
use App\Enums\NavigationLinkType;
use App\Models\HomepageCategoryCard;
use App\Models\HomepageMetric;
use App\Models\HomepageQuickLink;
use App\Models\HomepageSection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;

class HomepageContentSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            if (! Route::has('catalog.index')) {
                throw new RuntimeException('Обязательный маршрут catalog.index не зарегистрирован.');
            }

            foreach ($this->sections() as $code => $attributes) {
                HomepageSection::query()->firstOrCreate(['code' => $code], $attributes);
            }

            foreach ($this->quickLinks() as $code => $attributes) {
                HomepageQuickLink::query()->firstOrCreate(['code' => $code], $attributes);
            }

            foreach ($this->categoryCards() as $code => $attributes) {
                HomepageCategoryCard::query()->firstOrCreate(['code' => $code], $attributes);
            }

            foreach ($this->metrics() as $code => $attributes) {
                HomepageMetric::query()->firstOrCreate(['code' => $code], $attributes);
            }
        });
    }

    private function sections(): array
    {
        return [
            HomepageSectionCode::QuickLinks->value => ['title' => null, 'position' => 10],
            HomepageSectionCode::VehicleSearch->value => ['title' => 'Быстрый поиск запчастей', 'position' => 20],
            HomepageSectionCode::CategoryCards->value => ['title' => null, 'position' => 30],
            HomepageSectionCode::AboutMetrics->value => ['title' => 'О компании', 'position' => 40],
        ];
    }

    private function quickLinks(): array
    {
        return [
            HomepageQuickLinkCode::NewArrivals->value => ['title' => 'Новинки', 'position' => 10],
            HomepageQuickLinkCode::Promotions->value => ['title' => 'Акции', 'position' => 20],
            HomepageQuickLinkCode::ServiceSearch->value => ['title' => 'Поиск СТО', 'position' => 30],
            HomepageQuickLinkCode::Reviews->value => ['title' => 'Отзывы', 'position' => 40],
            HomepageQuickLinkCode::Socials->value => ['title' => 'Соц. сети', 'position' => 50],
            HomepageQuickLinkCode::Galvanized->value => ['title' => 'Оцинковка', 'position' => 60],
            HomepageQuickLinkCode::Fitting->value => ['title' => 'Примерка', 'position' => 70],
        ];
    }

    private function categoryCards(): array
    {
        $destination = [
            'link_type' => NavigationLinkType::Route->value,
            'route_name' => 'catalog.index',
            'position' => 0,
        ];

        return [
            HomepageCategoryCardCode::Sills->value => [...$destination, 'title' => 'Кузовные пороги', 'position' => 10],
            HomepageCategoryCardCode::Commercial->value => [...$destination, 'title' => 'Коммерческий транспорт', 'position' => 20],
            HomepageCategoryCardCode::BodyRepair->value => [...$destination, 'title' => 'Ремонт кузова', 'position' => 30],
            HomepageCategoryCardCode::FrontArches->value => [...$destination, 'title' => 'Передние арки', 'position' => 40],
            HomepageCategoryCardCode::RearArches->value => [...$destination, 'title' => 'Задние арки', 'position' => 50],
        ];
    }

    private function metrics(): array
    {
        return [
            HomepageMetricCode::SinceYear->value => ['prefix' => 'с', 'value' => '2014', 'suffix' => 'г.', 'text' => 'наша экспертиза для вашей уверенности', 'position' => 10],
            HomepageMetricCode::VehicleDatabase->value => ['prefix' => null, 'value' => '3000', 'suffix' => 'авто', 'text' => 'самая большая база ремонтных деталей', 'position' => 20],
            HomepageMetricCode::ItemsSold->value => ['prefix' => null, 'value' => '1', 'suffix' => 'млн шт.', 'text' => 'проданных арок и порогов за все время', 'position' => 30],
            HomepageMetricCode::OriginalFit->value => ['prefix' => null, 'value' => '100', 'suffix' => '%', 'text' => 'повторяет оригинальные детали', 'position' => 40],
            HomepageMetricCode::PriceAdvantage->value => ['prefix' => 'в', 'value' => '5', 'suffix' => 'раз', 'text' => 'дешевле штампованных деталей с разборки', 'position' => 50],
        ];
    }
}
