<?php

use App\Enums\HomepageCategoryCardCode;
use App\Enums\HomepageSectionCode;
use App\Models\FaqItem;
use App\Models\HomepageSection;
use App\Models\ProductCategory;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('homepage renders approved active database sections without adding featured faq', function (): void {
    $this->seed([ShopSettingsSeeder::class, HomepageContentSeeder::class]);

    HomepageSection::query()
        ->where('code', HomepageSectionCode::VehicleSearch->value)
        ->firstOrFail()
        ->forceFill([
            'title' => 'Поиск деталей по автомобилю из базы',
            'is_active' => true,
        ])
        ->save();

    FaqItem::factory()->create([
        'question' => 'Featured FAQ не должен появляться на главной',
        'answer' => 'Этот вопрос существует только для проверки границы PROMPT 3.',
        'is_featured' => true,
        'is_active' => true,
    ]);

    $category = ProductCategory::factory()->create(['title' => 'Тестовая категория витрины']);
    DB::table('homepage_category_cards')
        ->where('code', HomepageCategoryCardCode::BodyRepair->value)
        ->update([
            'title' => 'Карточка из базы данных',
            'link_type' => null,
            'route_name' => null,
            'product_category_id' => $category->getKey(),
            'part_type_id' => null,
            'url' => null,
            'is_active' => true,
        ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Карточка из базы данных')
        ->assertSee(route('catalog.index', ['category' => $category->full_slug]), false)
        ->assertSee('Поиск деталей по автомобилю из базы')
        ->assertSee('class="search"', false)
        ->assertSee('search__form', false)
        ->assertSee('about__grid', false)
        ->assertDontSee('Featured FAQ не должен появляться на главной')
        ->assertDontSee('class="faq"', false);

    HomepageSection::query()
        ->where('code', HomepageSectionCode::VehicleSearch->value)
        ->firstOrFail()
        ->forceFill(['is_active' => false])
        ->save();

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('Поиск деталей по автомобилю из базы')
        ->assertDontSee('class="search"', false)
        ->assertDontSee('search__form', false);

    HomepageSection::query()
        ->where('code', HomepageSectionCode::CategoryCards->value)
        ->firstOrFail()
        ->forceFill(['is_active' => false])
        ->save();

    $this->get(route('home'))->assertDontSee('Карточка из базы данных');
});
