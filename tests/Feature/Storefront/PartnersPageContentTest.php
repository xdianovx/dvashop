<?php

use Database\Seeders\ShopSettingsSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('partners page renders the fixed database inventory and one global contact', function (): void {
    $this->seed([ShopSettingsSeeder::class, StaticPageContentSeeder::class]);

    $this->get(route('partners'))
        ->assertOk()
        ->assertSee('partners-page__benefits', false)
        ->assertSee('partners-page__coop', false)
        ->assertSee('partners-page__about', false)
        ->assertSee('8 800 100 56 25')
        ->assertSee('tel:+78001005625', false)
        ->assertDontSee('+7 (906) 244-41-51');
});
