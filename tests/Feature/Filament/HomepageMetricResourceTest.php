<?php

use App\Enums\HomepageMetricCode;
use App\Filament\Resources\HomepageMetrics\HomepageMetricResource;
use App\Filament\Resources\HomepageMetrics\Pages\EditHomepageMetric;
use App\Filament\Resources\HomepageMetrics\Pages\ListHomepageMetrics;
use App\Models\HomepageMetric;
use App\Models\User;
use App\Policies\HomepageMetricPolicy;
use Database\Seeders\HomepageContentSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(HomepageContentSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('homepage metric resource is registered without create or delete operations', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $metric = HomepageMetric::query()->firstOrFail();

    expect(Filament::getPanel('admin')->getResources())->toContain(HomepageMetricResource::class)
        ->and(HomepageMetricResource::getNavigationGroup())->toBe('Главная страница')
        ->and(HomepageMetricResource::getNavigationLabel())->toBe('Показатели')
        ->and(array_keys(HomepageMetricResource::getPages()))->toBe(['index', 'view', 'edit'])
        ->and(app('Illuminate\Contracts\Auth\Access\Gate')->getPolicyFor(HomepageMetric::class))->toBeInstanceOf(HomepageMetricPolicy::class);

    Livewire::test(ListHomepageMetrics::class)
        ->assertCanSeeTableRecords(HomepageMetric::query()->ordered()->get())
        ->assertTableColumnExists('code')
        ->assertTableColumnExists('prefix')
        ->assertTableColumnExists('value')
        ->assertTableColumnExists('suffix')
        ->assertTableColumnExists('text')
        ->assertTableColumnExists('is_active')
        ->assertTableColumnExists('position')
        ->assertTableColumnExists('updated_at')
        ->assertTableFilterExists('is_active')
        ->assertTableActionDoesNotExist('delete', record: $metric)
        ->assertTableBulkActionDoesNotExist('delete');
    Livewire::test(EditHomepageMetric::class, ['record' => $metric->getKey()])->assertActionDoesNotExist(DeleteAction::class);
});

test('homepage metric edits toggles and reorders through the aggregate service', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $metric = HomepageMetric::query()->where('code', HomepageMetricCode::SinceYear)->firstOrFail();

    Livewire::test(EditHomepageMetric::class, ['record' => $metric->getKey()])
        ->fillForm(['prefix' => 'с', 'value' => '2015', 'suffix' => 'г.', 'text' => 'Новая экспертиза', 'position' => 15, 'is_active' => true])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($metric->refresh()->value)->toBe('2015')->and($metric->text)->toBe('Новая экспертиза');

    Livewire::test(ListHomepageMetrics::class)->callTableAction('toggle_active', $metric);
    expect($metric->refresh()->is_active)->toBeFalse();
    $ids = HomepageMetric::query()->ordered()->pluck('id')->reverse()->values()->all();
    Livewire::test(ListHomepageMetrics::class)->call('reorderTable', $ids)->assertHasNoErrors();
    expect(HomepageMetric::query()->ordered()->pluck('id')->all())->toBe($ids);
});

test('homepage metric forged livewire mutation and manager writes are denied', function (): void {
    $metric = HomepageMetric::query()->where('code', HomepageMetricCode::SinceYear)->firstOrFail();
    $before = $metric->getAttributes();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EditHomepageMetric::class, ['record' => $metric->getKey()])
        ->set('data.code', HomepageMetricCode::ItemsSold->value)
        ->call('save')
        ->assertHasFormErrors(['code']);
    expect($metric->fresh()->getAttributes())->toEqual($before);

    $this->actingAs(User::factory()->manager()->create());
    $this->get(HomepageMetricResource::getUrl('index'))->assertOk();
    $this->get(HomepageMetricResource::getUrl('view', ['record' => $metric]))->assertOk();
    expect($this->get(HomepageMetricResource::getUrl('edit', ['record' => $metric]))->getStatusCode())->not->toBe(200);
    Livewire::test(ListHomepageMetrics::class)
        ->assertTableActionVisible('view', $metric)
        ->assertTableActionHidden('edit', $metric)
        ->assertTableActionHidden('toggle_active', $metric);
    Livewire::test(ListHomepageMetrics::class)
        ->call('reorderTable', HomepageMetric::query()->pluck('id')->all())
        ->assertForbidden();
    expect($metric->fresh()->getAttributes())->toEqual($before);
});
