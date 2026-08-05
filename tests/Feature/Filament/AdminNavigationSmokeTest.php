<?php

use App\Filament\Pages\CatalogImportPage;
use App\Filament\Pages\ShopSettingsPage;
use App\Filament\Resources\DeliveryMethodSettings\DeliveryMethodSettingResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\PartTypes\PartTypeResource;
use App\Filament\Resources\PaymentMethodSettings\PaymentMethodSettingResource;
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
use App\Models\DeliveryMethodSetting;
use App\Models\Order;
use App\Models\PartType;
use App\Models\PaymentMethodSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\SiteNavigationItem;
use App\Models\User;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('filament discovery inventory is complete and fails on an unclassified resource or page', function (): void {
    $panel = Filament::getPanel('admin');
    $resources = $panel->getResources();
    $pages = $panel->getPages();
    $widgets = $panel->getWidgets();

    sort($resources);
    sort($pages);
    sort($widgets);

    $expectedResources = [
        DeliveryMethodSettingResource::class,
        OrderResource::class,
        PartTypeResource::class,
        PaymentMethodSettingResource::class,
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
    $expectedPages = [CatalogImportPage::class, Dashboard::class, ShopSettingsPage::class];
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

test('actual navigation contains only role permitted resource and page urls', function (string $role): void {
    $user = match ($role) {
        'super_admin' => User::factory()->superAdmin()->create(),
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'inactive' => User::factory()->superAdmin()->inactive()->create(),
        'blocked' => User::factory()->superAdmin()->blocked()->create(),
        default => User::factory()->create(),
    };
    $allResources = array_values(Filament::getPanel('admin')->getResources());
    $allowedResources = match ($role) {
        'super_admin' => $allResources,
        'admin', 'manager' => array_values(array_filter(
            $allResources,
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

    $response->assertSee(ShopSettingsPage::getUrl(), false);
})->with([
    'super admin' => ['super_admin'],
    'admin' => ['admin'],
    'manager' => ['manager'],
    'customer' => ['customer'],
    'inactive super admin' => ['inactive'],
    'blocked super admin' => ['blocked'],
]);

test('every discovered resource page route follows the explicit role matrix', function (string $role): void {
    $actor = match ($role) {
        'super_admin' => User::factory()->superAdmin()->create(),
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'inactive' => User::factory()->superAdmin()->inactive()->create(),
        'blocked' => User::factory()->superAdmin()->blocked()->create(),
        default => User::factory()->create(),
    };
    $records = [
        DeliveryMethodSettingResource::class => DeliveryMethodSetting::factory()->create(),
        OrderResource::class => Order::factory()->create(),
        PartTypeResource::class => PartType::factory()->create(),
        PaymentMethodSettingResource::class => PaymentMethodSetting::factory()->create(),
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
            $parameters = in_array($page, ['edit', 'view'], true)
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
