<?php

use App\Enums\NavigationLinkType;
use App\Enums\NavigationZone;
use App\Models\SiteNavigationItem;
use App\Models\User;
use App\Services\Settings\ShopSettingsService;
use App\Services\Settings\SiteNavigationAdminService;
use App\Services\Storefront\GlobalStorefrontDataProvider;
use App\ViewData\Storefront\GlobalStorefrontData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function responsiveContractNodes(string $html, string $query): DOMNodeList
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

    return (new DOMXPath($document))->query($query);
}

function responsiveContractLinks(string $html, string $query): array
{
    return array_map(fn (DOMElement $link): array => [
        'title' => trim($link->textContent),
        'url' => $link->getAttribute('href'),
        'target' => $link->getAttribute('target'),
        'rel' => $link->getAttribute('rel'),
    ], iterator_to_array(responsiveContractNodes($html, $query)));
}

function assertResponsiveHeaderContract(GlobalStorefrontData $data): array
{
    $html = Blade::render('<x-header :storefront="$data" />', ['data' => $data]);
    $desktop = responsiveContractLinks($html, '//a[@class="header__util-link" or @class="header__nav-link"]');
    $mobile = responsiveContractLinks($html, '//nav[@class="mobile-menu__nav"]/a');
    $expected = array_map(fn ($link): array => [
        'title' => $link->title, 'url' => $link->url,
        'target' => $link->openInNewTab ? '_blank' : '',
        'rel' => $link->openInNewTab ? 'noopener noreferrer' : '',
    ], [...$data->navigationFor(NavigationZone::HeaderTop), ...$data->navigationFor(NavigationZone::HeaderMain)]);

    $configuredDesktopCount = count($expected);
    if (isset($data->homepageSections['reviews'])) {
        $expected[] = ['title' => 'Отзывы', 'url' => route('home').'#homepage-reviews', 'target' => '', 'rel' => ''];
    }
    if ($data->phoneUrl || $data->emailUrl || $data->socials !== [] || $data->workHours) {
        $expected[] = ['title' => 'Контакты', 'url' => route('home').'#footer-contacts', 'target' => '', 'rel' => ''];
    }
    $expectedMobile = array_map(fn ($link): array => [
        'title' => $link->title, 'url' => $link->url,
        'target' => $link->openInNewTab ? '_blank' : '',
        'rel' => $link->openInNewTab ? 'noopener noreferrer' : '',
    ], $data->navigationFor(NavigationZone::Mobile));
    expect($desktop)->toBe($expected)->and($mobile)->toBe($expectedMobile);

    return array_slice($desktop, 0, $configuredDesktopCount);
}

test('desktop follows header edits while burger follows its independent mobile zone', function (): void {
    $admin = User::factory()->admin()->create();
    $service = app(SiteNavigationAdminService::class);
    $top = $service->create($admin, [
        'code' => 'header-top', 'zone' => NavigationZone::HeaderTop->value,
        'title' => 'Первый пункт', 'link_type' => 'route', 'route_name' => 'about',
        'url' => null, 'open_in_new_tab' => false, 'is_active' => true, 'position' => 20,
    ]);
    $earlier = $service->create($admin, [
        'code' => 'earlier-top', 'zone' => NavigationZone::HeaderTop->value,
        'title' => 'Перед первым', 'link_type' => 'route', 'route_name' => 'how',
        'url' => null, 'open_in_new_tab' => false, 'is_active' => true, 'position' => 10,
    ]);
    $main = $service->create($admin, [
        'code' => 'header-main', 'zone' => NavigationZone::HeaderMain->value,
        'title' => 'Каталог', 'link_type' => 'route', 'route_name' => 'catalog.index',
        'url' => null, 'open_in_new_tab' => false, 'is_active' => true, 'position' => 1,
    ]);
    foreach ([NavigationZone::Mobile, NavigationZone::FooterAbout] as $zone) {
        SiteNavigationItem::factory()->create([
            'zone' => $zone, 'title' => 'Устаревшая копия', 'link_type' => NavigationLinkType::Route,
            'route_name' => 'about', 'url' => null, 'is_active' => true,
        ]);
    }
    $provider = app(GlobalStorefrontDataProvider::class);
    $links = assertResponsiveHeaderContract($provider->load());
    expect(array_column($links, 'title'))->toBe(['Перед первым', 'Первый пункт', 'Каталог']);

    $service->update($admin, $top, ['title' => 'О компании', 'position' => 0, 'open_in_new_tab' => true]);
    $service->update($admin, $main, [
        'link_type' => 'url', 'route_name' => null,
        'url' => 'https://example.test/updated-catalog', 'open_in_new_tab' => true,
    ]);
    $links = assertResponsiveHeaderContract($provider->load());
    expect(array_column($links, 'title'))->toBe(['О компании', 'Перед первым', 'Каталог'])
        ->and($links[0]['target'])->toBe('_blank')
        ->and($links[0]['rel'])->toBe('noopener noreferrer')
        ->and($links[2]['url'])->toBe('https://example.test/updated-catalog')
        ->and($links[2]['target'])->toBe('_blank')
        ->and($links[2]['rel'])->toBe('noopener noreferrer');

    $service->update($admin, $top, ['is_active' => false]);
    $service->update($admin, $main, ['link_type' => 'route', 'route_name' => 'payment', 'url' => null, 'open_in_new_tab' => false]);
    $links = assertResponsiveHeaderContract($provider->load());
    expect(array_column($links, 'title'))->toBe(['Перед первым', 'Каталог'])
        ->and($links[1]['url'])->toBe(route('payment'))
        ->and($links[1]['target'])->toBe('')
        ->and(SiteNavigationItem::query()->count())->toBe(5);

    $service->update($admin, $earlier, ['is_active' => false]);
    $service->update($admin, $main, ['is_active' => false]);
    expect(assertResponsiveHeaderContract($provider->load()))->toBe([]);
});

