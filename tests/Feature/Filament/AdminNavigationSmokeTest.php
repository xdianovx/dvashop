<?php

use App\Filament\Pages\CatalogImportPage;
use App\Filament\Pages\ShopSettingsPage;
use App\Filament\Pages\SiteContent\EditAboutPage;
use App\Filament\Pages\SiteContent\EditFaqPage;
use App\Filament\Pages\SiteContent\EditHomepagePage;
use App\Filament\Pages\SiteContent\EditHowPage;
use App\Filament\Pages\SiteContent\EditPartnersPage;
use App\Filament\Pages\SiteContent\EditPaymentPage;
use App\Filament\Pages\SitePagesPage;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\PartTypes\PartTypeResource;
use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Filament\Resources\ProductOptionGroups\ProductOptionGroupResource;
use App\Filament\Resources\ProductOptionGroups\RelationManagers\ValuesRelationManager;
use App\Filament\Resources\ProductOptionTemplates\ProductOptionTemplateResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\SiteNavigationItems\SiteNavigationItemResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\VehicleGenerations\VehicleGenerationResource;
use App\Filament\Resources\VehicleMakes\VehicleMakeResource;
use App\Filament\Resources\VehicleModels\VehicleModelResource;
use App\Models\Order;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\SiteNavigationItem;
use App\Models\User;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Database\Seeders\FaqSeeder;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

function siteContentEditorPages(): array
{
    return [
        EditHomepagePage::class,
        EditAboutPage::class,
        EditHowPage::class,
        EditPaymentPage::class,
        EditFaqPage::class,
        EditPartnersPage::class,
    ];
}

function oldTechnicalResourceRoutePrefixes(): array
{
    return [
        'filament.admin.resources.delivery-method-settings.',
        'filament.admin.resources.faq-categories.',
        'filament.admin.resources.faq-items.',
        'filament.admin.resources.homepage-category-cards.',
        'filament.admin.resources.homepage-metrics.',
        'filament.admin.resources.homepage-quick-links.',
        'filament.admin.resources.homepage-sections.',
        'filament.admin.resources.payment-method-settings.',
        'filament.admin.resources.static-pages.',
        'filament.admin.resources.static-page-sections.',
        'filament.admin.resources.static-page-items.',
    ];
}

function oldTechnicalResourceUriPrefixes(): array
{
    return [
        'admin/delivery-method-settings',
        'admin/faq-categories',
        'admin/faq-items',
        'admin/homepage-category-cards',
        'admin/homepage-metrics',
        'admin/homepage-quick-links',
        'admin/homepage-sections',
        'admin/payment-method-settings',
        'admin/static-pages',
        'admin/static-page-sections',
        'admin/static-page-items',
    ];
}

test('filament discovery contains no deleted technical resources and every discovered class is classified', function (): void {
    $panel = Filament::getPanel('admin');
    $resources = $panel->getResources();
    $pages = $panel->getPages();
    $widgets = $panel->getWidgets();

    sort($resources);
    sort($pages);
    sort($widgets);

    $expectedResources = [
        OrderResource::class,
        PartTypeResource::class,
        ProductCategoryResource::class,
        ProductOptionGroupResource::class,
        ProductOptionTemplateResource::class,
        ProductResource::class,
        SiteNavigationItemResource::class,
        UserResource::class,
        VehicleGenerationResource::class,
        VehicleMakeResource::class,
        VehicleModelResource::class,
    ];
    $expectedPages = [
        CatalogImportPage::class,
        Dashboard::class,
        ShopSettingsPage::class,
        SitePagesPage::class,
        ...siteContentEditorPages(),
    ];
    $expectedWidgets = [AccountWidget::class, FilamentInfoWidget::class];
    sort($expectedResources);
    sort($expectedPages);
    sort($expectedWidgets);

    expect($resources)->toBe($expectedResources)
        ->and($pages)->toBe($expectedPages)
        ->and($widgets)->toBe($expectedWidgets);

    foreach ($resources as $resource) {
        expect($resource::getRelations(), $resource)->toBe(match ($resource) {
            ProductOptionGroupResource::class => [ValuesRelationManager::class],
            default => [],
        });
    }
});

