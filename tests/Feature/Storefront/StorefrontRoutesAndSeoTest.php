<?php

use App\Enums\LegalDocumentCode;
use App\Models\LegalDocument;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\LegalDocumentsSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('public information route names stay stable and legal routes are fixed', function (): void {
    foreach (['home', 'about', 'how', 'payment', 'faq', 'partners', 'catalog.index', 'cart.show'] as $routeName) {
        expect(Route::has($routeName))->toBeTrue();
    }

    expect(Route::has('legal.privacy-policy'))->toBeTrue()
        ->and(Route::has('legal.sale-rules'))->toBeTrue()
        ->and(Route::has('legal.returns-exchange'))->toBeTrue()
        ->and(Route::has('legal.information-usage-rules'))->toBeTrue();
});

test('every connected information page exposes title description and canonical without query parameters', function (): void {
    $this->seed([
        ShopSettingsSeeder::class,
        StaticPageContentSeeder::class,
        CheckoutMethodSettingsSeeder::class,
        FaqSeeder::class,
        HomepageContentSeeder::class,
        LegalDocumentsSeeder::class,
    ]);

    LegalDocument::query()
        ->where('code', LegalDocumentCode::PrivacyPolicy->value)
        ->firstOrFail()
        ->forceFill(['body' => 'Текст политики конфиденциальности.', 'is_active' => true])
        ->save();

    foreach (['home', 'about', 'how', 'payment', 'faq', 'partners', 'legal.privacy-policy'] as $routeName) {
        $html = $this->get(route($routeName, ['untrusted' => 'ignored']))->assertOk()->getContent();

        expect($html)
            ->toMatch('/<title>[^<]+<\/title>/u')
            ->toMatch('/<meta name="description" content="[^"]+">/u')
            ->toMatch('/<link rel="canonical" href="[^"]+">/u');

        preg_match('/<link rel="canonical" href="([^"]+)">/u', $html, $matches);
        expect($matches[1] ?? '')->not->toContain('untrusted=');
    }
});
