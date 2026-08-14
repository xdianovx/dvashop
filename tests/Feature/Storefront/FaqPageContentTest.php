<?php

use App\Models\FaqCategory;
use App\Models\FaqItem;
use Database\Seeders\FaqSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('faq page eager loads ordered active non deleted categories and questions', function (): void {
    $this->seed([ShopSettingsSeeder::class, StaticPageContentSeeder::class, FaqSeeder::class]);

    $hidden = FaqItem::factory()->for(FaqCategory::query()->firstOrFail(), 'category')->create([
        'question' => 'Скрытый вопрос для проверки?',
        'answer' => 'Скрытый ответ',
        'is_active' => false,
        'position' => 999,
    ]);
    $deleted = FaqItem::factory()->for(FaqCategory::query()->firstOrFail(), 'category')->create([
        'question' => 'Удалённый вопрос для проверки?',
        'answer' => 'Удалённый ответ',
        'is_active' => true,
        'position' => 1000,
    ]);
    $deleted->delete();

    $this->get(route('faq'))
        ->assertOk()
        ->assertSee('faq-page__tabs', false)
        ->assertSee('data-faq-toggle', false)
        ->assertDontSee($hidden->question)
        ->assertDontSee($deleted->question);
});

test('faq keeps both mobile calls to action visible', function (): void {
    $this->seed([ShopSettingsSeeder::class, StaticPageContentSeeder::class, FaqSeeder::class]);

    $this->get(route('faq'))
        ->assertOk()
        ->assertSee('faq-page__cta', false)
        ->assertSee('faq-page__cta-home', false)
        ->assertSee('data-inquiry-open', false);

    $scss = file_get_contents(resource_path('scss/_faq-page.scss'));

    expect($scss)
        ->toContain(".faq-page__cta {\n        display: flex;")
        ->not->toContain(".faq-page__cta {\n        display: none;")
        ->toContain(".faq-page__cta-home {\n        display: flex;");
});
