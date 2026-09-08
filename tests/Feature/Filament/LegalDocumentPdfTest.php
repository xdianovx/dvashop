<?php

use App\Enums\LegalDocumentCode;
use App\Enums\LegalDocumentContentType;
use App\Filament\Pages\LegalDocumentsPage;
use App\Models\LegalDocument;
use App\Models\User;
use App\Services\Legal\LegalDocumentAdminService;
use App\Services\Legal\LegalDocumentPdfStorage;
use App\Services\Storefront\GlobalStorefrontDataProvider;
use Database\Seeders\LegalDocumentsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->seed(LegalDocumentsSeeder::class);
    $this->actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

function pdfDocumentState(array $changes = []): array
{
    $data = app(LegalDocumentAdminService::class)->state();
    foreach ($data['documents'] as &$document) {
        unset($document['_label']);
    }
    unset($document);
    $data['documents'][0] = [...$data['documents'][0], ...$changes];

    return $data;
}

function savePdfDocument(array $changes): LegalDocument
{
    app(LegalDocumentAdminService::class)->save(auth()->user(), pdfDocumentState($changes));

    return LegalDocument::query()->where('code', LegalDocumentCode::PrivacyPolicy)->firstOrFail();
}

test('page and PDF conditional fields work and valid upload opens directly from public storage', function (): void {
    $undo = Repeater::fake();
    try {
        $page = Livewire::test(LegalDocumentsPage::class)
            ->assertFormFieldVisible('documents.0.body')
            ->assertFormFieldHidden('documents.0.pdf_path')
            ->set('data.documents.0.content_type', 'pdf')
            ->assertFormFieldHidden('documents.0.body')
            ->assertFormFieldVisible('documents.0.pdf_path')
            ->set('data.documents.0.is_active', true)
            ->call('save')->assertHasFormErrors(['documents.0.pdf_path'])
            ->assertNotified('Не удалось выполнить действие');
        $page->set('data.documents.0.pdf_path', [UploadedFile::fake()->create('legal.pdf', 100, 'application/pdf')])
            ->call('save')->assertHasNoErrors()->assertNotified('Документы сохранены');
        $document = LegalDocument::query()->where('code', LegalDocumentCode::PrivacyPolicy)->firstOrFail();
        expect($document->content_type)->toBe(LegalDocumentContentType::Pdf)
            ->and($document->pdf_path)->toMatch('~^uploads/legal-documents/[a-f0-9-]+\.pdf$~')
            ->and($document->publicDestination())->toBe(Storage::disk('public')->url($document->pdf_path));
        Storage::disk('public')->assertExists($document->pdf_path);
        $this->get(route('legal.privacy-policy'))->assertRedirect($document->publicDestination());
        $data = app(GlobalStorefrontDataProvider::class)->load();
        expect($data->legalDocumentUrls['privacy_policy'])->toBe($document->publicDestination());
        $this->get(route('cart.show'))->assertOk()->assertSee($document->publicDestination(), false);
        $this->get(route('checkout.show'))->assertOk()->assertSee('href="'.$document->publicDestination().'"', false);
    } finally {
        $undo();
    }
});

test('non PDF uploads spoofed extensions oversized files and foreign paths are rejected', function (Closure $file): void {
    $before = pdfDocumentState();
    expect(fn () => savePdfDocument(['content_type' => 'pdf', 'is_active' => true, 'pdf_path' => $file()]))
        ->toThrow(ValidationException::class);
    expect(pdfDocumentState())->toBe($before)->and(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'image' => [fn () => UploadedFile::fake()->image('fake.png')],
    'renamed non PDF' => [fn () => UploadedFile::fake()->create('fake.pdf', 1, 'text/plain')],
    'wrong extension' => [fn () => UploadedFile::fake()->create('fake.html', 1, 'application/pdf')],
    'oversize' => [fn () => UploadedFile::fake()->create('big.pdf', LegalDocumentPdfStorage::MAX_SIZE_KB + 1, 'application/pdf')],
    'traversal' => [fn () => 'uploads/legal-documents/../../secret.pdf'],
    'external URL' => [fn () => 'https://example.test/legal.pdf'],
    'unowned path' => [fn () => 'uploads/legal-documents/someone-else.pdf'],
]);

test('PDF replacement and switch back preserve page content and delete old files only after commit', function (): void {
    $document = savePdfDocument(['body' => '<p>Сохранённый текст</p>', 'is_active' => true]);
    expect($document->publicDestination())->toBe(route('legal.privacy-policy'));
    $this->get(route('legal.privacy-policy'))->assertOk()->assertSee('Сохранённый текст');
    $document = savePdfDocument(['content_type' => 'pdf', 'pdf_path' => UploadedFile::fake()->create('first.pdf', 1, 'application/pdf'), 'is_active' => true]);
    $first = $document->pdf_path;
    DB::beginTransaction();
    $document = savePdfDocument(['pdf_path' => UploadedFile::fake()->create('second.pdf', 1, 'application/pdf')]);
    $second = $document->pdf_path;
    Storage::disk('public')->assertExists([$first, $second]);
    DB::commit();
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
    $document = savePdfDocument(['content_type' => 'page']);
    expect($document->pdf_path)->toBeNull()->and($document->body)->toBe('<p>Сохранённый текст</p>')
        ->and($document->publicDestination())->toBe(route('legal.privacy-policy'));
    Storage::disk('public')->assertMissing($second);
    $this->get(route('legal.privacy-policy'))->assertOk()->assertSee('Сохранённый текст');
});

test('late validation rollback keeps original PDF and removes newly saved replacement', function (): void {
    $original = savePdfDocument(['content_type' => 'pdf', 'is_active' => true, 'pdf_path' => UploadedFile::fake()->create('original.pdf', 1, 'application/pdf')]);
    $before = pdfDocumentState();
    $data = pdfDocumentState(['pdf_path' => UploadedFile::fake()->create('replacement.pdf', 1, 'application/pdf')]);
    $data['documents'][3]['title'] = '';
    expect(fn () => app(LegalDocumentAdminService::class)->save(auth()->user(), $data))->toThrow(ValidationException::class);
    expect(pdfDocumentState())->toBe($before)
        ->and(Storage::disk('public')->allFiles())->toBe([$original->pdf_path]);
});

test('inactive PDF draft may have no file and published links retain inactive behavior', function (): void {
    $draft = savePdfDocument(['content_type' => 'pdf', 'is_active' => false, 'pdf_path' => null]);
    expect($draft->publicDestination())->toBeNull();
    $this->get(route('legal.privacy-policy'))->assertNotFound();
    $document = savePdfDocument(['is_active' => true, 'pdf_path' => UploadedFile::fake()->create('legal.pdf', 1, 'application/pdf')]);
    $document = savePdfDocument(['is_active' => false]);
    Storage::disk('public')->assertExists($document->pdf_path);
    expect($document->publicDestination())->toBeNull()
        ->and(app(GlobalStorefrontDataProvider::class)->load()->legalDocumentUrls)->not->toHaveKey('privacy_policy');
    $this->get(route('legal.privacy-policy'))->assertNotFound();
    expect(fn () => $document->delete())->toThrow(ValidationException::class);
    Storage::disk('public')->assertExists($document->pdf_path);
});
