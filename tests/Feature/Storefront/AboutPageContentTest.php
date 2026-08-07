<?php

use App\Enums\StaticPageCode;
use App\Models\StaticPage;
use App\Models\User;
use App\Services\StaticContent\StaticPageContentAdminService;
use Database\Seeders\ShopSettingsSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('about page renders database content and reflects the next admin service update', function (): void {
    $this->seed([ShopSettingsSeeder::class, StaticPageContentSeeder::class]);

    $page = StaticPage::query()->where('code', StaticPageCode::About)->firstOrFail();
    app(StaticPageContentAdminService::class)->updatePage(
        User::factory()->admin()->create(),
        $page,
        ['title' => 'Уникальный заголовок страницы о компании'],
    );

    $this->get(route('about'))
        ->assertOk()
        ->assertSee('Уникальный заголовок страницы о компании')
        ->assertSee('tel:+78001005625', false)
        ->assertSee('about-page', false)
        ->assertSee('about-hero', false)
        ->assertSee('about-metrics', false)
        ->assertSee('about-tech', false)
        ->assertSee('about-goal', false);
});
