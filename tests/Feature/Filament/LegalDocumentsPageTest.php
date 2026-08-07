<?php

use App\Enums\LegalDocumentCode;
use App\Filament\Pages\LegalDocumentsPage;
use App\Models\LegalDocument;
use App\Models\User;
use App\Services\Legal\LegalDocumentAdminService;
use Database\Seeders\LegalDocumentsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

$undoLegalRepeaterFake = null;

beforeEach(function () use (&$undoLegalRepeaterFake): void {
    $undoLegalRepeaterFake = Repeater::fake();
    $this->seed(LegalDocumentsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

afterEach(function () use (&$undoLegalRepeaterFake): void {
    if ($undoLegalRepeaterFake instanceof Closure) {
        $undoLegalRepeaterFake();
    }
});

test('admin and super admin edit fixed legal documents through understandable page', function (string $role): void {
    $actor = $role === 'super_admin'
        ? User::factory()->superAdmin()->create()
        : User::factory()->admin()->create();
    $this->actingAs($actor);

    Livewire::test(LegalDocumentsPage::class)
        ->assertSee('Документы')
        ->assertSee('Юридические документы')
        ->assertSee('Название')
        ->assertSee('Содержимое')
        ->assertSee('Документ не заполнен')
        ->set('data.documents.0.title', 'Утверждённая политика')
        ->set('data.documents.0.body', "Первая строка.\nВторая строка.")
        ->set('data.documents.0.is_active', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertHasNoFormErrors()
        ->assertNotified('Документы сохранены');

    $document = LegalDocument::query()->where('code', LegalDocumentCode::PrivacyPolicy)->firstOrFail();

    expect($document->title)->toBe('Утверждённая политика')
        ->and($document->body)->toBe("Первая строка.\nВторая строка.")
        ->and($document->is_active)->toBeTrue();
})->with(['super admin' => ['super_admin'], 'admin' => ['admin']]);

test('manager sees legal documents read only and cannot forge save', function (): void {
    $this->actingAs(User::factory()->manager()->create());
    $before = app(LegalDocumentAdminService::class)->state();

    $this->get(LegalDocumentsPage::getUrl())
        ->assertOk()
        ->assertSee('Режим просмотра')
        ->assertDontSee('Сохранить изменения');

    Livewire::test(LegalDocumentsPage::class)
        ->assertFormFieldDisabled('documents')
        ->set('data.documents.0.title', 'Поддельное изменение')
        ->call('save')
        ->assertForbidden();

    expect(app(LegalDocumentAdminService::class)->state())->toBe($before);
});

test('customer inactive and blocked users cannot access legal documents', function (string $kind): void {
    $actor = match ($kind) {
        'inactive' => User::factory()->admin()->inactive()->create(),
        'blocked' => User::factory()->admin()->blocked()->create(),
        default => User::factory()->create(),
    };
    $this->actingAs($actor);

    $this->get(LegalDocumentsPage::getUrl())->assertForbidden();
})->with(['customer' => ['customer'], 'inactive' => ['inactive'], 'blocked' => ['blocked']]);

test('fixed legal form rejects forged code fifth document omitted document and duplicate id', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $before = app(LegalDocumentAdminService::class)->state();

    $cases = [
        'forged code' => [function (array $state): array {
            $state['documents'][0]['code'] = 'sale_rules';

            return $state;
        }, 'data.documents.0.code'],
        'fifth document' => [function (array $state): array {
            $state['documents'][] = [
                'id' => 999999,
                'title' => 'Пятый документ',
                'body' => null,
                'is_active' => false,
            ];

            return $state;
        }, 'data.documents'],
        'omitted document' => [function (array $state): array {
            array_pop($state['documents']);

            return $state;
        }, 'data.documents'],
        'duplicate id' => [function (array $state): array {
            $state['documents'][1]['id'] = $state['documents'][0]['id'];

            return $state;
        }, 'data.documents.1.id'],
    ];

    foreach ($cases as $label => [$mutate, $error]) {
        Livewire::test(LegalDocumentsPage::class)
            ->set('data', $mutate($before))
            ->call('save')
            ->assertHasErrors([$error]);

        expect(app(LegalDocumentAdminService::class)->state(), $label)->toBe($before);
    }
});

test('legal document save is transactional and rejects html in a late document', function (): void {
    $service = app(LegalDocumentAdminService::class);
    $admin = User::factory()->admin()->create();
    $before = $service->state();
    $payload = $before;
    $payload['documents'][0]['title'] = 'Не должно сохраниться';
    $payload['documents'][3]['body'] = '<script>alert(1)</script>';
    $payload['documents'][3]['is_active'] = true;

    expect(fn () => $service->save($admin, $payload))->toThrow(ValidationException::class);
    expect($service->state())->toBe($before);
});

test('legal document policy forbids create delete replicate restore and reorder', function (): void {
    $admin = User::factory()->admin()->create();
    $manager = User::factory()->manager()->create();
    $document = LegalDocument::query()->firstOrFail();

    expect($admin->can('viewAny', LegalDocument::class))->toBeTrue()
        ->and($admin->can('update', $document))->toBeTrue()
        ->and($manager->can('viewAny', LegalDocument::class))->toBeTrue()
        ->and($manager->can('update', $document))->toBeFalse();

    foreach ([$admin, $manager] as $actor) {
        expect($actor->can('create', LegalDocument::class))->toBeFalse()
            ->and($actor->can('delete', $document))->toBeFalse()
            ->and($actor->can('deleteAny', LegalDocument::class))->toBeFalse()
            ->and($actor->can('restore', $document))->toBeFalse()
            ->and($actor->can('restoreAny', LegalDocument::class))->toBeFalse()
            ->and($actor->can('forceDelete', $document))->toBeFalse()
            ->and($actor->can('forceDeleteAny', LegalDocument::class))->toBeFalse()
            ->and($actor->can('replicate', $document))->toBeFalse()
            ->and($actor->can('reorder', LegalDocument::class))->toBeFalse();
    }
});

test('legal document state uses one bounded query and returns fixed enum order', function (): void {
    DB::flushQueryLog();
    DB::enableQueryLog();
    $state = app(LegalDocumentAdminService::class)->state();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($state['documents'])->toHaveCount(4)
        ->and(array_column($state['documents'], '_label'))->toBe(array_map(
            fn (LegalDocumentCode $code): string => $code->label(),
            LegalDocumentCode::cases(),
        ))
        ->and(collect($queries)->filter(
            fn (array $query): bool => str_contains($query['query'], 'legal_documents'),
        ))->toHaveCount(1);
});

test('direct legal service rejects manager before any write', function (): void {
    $service = app(LegalDocumentAdminService::class);
    $manager = User::factory()->manager()->create();
    $before = $service->state();

    expect(fn () => $service->save($manager, $before))->toThrow(AuthorizationException::class);
    expect($service->state())->toBe($before);
});
