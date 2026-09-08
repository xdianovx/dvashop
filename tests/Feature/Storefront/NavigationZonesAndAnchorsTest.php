<?php

use App\Enums\HomepageSectionCode;
use App\Enums\NavigationZone;
use App\Filament\Resources\SiteNavigationItems\Pages\CreateSiteNavigationItem;
use App\Filament\Resources\SiteNavigationItems\Pages\EditSiteNavigationItem;
use App\Models\HomepageSection;
use App\Models\SiteNavigationItem;
use App\Models\User;
use App\Services\Settings\ShopSettingsService;
use App\Services\Settings\SiteNavigationAdminService;
use App\Services\Storefront\GlobalStorefrontDataProvider;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('desktop system anchors match sections and follow homepage reviews setting on every route', function (): void {
    app(ShopSettingsService::class)->current();
    $section = HomepageSection::query()->where('code', HomepageSectionCode::Reviews)->firstOrFail();
    foreach ([true, false] as $active) {
        $section->update(['is_active' => $active]);
        $home = $this->get(route('home'))->assertOk();
        $html = $home->getContent();
        expect(str_contains($html, 'href="#homepage-reviews"'))->toBe($active)
            ->and(str_contains($html, 'id="homepage-reviews"'))->toBe($active)
            ->and($html)->toContain('href="#footer-contacts"', 'id="footer-contacts"');
        foreach (['cart.show', 'about'] as $route) {
            $html = $this->get(route($route))->assertOk()->getContent();
            expect(str_contains($html, 'href="'.route('home').'#homepage-reviews"'))->toBe($active)
                ->and($html)->toContain('href="'.route('home').'#footer-contacts"');
        }
    }
});

test('mobile zone controls burger order destination active state and new tab independently of desktop', function (): void {
    $actor = User::factory()->admin()->create();
    $service = app(SiteNavigationAdminService::class);
    SiteNavigationItem::query()->delete();
    $main = SiteNavigationItem::factory()->create(['zone' => NavigationZone::HeaderMain, 'title' => 'Только desktop', 'is_active' => true]);
    $first = $service->create($actor, ['code' => 'mobile-first', 'zone' => 'mobile', 'title' => 'Первый', 'link_type' => 'route', 'route_name' => 'about', 'position' => 20]);
    $second = $service->create($actor, ['code' => 'mobile-second', 'zone' => 'mobile', 'title' => 'Второй', 'link_type' => 'route', 'route_name' => 'how', 'position' => 10]);
    $render = fn (): string => Blade::render('<x-mobile-menu :storefront="$data" />', ['data' => app(GlobalStorefrontDataProvider::class)->load()]);
    expect($render())->not->toContain('Только desktop');
    expect(strpos($render(), '>Второй<'))->toBeLessThan(strpos($render(), '>Первый<'));
    $service->update($actor, $first, ['title' => 'Обновлённый', 'position' => 0, 'link_type' => 'url', 'route_name' => null, 'url' => 'https://example.test/mobile', 'open_in_new_tab' => true]);
    $html = $render();
    expect($html)->toContain('href="https://example.test/mobile"', 'target="_blank" rel="noopener noreferrer"', '>Обновлённый<')
        ->and(strpos($html, '>Обновлённый<'))->toBeLessThan(strpos($html, '>Второй<'));
    $service->setActive($actor, $first, false);
    expect($render())->not->toContain('Обновлённый');
    $service->delete($actor, $second);
    expect($render())->not->toContain('Второй', 'Только desktop');
});

test('legacy footer documents zone hydrates but is absent from choices and storefront', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $legacy = SiteNavigationItem::factory()->create(['zone' => NavigationZone::FooterDocuments, 'title' => 'Старая зона документов', 'is_active' => true]);
    foreach ([CreateSiteNavigationItem::class => [], EditSiteNavigationItem::class => ['record' => $legacy->id]] as $page => $params) {
        Livewire::test($page, $params)->assertFormFieldExists('zone', fn (Select $field): bool => ! array_key_exists('footer_documents', $field->getOptions()));
    }
    expect($legacy->refresh()->zone)->toBe(NavigationZone::FooterDocuments)
        ->and(NavigationZone::options())->not->toHaveKey('footer_documents');
    $data = app(GlobalStorefrontDataProvider::class)->load();
    expect($data->navigationFor(NavigationZone::FooterDocuments))->toBe([]);
    expect(Blade::render('<x-footer :storefront="$data" /><x-mobile-menu :storefront="$data" />', compact('data')))->not->toContain('Старая зона документов');
});

test('navigation query count stays fixed with many mobile items and rendering adds no queries', function (): void {
    $measure = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $data = app(GlobalStorefrontDataProvider::class)->load();
            $queries = DB::getQueryLog();
            Blade::render('<x-header :storefront="$data" /><x-footer :storefront="$data" />', compact('data'));
            expect(DB::getQueryLog())->toBe($queries);

            return count($queries);
        } finally {
            DB::disableQueryLog();
        }
    };
    $one = $measure();
    SiteNavigationItem::factory()->count(20)->create(['zone' => NavigationZone::Mobile, 'is_active' => true]);
    expect($measure())->toBe($one);
});