test('header and both footers share global data without extra breakpoint queries', function (): void {
    app(ShopSettingsService::class)->current();
    SiteNavigationItem::factory()->count(5)->create(['zone' => NavigationZone::HeaderTop, 'is_active' => true]);
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $data = app(GlobalStorefrontData::class);
        $queries = DB::getQueryLog();
        expect(count($queries))->toBe(4);
        assertResponsiveHeaderContract($data);
        Blade::render('<x-footer :storefront="$data" />', ['data' => $data]);
        expect(DB::getQueryLog())->toBe($queries)
            ->and(app(GlobalStorefrontData::class))->toBe($data);
    } finally {
        DB::disableQueryLog();
    }
});

test('MAX footer uses the saved URL and explicit icons in both responsive layouts', function (): void {
    $admin = User::factory()->admin()->create();
    app(ShopSettingsService::class)->update($admin, [
        'vk_url' => 'https://vk.example.test/shop',
        'telegram_url' => 'https://telegram.example.test/shop',
        'max_url' => 'https://max.example.test/shop',
    ]);
    $data = app(GlobalStorefrontDataProvider::class)->load();
    expect($data->socials)->toBe([
        ['code' => 'vk', 'label' => 'ВКонтакте', 'url' => 'https://vk.example.test/shop'],
        ['code' => 'telegram', 'label' => 'Telegram', 'url' => 'https://telegram.example.test/shop'],
        ['code' => 'max', 'label' => 'MAX', 'url' => 'https://max.example.test/shop'],
    ]);
    $html = Blade::render('<x-footer :storefront="$data" />', ['data' => $data]);
    foreach (['desktop', 'mobile'] as $layout) {
        $nodes = responsiveContractNodes($html, '//div[@class="footer__'.$layout.'"]//a[@class="footer__social"]');
        expect($nodes->length)->toBe(3);
        foreach (iterator_to_array($nodes) as $index => $link) {
            expect($link->getAttribute('href'))->toBe($data->socials[$index]['url'])
                ->and($link->getAttribute('aria-label'))->toBe($data->socials[$index]['label'])
                ->and($link->getAttribute('target'))->toBe('_blank')
                ->and($link->getAttribute('rel'))->toBe('noopener noreferrer')
                ->and($link->getElementsByTagName('img')->item(0)->getAttribute('src'))
                ->toBe(['/img/icons/vk.svg', '/img/icons/tg.svg', '/img/icons/max.svg'][$index]);
        }
    }
    expect(file_exists(public_path('img/icons/max.svg')))->toBeTrue();
});

test('empty or unsafe stored MAX never appears in the footer', function (?string $url): void {
    app(ShopSettingsService::class)->current()->update(['max_url' => $url]);
    $data = app(GlobalStorefrontDataProvider::class)->load();
    expect($data->socials)->toBe([]);
    $html = Blade::render('<x-footer :storefront="$data" />', ['data' => $data]);
    expect($html)->not->toContain('aria-label="MAX"', '/img/icons/max.svg');
})->with([null, '', 'javascript:alert(1)', '//example.test/shop']);

test('burger template uses mobile zone and has no hardcoded information destinations', function (): void {
    $source = file_get_contents(resource_path('views/components/mobile-menu.blade.php'));
    expect($source)->toContain('NavigationZone::Mobile')->not->toContain('NavigationZone::HeaderTop', 'NavigationZone::HeaderMain');
    foreach (['about', 'how', 'partners', 'payment', 'faq'] as $page) {
        expect($source)->not->toContain("route('{$page}')", 'href="/'.$page.'"');
    }
});
