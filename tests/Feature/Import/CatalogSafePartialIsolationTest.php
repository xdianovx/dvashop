<?php

use App\Models\ImportRun;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Catalog\CatalogActions;
use App\Services\Catalog\CatalogConfigurationInstallation;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogConfigurationSource;
use App\Services\Catalog\CatalogImportProtections;
use App\Services\Import\ImportImageDownloader;
use App\Services\StorefrontProductAvailability;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Bus::fake();
    $this->seed([ProductCatalogSeeder::class, ProductOptionSeeder::class, PartTypeSeeder::class]);
    $this->matrix = app(CatalogConfigurations::class);
    $this->matrix->install(base_path('tests/Fixtures/catalog-configurations.csv'));
    $type = PartType::where('full_slug', 'usilitel')->firstOrFail();
    $this->product = Product::factory()->create(['part_type_id' => $type->id, 'product_category_id' => null,
        'import_source' => 'catalog', 'import_key' => 'catalog:protected-identity', 'status' => 'active',
        'price_mode' => 'inherited', 'product_option_template_id' => $this->matrix->template($type->id)->id]);
    $this->matrix->synchronize($this->product);
    $run = ImportRun::factory()->create(['import_mode' => 'safe_partial']);
    DB::transaction(fn () => app(CatalogImportProtections::class)->persist($run->id, [$this->product->id => 'unresolved_identity'], 1, str_repeat('a', 64)));
});

function safeIsolationCsv(bool $remove): string
{
    $source = fopen(base_path('tests/Fixtures/catalog-configurations.csv'), 'rb');
    $path = Storage::disk('local')->path('changed.csv');
    $dest = fopen($path, 'wb');
    $changed = false;
    while (($row = fgetcsv($source, null, ',', '"', '')) !== false) {
        if (! $changed && trim($row[0]) === 'Усилитель') {
            $changed = true;
            if ($remove) {
                continue;
            }
            $row[5] = (string) ((int) $row[5] + 777);
        }
        fputcsv($dest, $row, ',', '"', '');
    }
    fclose($source);
    fclose($dest);

    return $path;
}

test('protected product survives CSV price change attempt and every later synchronization', function (): void {
    $p = $this->product->fresh();
    $before = $p->variants()->orderBy('id')->get()->toJson();
    $csv = safeIsolationCsv(false);
    $review = app(CatalogConfigurationInstallation::class)->preview(app(CatalogConfigurationSource::class)->read($csv));
    expect(fn () => $this->matrix->install($csv, $review['confirmation']))->toThrow(ValidationException::class, 'защищённых');
    expect($this->matrix->synchronize($p, 'later:manual')['changed'])->toBeFalse()
        ->and($p->variants()->orderBy('id')->get()->toJson())->toBe($before);
});

test('protected offer stays visible when a CSV tries retiring its shared configuration', function (): void {
    $variant = $this->product->variants()->firstOrFail();
    $availability = app(StorefrontProductAvailability::class);
    expect($availability->isPubliclyAvailable($variant))->toBeTrue();
    $before = $variant->getRawOriginal();
    $csv = safeIsolationCsv(true);
    $review = app(CatalogConfigurationInstallation::class)->preview(app(CatalogConfigurationSource::class)->read($csv));
    expect(fn () => $this->matrix->install($csv, $review['confirmation']))->toThrow(ValidationException::class, 'защищённых');
    expect($availability->isPubliclyAvailable($variant->fresh()))->toBeTrue()->and($variant->fresh()->getRawOriginal())->toBe($before);
});

test('equivalent matrix only updates source metadata and preserves shared structures', function (): void {
    $before = DB::table('catalog_configurations')->orderBy('id')->get()->toJson();
    $result = $this->matrix->install(base_path('tests/Fixtures/catalog-configurations.csv'));
    expect($result['structures_unchanged'])->toBeTrue()
        ->and(DB::table('catalog_configurations')->orderBy('id')->get()->toJson())->toBe($before);
});

test('database backstop blocks administrative bulk changes to protected data', function (string $kind): void {
    $p = $this->product->fresh();
    $v = $p->variants()->with('optionValues.group')->firstOrFail();
    expect(fn () => match ($kind) {
        'variant' => DB::table('product_variants')->where('id', $v->id)->update(['price' => 1]),
        'product' => DB::table('products')->where('id', $p->id)->update(['status' => 'archived']),
        'configuration' => DB::table('catalog_configurations')->where('id', $v->catalog_configuration_id)->update(['is_active' => false]),
        'template' => DB::table('product_option_templates')->where('id', $p->product_option_template_id)->update(['is_active' => false]),
        'item' => DB::table('product_option_template_items')->where('product_option_template_id', $p->product_option_template_id)->delete(),
        'value' => DB::table('product_option_values')->where('id', $v->optionValues->first()->id)->update(['is_active' => false]),
        'group' => DB::table('product_option_groups')->where('id', $v->optionValues->first()->product_option_group_id)->update(['is_active' => false]),
        'protection' => DB::table('catalog_import_protections')->where('product_id', $p->id)->delete(),
    })->toThrow(QueryException::class, 'catalog import protection');
})->with(['variant', 'product', 'configuration', 'template', 'item', 'value', 'group', 'protection']);

test('administrative configuration actions report permanent protections before application', function (): void {
    $p = $this->product->fresh();
    $review = app(CatalogActions::class)->preview(User::factory()->superAdmin()->create(), [
        'kind' => 'configurations', 'type_id' => $p->part_type_id, 'descendants' => false,
        'source_status' => 'all', 'target_status' => 'active', 'force' => false, 'search' => (string) $p->id,
    ]);
    expect($review['counts']['protected'])->toBe(1)->and($review['counts']['changed'])->toBe(0);
});

test('image download and physical file deletion stop before touching protected media', function (): void {
    Http::preventStrayRequests();
    $p = $this->product->fresh();
    expect(fn () => app(ImportImageDownloader::class)->download($p, 'https://example.com/car.jpg'))
        ->toThrow(ValidationException::class, 'постоянную защиту');
    Storage::disk('public')->put('protected.jpg', 'retained');
    $image = new ProductImage(['product_id' => $p->id, 'path' => 'protected.jpg', 'disk' => 'public']);
    expect(fn () => $image->deleteFiles())->toThrow(ValidationException::class, 'постоянную защиту');
    Storage::disk('public')->assertExists('protected.jpg');
    Http::assertNothingSent();
});

test('a protected variant also freezes a configuration linked from another historical type', function (): void {
    $p = Product::factory()->create(['part_type_id' => $this->product->part_type_id, 'import_source' => 'catalog']);
    $this->matrix->synchronize($p);
    $configuration = \App\Models\CatalogConfiguration::where('part_type_id', '!=', $p->part_type_id)->firstOrFail();
    $v = $p->variants()->firstOrFail();
    DB::table('product_variants')->where('id', $v->id)->update(['catalog_configuration_id' => $configuration->id]);
    $run = ImportRun::factory()->create(['import_mode' => 'safe_partial']);
    DB::transaction(fn () => app(CatalogImportProtections::class)->persist($run->id, [$p->id => 'historical_relation'], 1, str_repeat('b', 64)));
    expect(fn () => DB::table('catalog_configurations')->where('id', $configuration->id)->update(['is_active' => false]))
        ->toThrow(\Illuminate\Database\QueryException::class, 'protection');
    expect(fn () => DB::table('part_types')->where('id', $configuration->part_type_id)->update(['is_active' => false]))
        ->toThrow(\Illuminate\Database\QueryException::class, 'protection');
});
