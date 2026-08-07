<?php

use App\Enums\LegalDocumentCode;
use App\Models\LegalDocument;
use Database\Seeders\LegalDocumentsSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