test('content navigation contains exactly one site pages item and editor children stay hidden', function (string $role): void {
    $user = match ($role) {
        'super_admin' => User::factory()->superAdmin()->create(),
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'inactive' => User::factory()->superAdmin()->inactive()->create(),
        'blocked' => User::factory()->superAdmin()->blocked()->create(),
        default => User::factory()->create(),
    };
    $this->actingAs($user);
    $response = $this->get('/admin');

    if (! in_array($role, ['super_admin', 'admin', 'manager'], true)) {
        expect($response->getStatusCode())->not->toBe(200);

        return;
    }

    $contentNavigationPages = collect(Filament::getPanel('admin')->getPages())
        ->filter(fn (string $page): bool => $page::shouldRegisterNavigation())
        ->filter(fn (string $page): bool => $page::getNavigationGroup() === 'Контент сайта')
        ->values()
        ->all();

    expect($contentNavigationPages)->toBe([SitePagesPage::class]);

    $response->assertOk()
        ->assertSee(SitePagesPage::getUrl(), false)
        ->assertSee('Страницы сайта');

    foreach (siteContentEditorPages() as $page) {
        expect($page::shouldRegisterNavigation(), $page)->toBeFalse();
        $response->assertDontSee($page::getUrl(), false);
    }
})->with([
    'super admin' => ['super_admin'],
    'admin' => ['admin'],
    'manager' => ['manager'],
    'customer' => ['customer'],
    'inactive super admin' => ['inactive'],
    'blocked super admin' => ['blocked'],
]);

test('deleted technical resource route names and urls are not registered', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    $routeNames = $routes->map(fn ($route): ?string => $route->getName())->filter()->values();
    $uris = $routes->map(fn ($route): string => $route->uri())->values();

    foreach (oldTechnicalResourceRoutePrefixes() as $prefix) {
        expect($routeNames->contains(fn (string $name): bool => str_starts_with($name, $prefix)), $prefix)->toBeFalse();
    }

    foreach (oldTechnicalResourceUriPrefixes() as $prefix) {
        expect($uris->contains(fn (string $uri): bool => $uri === $prefix || str_starts_with($uri, $prefix.'/')), $prefix)->toBeFalse();
    }
});

test('actual navigation contains only role permitted visible resource and page urls', function (string $role): void {
    $user = match ($role) {
        'super_admin' => User::factory()->superAdmin()->create(),
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'inactive' => User::factory()->superAdmin()->inactive()->create(),
        'blocked' => User::factory()->superAdmin()->blocked()->create(),
        default => User::factory()->create(),
    };
    $allResources = array_values(Filament::getPanel('admin')->getResources());
    $visibleResources = array_values(array_filter(
        $allResources,
        fn (string $resource): bool => $resource::shouldRegisterNavigation(),
    ));
    $allowedResources = match ($role) {
        'super_admin' => $visibleResources,
        'admin', 'manager' => array_values(array_filter(
            $visibleResources,
            fn (string $resource): bool => $resource !== UserResource::class,
        )),
        default => [],
    };
    $this->actingAs($user);
    $response = $this->get('/admin');

    if (! in_array($role, ['super_admin', 'admin', 'manager'], true)) {
        expect($response->getStatusCode())->not->toBe(200);

        return;
    }

    $response->assertOk();

    foreach ($allResources as $resource) {
        $url = $resource::getUrl('index');

        if (in_array($resource, $allowedResources, true)) {
            $response->assertSee($url, false);
        } else {
            $response->assertDontSee($url, false);
        }
    }

    if (in_array($role, ['super_admin', 'admin'], true)) {
        $response->assertSee(CatalogImportPage::getUrl(), false);
    } else {
        $response->assertDontSee(CatalogImportPage::getUrl(), false);
    }

    $response->assertSee(ShopSettingsPage::getUrl(), false)
        ->assertSee(SitePagesPage::getUrl(), false);
})->with([
    'super admin' => ['super_admin'],
    'admin' => ['admin'],
    'manager' => ['manager'],
    'customer' => ['customer'],
    'inactive super admin' => ['inactive'],
    'blocked super admin' => ['blocked'],
]);

