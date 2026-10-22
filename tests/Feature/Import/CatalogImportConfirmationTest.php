<?php

use App\Enums\ImportRunStatus;
use App\Filament\Pages\CatalogImportPage;
use App\Jobs\CatalogImportChunkJob;
use App\Jobs\CatalogImportCsvChunkJob;
use App\Jobs\CatalogImportCsvStartJob;
use App\Jobs\CatalogImportStartJob;
use App\Models\CatalogConfiguration;
use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Catalog\CatalogConfigurationInstallation;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogConfigurationSource;
use App\Services\ImportStatusService;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();
    Http::preventStrayRequests();
    $this->seed([ProductCatalogSeeder::class, ProductOptionSeeder::class, PartTypeSeeder::class]);
    $this->admin = User::factory()->admin()->create();
    $this->installation = app(CatalogConfigurationInstallation::class);
    Storage::disk('local')->put('baseline.csv', confirmationCsv('900'));
    app(CatalogConfigurations::class)->install(Storage::disk('local')->path('baseline.csv'));
    $type = PartType::where('full_slug', 'usilitel')->firstOrFail();
    $product = Product::factory()->forPartType($type)->create(['status' => 'active', 'import_source' => 'catalog', 'import_key' => 'catalog:confirmation:existing']);
    // A historical imported product needs real applicability for FULL absence proof.
    // An orphan must instead be preserved and may block changes to its shared matrix.
    $make = VehicleMake::factory()->create(['title' => 'Review', 'norm_key' => 'review']);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Legacy', 'norm_key' => 'legacy']);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => '1',
        'norm_key' => '1-1980-1985-sedan', 'years_label' => '1980-1985', 'body' => 'Седан', 'platform' => null, 'restyling' => null]);
    DB::table('product_fitments')->insert(['product_id' => $product->id, 'vehicle_generation_id' => $generation->id, 'is_primary' => true]);
    // Imported variants must be created through the real CSV synchronization path.
    app(CatalogConfigurations::class)->synchronize($product, 'test:confirmation-baseline');
    expect($product->variants()->count())->toBe(1);
    ImportRun::factory()->create(['status' => ImportRunStatus::Done, 'import_mode' => 'excel',
        'detail_columns' => [['part_type_full_slug' => 'usilitel', 'part_type_full_title' => 'Усилитель']]]);
});

function confirmationCsv(string $left, bool $withRight = false): string
{
    return "Название,Профиль,Положение,Материал,Толщина металла,Цена\nУсилитель,-,Левый,ХКС,1 мм,{$left}\n"
        .($withRight ? "Усилитель,-,Правый,ХКС,1 мм,1200\n" : '');
}

function confirmationWorkbook(): string
{
    $path = Storage::disk('local')->path('confirmation.xlsx');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Каталог (new)');
    $writer->addRow(Row::fromValues([null, null, null, null, null, null, 'Усилитель']));
    $writer->addRow(Row::fromValues(['Фото', 'Марка', 'Модель', 'Поколение', 'Годы выпуска', 'Кузов', 'Усилитель']));
    $writer->addRow(Row::fromValues([null, 'Acura', 'MDX', '2', '2006–2013', 'Внедорожник', 1]));
    $writer->close();

    return $path;
}

function confirmationRun(string $mode): ImportRun
{
    Storage::disk('local')->put('confirmation.csv', confirmationCsv('1000', true));
    $book = confirmationWorkbook();
    $primary = $mode === 'csv' ? 'confirmation.csv' : basename($book);

    return ImportRun::factory()->create(['import_mode' => $mode, 'stored_path' => $primary,
        'file_hash' => hash_file('sha256', Storage::disk('local')->path($primary)),
        'configuration_path' => 'confirmation.csv',
        'configuration_hash' => hash_file('sha256', Storage::disk('local')->path('confirmation.csv'))]);
}

