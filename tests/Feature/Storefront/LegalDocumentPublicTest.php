<?php

use App\Enums\LegalDocumentCode;
use App\Models\LegalDocument;
use Database\Seeders\FaqSeeder;
use Database\Seeders\LegalDocumentsSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('only fixed active non empty legal documents are publicly available', function (): void {
    $this->seed([ShopSettingsSeeder::class, LegalDocumentsSeeder::class]);

    LegalDocument::query()
        ->where('code', LegalDocumentCode::PrivacyPolicy->value)
        ->firstOrFail()
        ->forceFill([
            'body' => "Первый абзац.\n\nВторая строка.\nТретья строка.",
            'is_active' => true,
        ])
        ->save();

    $this->get(route('legal.privacy-policy'))
        ->assertOk()
        ->assertSee('Первый абзац.')
        ->assertSee('Вторая строка.')
        ->assertSee('ИНН')
        ->assertSee('legal-page', false);

    $this->get(route('legal.sale-rules'))->assertNotFound();
    $this->get('/documents/unknown-document')->assertNotFound();
});

test('inquiry privacy notice links to the single active privacy policy route', function (): void {
    $this->seed([
        ShopSettingsSeeder::class,
        StaticPageContentSeeder::class,
        FaqSeeder::class,
        LegalDocumentsSeeder::class,
    ]);

    $privacyUrl = route('legal.privacy-policy');

    LegalDocument::query()
        ->where('code', LegalDocumentCode::PrivacyPolicy->value)
        ->firstOrFail()
        ->forceFill([
            'body' => 'Утверждённая политика обработки персональных данных.',
            'is_active' => true,
        ])
        ->save();

    $this->get(route('faq'))
        ->assertOk()
        ->assertSee('href="'.$privacyUrl.'"', false)
        ->assertSee('политикой конфиденциальности');
});

test('legal rich content renders tables formatting safe links and legacy text without executable html', function (): void {
    $this->seed([ShopSettingsSeeder::class, LegalDocumentsSeeder::class]);
    $document = LegalDocument::query()->where('code', LegalDocumentCode::PrivacyPolicy)->firstOrFail();
    $document->forceFill([
        'body' => '<h2>Условия</h2><table><thead><tr><th>Пункт</th></tr></thead><tbody><tr><td>Значение</td></tr></tbody></table><p><a href="https://example.com" target="_blank">Источник</a><img src=x onerror=alert(1)></p>',
        'is_active' => true,
    ])->save();

    $response = $this->get(route('legal.privacy-policy'))->assertOk()
        ->assertSee('<h2>Условия</h2>', false)
        ->assertSee('<table>', false)
        ->assertSee('target="_blank" rel="noopener noreferrer"', false)
        ->assertDontSee('<img src="x"', false)
        ->assertDontSee('onerror', false);

    $document->forceFill(['body' => "Старый абзац.\nСтрока.\n\nВторой абзац."])->save();
    $this->get(route('legal.privacy-policy'))
        ->assertOk()
        ->assertSee('Старый абзац.')
        ->assertSee('<br>', false)
        ->assertSee('Строка.')
        ->assertSee('<p>Второй абзац.</p>', false);
});

test('public legal rich html is rendered once while seo description stays plain text', function (): void {
    $this->seed([ShopSettingsSeeder::class, LegalDocumentsSeeder::class]);
    LegalDocument::query()->where('code', LegalDocumentCode::PrivacyPolicy)->firstOrFail()->forceFill([
        'body' => '<h2>Правила возврата</h2><p><strong>Сохраняйте чек</strong> и упаковку.</p><table><tbody><tr><td colspan="2">Срок: 14 дней</td></tr></tbody></table>',
        'is_active' => true,
    ])->save();

    $html = $this->get(route('legal.privacy-policy'))
        ->assertOk()
        ->assertSee('<h2>Правила возврата</h2>', false)
        ->assertSee('<strong>Сохраняйте чек</strong>', false)
        ->assertSee('<td colspan="2">Срок: 14 дней</td>', false)
        ->assertDontSee('&lt;h2&gt;', false)
        ->getContent();

    expect(preg_match('/<meta name="description" content="([^"]*)"/', $html, $matches))->toBe(1)
        ->and(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))->not->toContain('<', '>');
});

test('public legal provider sanitizes even raw legacy database content at render time', function (): void {
    $this->seed([ShopSettingsSeeder::class, LegalDocumentsSeeder::class]);
    DB::table('legal_documents')->where('code', LegalDocumentCode::PrivacyPolicy->value)->update([
        'body' => '<h2>Безопасный заголовок</h2><script>alert(1)</script><a href="javascript:alert(2)">Опасная ссылка</a><table onclick="alert(3)"><tr><td>Данные</td></tr></table>',
        'is_active' => true,
    ]);

    $this->get(route('legal.privacy-policy'))
        ->assertOk()
        ->assertSee('<h2>Безопасный заголовок</h2>', false)
        ->assertSee('<table>', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('javascript:', false)
        ->assertDontSee('onclick', false);
});