test('every discovered resource and content page route follows the explicit role matrix', function (string $role): void {
    $this->seed([
        CheckoutMethodSettingsSeeder::class,
        HomepageContentSeeder::class,
        StaticPageContentSeeder::class,
        FaqSeeder::class,
    ]);

    $actor = match ($role) {
        'super_admin' => User::factory()->superAdmin()->create(),
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'inactive' => User::factory()->superAdmin()->inactive()->create(),
        'blocked' => User::factory()->superAdmin()->blocked()->create(),
        default => User::factory()->create(),
    };
    $records = [
        OrderResource::class => Order::factory()->create(),
        PartTypeResource::class => PartType::factory()->create(),
        ProductCategoryResource::class => ProductCategory::factory()->create(),
        ProductOptionGroupResource::class => ProductOptionGroup::factory()->create(),
        ProductOptionTemplateResource::class => ProductOptionTemplate::factory()->create(),
        ProductResource::class => Product::factory()->create(),
        SiteNavigationItemResource::class => SiteNavigationItem::factory()->create(),
        UserResource::class => User::factory()->create(),
        VehicleGenerationResource::class => VehicleGeneration::factory()->create(),
        VehicleMakeResource::class => VehicleMake::factory()->create(),
        VehicleModelResource::class => VehicleModel::factory()->create(),
    ];
    $resources = array_values(Filament::getPanel('admin')->getResources());

    expect(array_keys($records))->toEqualCanonicalizing($resources);
    $this->actingAs($actor);

    foreach ($resources as $resource) {
        foreach (array_keys($resource::getPages()) as $page) {
            $allowed = match ($role) {
                'super_admin' => true,
                'admin' => $resource !== UserResource::class,
                'manager' => match ($resource) {
                    ProductResource::class, OrderResource::class => true,
                    UserResource::class => false,
                    default => in_array($page, ['index', 'view'], true),
                },
                default => false,
            };
            $parameters = in_array($page, ['view', 'edit'], true)
                ? ['record' => $records[$resource]]
                : [];
            $response = $this->get($resource::getUrl($page, $parameters));

            if ($allowed) {
                $response->assertOk();
            } else {
                expect($response->getStatusCode(), "{$role}:{$resource}:{$page}")->not->toBe(200)
                    ->and($response->getStatusCode(), "{$role}:{$resource}:{$page}")->not->toBe(500);
            }
        }
    }

    foreach ([SitePagesPage::class, ...siteContentEditorPages()] as $page) {
        $response = $this->get($page::getUrl());
        $allowed = in_array($role, ['super_admin', 'admin', 'manager'], true);

        if ($allowed) {
            expect(
                $response->getStatusCode(),
                "{$role}:{$page}",
            )->toBe(200);
        } else {
            expect($response->getStatusCode(), "{$role}:{$page}")->not->toBe(200)
                ->and($response->getStatusCode(), "{$role}:{$page}")->not->toBe(500);
        }
    }

    $importResponse = $this->get(CatalogImportPage::getUrl());
    $mayImport = in_array($role, ['super_admin', 'admin'], true);

    if ($mayImport) {
        $importResponse->assertOk();
    } else {
        expect($importResponse->getStatusCode())->not->toBe(200)
            ->and($importResponse->getStatusCode())->not->toBe(500);
    }

    $settingsResponse = $this->get(ShopSettingsPage::getUrl());
    $mayViewSettings = in_array($role, ['super_admin', 'admin', 'manager'], true);

    if ($mayViewSettings) {
        $settingsResponse->assertOk();
    } else {
        expect($settingsResponse->getStatusCode())->not->toBe(200)
            ->and($settingsResponse->getStatusCode())->not->toBe(500);
    }
})->with([
    'super admin' => ['super_admin'],
    'admin' => ['admin'],
    'manager' => ['manager'],
    'customer' => ['customer'],
    'inactive super admin' => ['inactive'],
    'blocked super admin' => ['blocked'],
]);
