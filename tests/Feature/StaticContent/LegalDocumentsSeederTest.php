<?php

use App\Enums\LegalDocumentCode;
use App\Models\LegalDocument;
use App\Services\Legal\LegalDocumentAdminService;
use Database\Seeders\LegalDocumentsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('legal documents seeder creates exactly four inactive fixed plain text documents idempotently', function (): void {
    $this->seed(LegalDocumentsSeeder::class);
    $this->seed(LegalDocumentsSeeder::class);

    $documents = LegalDocument::query()->orderBy('id')->get();

    expect($documents)->toHaveCount(4)
        ->and($documents->map(fn (LegalDocument $document): string => $document->code->value)->all())
        ->toBe(array_column(LegalDocumentCode::cases(), 'value'));

    foreach ($documents as $document) {
        expect($document->title)->toBe($document->code->label())
            ->and($document->body)->toBeNull()
            ->and($document->is_active)->toBeFalse();
    }
});

test('legal documents seeder preserves manual content and unrelated rows', function (): void {
    $this->seed(LegalDocumentsSeeder::class);

    $privacy = LegalDocument::query()->where('code', LegalDocumentCode::PrivacyPolicy)->firstOrFail();
    $privacy->forceFill([
        'title' => 'Утверждённая политика',
        'body' => "Первая строка.\nВторая строка.",
        'is_active' => true,
    ])->save();

    DB::table('legal_documents')->insert([
        'code' => 'legacy_raw_document',
        'title' => 'Сторонняя запись',
        'body' => null,
        'is_active' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->seed(LegalDocumentsSeeder::class);

    $state = app(LegalDocumentAdminService::class)->state();

    expect($privacy->refresh()->title)->toBe('Утверждённая политика')
        ->and($privacy->body)->toBe("Первая строка.\nВторая строка.")
        ->and($privacy->is_active)->toBeTrue()
        ->and(DB::table('legal_documents')->where('code', 'legacy_raw_document')->exists())->toBeTrue()
        ->and($state['documents'])->toHaveCount(4)
        ->and(array_column($state['documents'], '_label'))->toBe(array_map(
            fn (LegalDocumentCode $code): string => $code->label(),
            LegalDocumentCode::cases(),
        ));
});

test('legal document model rejects unknown mutable copied and deleted records while sanitizing html', function (): void {
    $this->seed(LegalDocumentsSeeder::class);
    $document = LegalDocument::query()->firstOrFail();

    expect(fn () => LegalDocument::query()->create([
        'code' => 'unknown_document',
        'title' => 'Неизвестный документ',
        'body' => null,
        'is_active' => false,
    ]))->toThrow(ValidationException::class);

    $document->body = '<h2 style="text-align: center; color: red">Раздел</h2><script>alert(1)</script><p><strong>Текст</strong> <a href="javascript:alert(1)" target="_blank">ссылка</a></p>';
    $document->save();
    expect($document->body)->toContain('<h2 style="text-align: center;">Раздел</h2>')
        ->toContain('<strong>Текст</strong>')
        ->not->toContain('<script')
        ->not->toContain('javascript:')
        ->not->toContain('color:');

    $otherCode = LegalDocumentCode::SaleRules;
    $document->code = $otherCode;

    expect(fn () => $document->save())->toThrow(ValidationException::class)
        ->and(fn () => $document->delete())->toThrow(ValidationException::class)
        ->and(fn () => $document->forceDelete())->toThrow(ValidationException::class)
        ->and(fn () => $document->replicate())->toThrow(ValidationException::class);
});

test('empty legal document is always inactive', function (): void {
    $document = LegalDocument::query()->create([
        'code' => LegalDocumentCode::PrivacyPolicy,
        'title' => LegalDocumentCode::PrivacyPolicy->label(),
        'body' => '   ',
        'is_active' => true,
    ]);

    expect($document->body)->toBeNull()
        ->and($document->is_active)->toBeFalse();
});

test('legal documents seeder rolls back all inserts after a late failure', function (): void {
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER fail_legal_document_seed
        BEFORE INSERT ON legal_documents
        WHEN NEW.code = 'returns_exchange'
        BEGIN
            SELECT RAISE(ABORT, 'forced legal document seeder failure');
        END
    SQL);

    try {
        expect(fn () => $this->seed(LegalDocumentsSeeder::class))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS fail_legal_document_seed');
    }

    expect(LegalDocument::query()->count())->toBe(0);
});
