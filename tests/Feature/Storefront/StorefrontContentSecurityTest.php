<?php

use App\Enums\StaticPageSectionCode;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

test('storefront escapes database text and renders no placeholder or unsafe destinations', function (): void {
    $this->seed([
        ShopSettingsSeeder::class,
        StaticPageContentSeeder::class,
        CheckoutMethodSettingsSeeder::class,
        FaqSeeder::class,
        HomepageContentSeeder::class,
    ]);

    DB::table('static_page_sections')
        ->where('code', StaticPageSectionCode::AboutHero->value)
        ->update(['body' => '<script>alert("unsafe")</script>']);

    $about = $this->get(route('about'))->assertOk();
    $about->assertSee('&lt;script&gt;alert(&quot;unsafe&quot;)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert("unsafe")</script>', false);

    foreach (['home', 'about', 'how', 'payment', 'faq', 'partners'] as $routeName) {
        $html = $this->get(route($routeName))->assertOk()->getContent();

        expect($html)
            ->not->toContain('href="#"')
            ->not->toContain('href=""')
            ->not->toContain('javascript:')
            ->not->toContain('+7 (777) 777-77-77')
            ->not->toContain('+7 (906) 244-41-51')
            ->not->toContain('+7(939)5554925')
            ->not->toContain('info@example.ru');
    }

    foreach ([
        'about.blade.php',
        'how.blade.php',
        'payment.blade.php',
        'faq.blade.php',
        'partners.blade.php',
        'legal-document.blade.php',
    ] as $view) {
        expect(File::get(resource_path('views/'.$view)))->not->toContain('{!!');
    }
});
