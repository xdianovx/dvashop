<?php

use App\Enums\ImportLogLevel;
use App\Enums\ImportRunStatus;
use App\Enums\UserRole;
use App\Filament\Pages\CatalogImportPage;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\ImportLogger;
use App\Services\ImportRunReportExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function presentationStreamContent(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

test('Validation uses Russian messages and never renders raw translation keys', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CatalogImportPage::class)
        ->call('submitImport')
        ->assertHasErrors(['file' => 'required'])
        ->assertSee('Выберите файл импорта.')
        ->assertDontSee('validation.required');

    Livewire::test(CatalogImportPage::class)
        ->set('file', UploadedFile::fake()->createWithContent('catalog.txt', 'not a spreadsheet'))
        ->call('submitImport')
        ->assertHasErrors(['file' => 'mimes'])
        ->assertSee('Файл должен быть в формате CSV или XLSX.')
        ->assertDontSee('validation.mimes');

    Livewire::test(CatalogImportPage::class)
        ->set('file', UploadedFile::fake()->createWithContent('catalog.csv', "header\nvalue\n"))
        ->set('chunkSize', 200)
        ->call('submitImport')
        ->assertHasErrors(['chunkSize' => 'in'])
        ->assertSee('Укажите корректный размер чанка.')
        ->assertDontSee('validation.in');
});

test('Validation rejects an unsafe source with a Russian message', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CatalogImportPage::class)
        ->set('file', UploadedFile::fake()->createWithContent('catalog.csv', "header\nvalue\n"))
        ->set('type', '../catalog')
        ->call('submitImport')
        ->assertHasErrors(['type' => 'regex'])
        ->assertSee('Укажите корректный источник импорта.');
});

test('Validation page presents the accessible import workflow and empty history state', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/admin/imports/catalog')
        ->assertOk()
        ->assertSee('Новый импорт каталога')
        ->assertSee('Перетащите файл сюда')
        ->assertSee('Выбрать файл')
        ->assertSee('CSV или XLSX, до 50 МБ')
        ->assertSee('Дополнительные настройки')
        ->assertSee('Запустить импорт сразу после загрузки')
        ->assertSee('История импортов')
        ->assertSee('Импортов пока нет')
        ->assertDontSee('validation.required');
});

test('Validation selected file card can be cleared without creating an import run', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CatalogImportPage::class)
        ->set('file', UploadedFile::fake()->createWithContent('catalog.csv', "header\nvalue\n"))
        ->assertSee('catalog.csv')
        ->assertSee('Готов к загрузке')
        ->assertSee('Заменить файл')
        ->assertSee('Загрузить и запустить импорт')
        ->set('startAfterUpload', false)
        ->assertSee('Только загрузить файл')
        ->call('removeSelectedFile')
        ->assertSet('file', null)
        ->assertHasNoErrors(['file'])
        ->assertSee('Перетащите файл сюда');

    expect(ImportRun::query()->count())->toBe(0);
});

test('Validation active import view renders three progress sections and only compact problems', function () {
    $this->actingAs(User::factory()->admin()->create());
    $run = ImportRun::factory()->create([
        'status' => ImportRunStatus::RunningRows,
        'total_rows' => 10,
        'processed_rows' => 4,
        'queued_images' => 2,
    ]);
    $logger = app(ImportLogger::class);
    $logger->info($run, 'Обычная техническая запись');
    $logger->warning($run, 'Краткое предупреждение');

    $this->get('/admin/imports/catalog')
        ->assertOk()
        ->assertSee('Общий прогресс')
        ->assertSee('Обработка строк')
        ->assertSee('Обработка изображений')
        ->assertSee($run->original_name)
        ->assertSee('Обработка строк')
        ->assertSee('Обработано строк: 4 из 10')
        ->assertSee('Обработано изображений: 0 из 2; ошибок: 0')
        ->assertSee('Краткое предупреждение')
        ->assertSee('Открыть логи')
        ->assertSee('Скачать отчёт')
        ->assertSee('Подробности')
        ->assertSee('Ещё');
});

test('Validation Filament admin theme is registered and scans custom page classes', function () {
    $themePath = resource_path('css/filament/admin/theme.css');
    $theme = file_get_contents($themePath) ?: '';
    $provider = file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php')) ?: '';
    $vite = file_get_contents(base_path('vite.config.js')) ?: '';

    expect(file_exists($themePath))->toBeTrue()
        ->and($provider)->toContain("->viteTheme('resources/css/filament/admin/theme.css')")
        ->and($vite)->toContain("'resources/css/filament/admin/theme.css'")
        ->and($theme)->toContain("@import '../../../../vendor/filament/filament/resources/css/theme.css';")
        ->and($theme)->toMatch("/@source\s+'\.\.\/\.\.\/\.\.\/\.\.\/app\/Filament\/\*\*\/\*';/")
        ->and($theme)->toMatch("/@source\s+'\.\.\/\.\.\/\.\.\/\.\.\/resources\/views\/filament\/\*\*\/\*';/");
});

test('Report CSV has BOM Russian labels archive reason and works without logs', function () {
    $run = ImportRun::factory()->create([
        'status' => ImportRunStatus::Done,
        'total_rows' => 12,
        'processed_rows' => 12,
        'warnings_count' => 2,
        'errors_count' => 1,
        'archive_skipped' => true,
        'archive_skip_reason' => 'row_errors',
    ]);

    $content = presentationStreamContent(app(ImportRunReportExporter::class)->summaryCsv($run));

    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($content)->toContain('Показатель,Значение')
        ->and($content)->toContain('ID импорта')
        ->and($content)->toContain('Архивация пропущена')
        ->and($content)->toContain('Ошибки обработки строк')
        ->and($content)->toContain('Предупреждений')
        ->and($content)->toContain('Ошибок')
        ->and($content)->not->toContain('metric,value');
});

test('Report logs CSV has BOM and Russian headings', function () {
    $run = ImportRun::factory()->create();
    app(ImportLogger::class)->warning($run, 'Проверочное предупреждение', ['row' => 3]);

    $content = presentationStreamContent(app(ImportRunReportExporter::class)->logsCsv($run));

    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($content)->toContain('Дата,Уровень,Сообщение,Контекст')
        ->and($content)->toContain('Предупреждение')
        ->and($content)->toContain('Проверочное предупреждение');
});

test('Report downloads are forbidden for customers', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Customer]));
    $run = ImportRun::factory()->create();

    expect(fn () => app(CatalogImportPage::class)->downloadLogs($run->getKey()))->toThrow(HttpException::class)
        ->and(fn () => app(CatalogImportPage::class)->downloadReport($run->getKey()))->toThrow(HttpException::class);
});

test('Report latest problems keeps only three warnings and errors', function () {
    $run = ImportRun::factory()->create();
    $logger = app(ImportLogger::class);

    foreach (range(1, 5) as $index) {
        $logger->info($run, "info {$index}");
        $logger->warning($run, "warning {$index}");
    }

    $problems = app(CatalogImportPage::class)->latestProblems($run, 20);

    expect($problems)->toHaveCount(3)
        ->and($problems->pluck('level')->unique()->all())->toBe([ImportLogLevel::Warning])
        ->and($problems->first()->message)->toBe('warning 3')
        ->and($problems->last()->message)->toBe('warning 5');
});