function confirmationCatalogState(): array
{
    return collect(['catalog_configurations', 'catalog_matrix_sources', 'products', 'product_variants'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

function executeConfirmationStart(ImportRun $run): void
{
    app(ImportStatusService::class)->start($run);
    $job = $run->import_mode === 'csv' ? new CatalogImportCsvStartJob($run->id) : new CatalogImportStartJob($run->id);
    app()->call([$job, 'handle']);
}

test('jobs cannot install any CSV without persisted administrator consent', function (string $mode) {
    $run = confirmationRun($mode);
    $before = confirmationCatalogState();
    executeConfirmationStart($run);
    expect($run->fresh()->status)->toBe(ImportRunStatus::Failed)
        ->and($run->fresh()->last_error)->toContain('подтверждения администратора')
        ->and($run->fresh()->configuration_confirmed_at)->toBeNull()
        ->and(confirmationCatalogState())->toBe($before);
    Queue::assertNotPushed(CatalogImportChunkJob::class);
    Queue::assertNotPushed(CatalogImportCsvChunkJob::class);
})->with(['full', 'csv']);

test('a freshly computed token cannot substitute for administrator consent in the installer', function () {
    $run = confirmationRun('csv');
    $path = Storage::disk('local')->path($run->stored_path);
    $review = $this->installation->preview(app(CatalogConfigurationSource::class)->read($path));
    $before = confirmationCatalogState();
    expect(fn () => app(CatalogConfigurations::class)->install($path, $review['confirmation'], $run->id))
        ->toThrow(ValidationException::class, 'подтверждения администратора');
    expect(confirmationCatalogState())->toBe($before);
});

test('a changed CSV or matrix after approval rejects the job without catalog changes', function (string $mode, string $change) {
    $run = confirmationRun($mode);
    $review = $this->installation->previewImport($run);
    $this->installation->confirmImport($run, $this->admin, $review['confirmation']);
    if ($change === 'csv') {
        Storage::disk('local')->put('confirmation.csv', confirmationCsv('1500', true));
    } else {
        CatalogConfiguration::firstOrFail()->update(['price' => '850.00']);
    }
    $before = confirmationCatalogState();
    executeConfirmationStart($run);
    expect($run->fresh()->status)->toBe(ImportRunStatus::Failed)
        ->and($run->fresh()->initialized_at)->toBeNull()
        ->and(confirmationCatalogState())->toBe($before);
    Queue::assertNotPushed(CatalogImportChunkJob::class);
    Queue::assertNotPushed(CatalogImportCsvChunkJob::class);
})->with([['full', 'csv'], ['full', 'matrix'], ['csv', 'csv'], ['csv', 'matrix']]);

test('current persisted approval installs the matrix and queues the normal next stage once', function (string $mode) {
    $run = confirmationRun($mode);
    $review = $this->installation->previewImport($run);
    expect($review['added'])->toHaveCount(1)->and($review['price_conflicts'])->toHaveCount(1);
    $approved = $this->installation->confirmImport($run, $this->admin, $review['confirmation']);
    expect($approved->configuration_confirmed_by)->toBe($this->admin->id)
        ->and($approved->configuration_confirmed_at)->not->toBeNull()
        ->and($approved->configuration_confirmed_hash)->toBe($run->configuration_hash)
        ->and($approved->configuration_review)->toBe($review);
    $ids = ProductVariant::orderBy('id')->pluck('id')->all();
    executeConfirmationStart($run);
    expect($run->fresh()->status)->toBe(ImportRunStatus::RunningRows)
        ->and($run->fresh()->initialized_at)->not->toBeNull()
        ->and(CatalogConfiguration::where('is_active', true)->orderBy('position')->pluck('price')->all())->toBe(['1000.00', '1200.00'])
        ->and(ProductVariant::orderBy('id')->pluck('id')->all())->toBe($ids);
    $before = confirmationCatalogState();
    executeConfirmationStart($run);
    expect(confirmationCatalogState())->toBe($before);
    Queue::assertPushed($mode === 'csv' ? CatalogImportCsvChunkJob::class : CatalogImportChunkJob::class, 1);
})->with(['full', 'csv']);

test('an approval is bound to its run and cannot be copied to another run', function () {
    $first = confirmationRun('csv');
    $review = $this->installation->previewImport($first);
    $first = $this->installation->confirmImport($first, $this->admin, $review['confirmation']);
    $other = confirmationRun('csv');
    $other->forceFill($first->only(['configuration_review', 'configuration_confirmed_hash', 'configuration_confirmed_by', 'configuration_confirmed_at']))->save();
    $before = confirmationCatalogState();
    executeConfirmationStart($other);
    expect($other->fresh()->status)->toBe(ImportRunStatus::Failed)->and(confirmationCatalogState())->toBe($before);
});

test('upload shows the review without starting full or CSV runs even with auto start selected', function (string $mode) {
    $this->actingAs($this->admin);
    $book = confirmationWorkbook();
    $page = Livewire::test(CatalogImportPage::class)->assertSet('importMode', 'full')->set('importMode', $mode)
        ->set('startAfterUpload', true)
        ->set('configurationFile', UploadedFile::fake()->createWithContent('config.csv', confirmationCsv('1000', true)));
    if ($mode === 'full') {
        $page->set('file', UploadedFile::fake()->createWithContent('catalog.xlsx', file_get_contents($book)));
    }
    $page->call('submitImport')->assertHasNoErrors()->assertSee('Подтверждение изменений CSV')
        ->assertSee('Добавляемые конфигурации')->assertSee('Отключаемые конфигурации')
        ->assertSee('Восстанавливаемые конфигурации')->assertSee('Изменения цен конфигураций')
        ->assertSee('Он не прогнозирует количество создаваемых');
    $run = ImportRun::where('import_mode', $mode)->latest('id')->firstOrFail();
    expect($run->status)->toBe(ImportRunStatus::Ready)->and($run->configuration_confirmed_at)->toBeNull();
    Queue::assertNotPushed(CatalogImportStartJob::class);
    Queue::assertNotPushed(CatalogImportCsvStartJob::class);
    $page->call('start', $run->id)->assertHasNoErrors();
    expect($run->fresh()->status)->toBe(ImportRunStatus::Ready);
    $page->call('confirmConfigurationImport')->assertHasNoErrors();
    expect($run->fresh()->configuration_confirmed_by)->toBe($this->admin->id)
        ->and($run->fresh()->status)->toBe(ImportRunStatus::RunningRows);
    Queue::assertPushed($mode === 'csv' ? CatalogImportCsvStartJob::class : CatalogImportStartJob::class, 1);
})->with(['full', 'csv']);

test('users without import permission cannot confirm through the service or page', function () {
    $run = confirmationRun('csv');
    $review = $this->installation->previewImport($run);
    $customer = User::factory()->create();
    $before = confirmationCatalogState();
    expect(fn () => $this->installation->confirmImport($run, $customer, $review['confirmation']))->toThrow(HttpException::class);
    $this->actingAs($customer);
    Livewire::test(CatalogImportPage::class)->assertForbidden();
    expect($run->fresh()->configuration_confirmed_at)->toBeNull()->and(confirmationCatalogState())->toBe($before);
});
