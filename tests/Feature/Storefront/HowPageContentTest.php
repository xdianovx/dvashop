<?php

use App\Enums\StaticPageItemCode;
use App\Models\StaticPageItem;
use Database\Seeders\ShopSettingsSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('how page renders fixed steps phone and the approved handover line break without database html', function (): void {
    $this->seed([ShopSettingsSeeder::class, StaticPageContentSeeder::class]);

    $handover = StaticPageItem::query()
        ->where('code', StaticPageItemCode::HowStepHandover)
        ->firstOrFail();

    expect($handover->text)
        ->toContain('СДЭК. Это позволяет')
        ->not->toContain('<br>')
        ->not->toContain('<strong>');

    StaticPageItem::query()
        ->where('code', StaticPageItemCode::HowStepPay)
        ->firstOrFail()
        ->forceFill(['is_active' => false])
        ->save();

    $response = $this->get(route('how'))
        ->assertOk()
        ->assertSee('how-page__step', false)
        ->assertSee('tel:+78001005625', false)
        ->assertDontSee('+7(939)5554925')
        ->assertSee('СДЭК.')
        ->assertSee('<strong>СДЭК.</strong><br>', false);

    expect(substr_count($response->getContent(), 'class="how-page__step"'))->toBe(5);
});
