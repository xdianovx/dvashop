<?php

use App\Enums\LegalDocumentCode;
use App\Models\LegalDocument;
use App\Models\Order;
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

test('robots response blocks private commerce paths and points to the sitemap', function (): void {
    $this->get(route('robots'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSeeText('Disallow: /admin')
        ->assertSeeText('Disallow: /cart')
        ->assertSeeText('Disallow: /checkout')
        ->assertSeeText('Disallow: /thanks')
        ->assertSeeText('Sitemap: '.route('sitemap'));
});

test('cart checkout and thanks pages are noindex nofollow', function (): void {
    $robotsMeta = '<meta name="robots" content="noindex, nofollow">';

    $this->get(route('cart.show'))
        ->assertOk()
        ->assertSee($robotsMeta, false);

    $this->get(route('checkout.show'))
        ->assertOk()
        ->assertSee($robotsMeta, false);

    $order = Order::factory()->create();
    $token = 'secure-thanks-token';

    $this->withSession(['checkout_success.'.$order->getKey() => $token])
        ->get(route('checkout.success', ['order' => $order->number, 'token' => $token]))
        ->assertOk()
        ->assertSee($robotsMeta, false);
});
