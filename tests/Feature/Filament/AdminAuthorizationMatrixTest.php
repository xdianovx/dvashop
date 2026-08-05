<?php

use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\DeliveryMethodSetting;
use App\Models\Order;
use App\Models\PartType;
use App\Models\PaymentMethodSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionTemplateItem;
use App\Models\ProductOptionValue;
use App\Models\ShopSetting;
use App\Models\SiteNavigationItem;
use App\Models\User;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Settings\ShopSettingsService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('store settings and site navigation permissions extend the matrix without changing earlier permissions', function (): void {
    $setting = app(ShopSettingsService::class)->current();
    $navigation = SiteNavigationItem::factory()->create();
    $actors = [
        'super_admin' => User::factory()->superAdmin()->create(),
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'customer' => User::factory()->create(),
        'inactive_super_admin' => User::factory()->superAdmin()->inactive()->create(),
        'blocked_super_admin' => User::factory()->superAdmin()->blocked()->create(),
    ];

    foreach ($actors as $label => $actor) {
        $mayView = in_array($label, ['super_admin', 'admin', 'manager'], true);
        $mayManage = in_array($label, ['super_admin', 'admin'], true);

        expect($actor->canPerformAdminAction(AdminPermission::ViewStoreSettings), "{$label}:permission:view-settings")->toBe($mayView)
            ->and($actor->canPerformAdminAction(AdminPermission::UpdateStoreSettings), "{$label}:permission:update-settings")->toBe($mayManage)
            ->and($actor->canPerformAdminAction(AdminPermission::ViewSiteNavigation), "{$label}:permission:view-navigation")->toBe($mayView)
            ->and($actor->canPerformAdminAction(AdminPermission::ManageSiteNavigation), "{$label}:permission:manage-navigation")->toBe($mayManage)
            ->and($actor->can('view', $setting), "{$label}:policy:view-settings")->toBe($mayView)
            ->and($actor->can('update', $setting), "{$label}:policy:update-settings")->toBe($mayManage)
            ->and($actor->can('view', $navigation), "{$label}:policy:view-navigation")->toBe($mayView)
            ->and($actor->can('update', $navigation), "{$label}:policy:update-navigation")->toBe($mayManage)
            ->and($actor->can('delete', $navigation), "{$label}:policy:delete-navigation")->toBe($mayManage)
            ->and($actor->can('reorder', SiteNavigationItem::class), "{$label}:policy:reorder-navigation")->toBe($mayManage)
            ->and($actor->can('forceDelete', $navigation), "{$label}:policy:force-delete-navigation")->toBeFalse()
            ->and($actor->can('replicate', $navigation), "{$label}:policy:replicate-navigation")->toBeFalse();
    }

    expect($setting)->toBeInstanceOf(ShopSetting::class);
});

test('checkout method settings permissions allow managers to view but only privileged roles to manage', function (): void {
    $deliveryMethod = DeliveryMethodSetting::factory()->create();
    $paymentMethod = PaymentMethodSetting::factory()->create();
    $actors = [
        'super_admin' => User::factory()->superAdmin()->create(),
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'customer' => User::factory()->create(),
        'inactive_super_admin' => User::factory()->superAdmin()->inactive()->create(),
        'blocked_super_admin' => User::factory()->superAdmin()->blocked()->create(),
    ];

    foreach ($actors as $label => $actor) {
        $mayView = in_array($label, ['super_admin', 'admin', 'manager'], true);
        $mayManage = in_array($label, ['super_admin', 'admin'], true);

        expect($actor->canPerformAdminAction(AdminPermission::ViewDeliveryMethods), "{$label}:permission:view-delivery-methods")->toBe($mayView)
            ->and($actor->canPerformAdminAction(AdminPermission::ManageDeliveryMethods), "{$label}:permission:manage-delivery-methods")->toBe($mayManage)
            ->and($actor->canPerformAdminAction(AdminPermission::ViewPaymentMethods), "{$label}:permission:view-payment-methods")->toBe($mayView)
            ->and($actor->canPerformAdminAction(AdminPermission::ManagePaymentMethods), "{$label}:permission:manage-payment-methods")->toBe($mayManage);

        foreach ([$deliveryMethod, $paymentMethod] as $method) {
            $model = $method::class;

            expect($actor->can('viewAny', $model), "{$label}:{$model}:viewAny")->toBe($mayView)
                ->and($actor->can('view', $method), "{$label}:{$model}:view")->toBe($mayView)
                ->and($actor->can('update', $method), "{$label}:{$model}:update")->toBe($mayManage)
                ->and($actor->can('reorder', $model), "{$label}:{$model}:reorder")->toBe($mayManage)
                ->and($actor->can('create', $model), "{$label}:{$model}:create")->toBeFalse()
                ->and($actor->can('delete', $method), "{$label}:{$model}:delete")->toBeFalse()
                ->and($actor->can('deleteAny', $model), "{$label}:{$model}:deleteAny")->toBeFalse()
                ->and($actor->can('restore', $method), "{$label}:{$model}:restore")->toBeFalse()
                ->and($actor->can('restoreAny', $model), "{$label}:{$model}:restoreAny")->toBeFalse()
                ->and($actor->can('forceDelete', $method), "{$label}:{$model}:forceDelete")->toBeFalse()
                ->and($actor->can('forceDeleteAny', $model), "{$label}:{$model}:forceDeleteAny")->toBeFalse()
                ->and($actor->can('replicate', $method), "{$label}:{$model}:replicate")->toBeFalse();
        }
    }
});

test('catalog policies enforce view only manager access and privileged mutations', function (): void {
    $records = [
        ProductCategory::factory()->create(),
        PartType::factory()->create(),
        VehicleMake::factory()->create(),
        VehicleModel::factory()->create(),
        VehicleGeneration::factory()->create(),
    ];
    $actors = [
        'super_admin' => User::factory()->superAdmin()->create(),
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'customer' => User::factory()->create(),
        'inactive_super_admin' => User::factory()->superAdmin()->inactive()->create(),
        'blocked_super_admin' => User::factory()->superAdmin()->blocked()->create(),
    ];

    foreach ($actors as $label => $actor) {
        $mayView = in_array($label, ['super_admin', 'admin', 'manager'], true);
        $mayMutate = in_array($label, ['super_admin', 'admin'], true);

        foreach ($records as $record) {
            $model = $record::class;
            expect($actor->can('viewAny', $model), "{$label}:{$model}:viewAny")->toBe($mayView)
                ->and($actor->can('view', $record), "{$label}:{$model}:view")->toBe($mayView)
                ->and($actor->can('create', $model), "{$label}:{$model}:create")->toBe($mayMutate)
                ->and($actor->can('update', $record), "{$label}:{$model}:update")->toBe($mayMutate)
                ->and($actor->can('delete', $record), "{$label}:{$model}:delete")->toBe($mayMutate)
                ->and($actor->can('deleteAny', $model), "{$label}:{$model}:deleteAny")->toBe($mayMutate)
                ->and($actor->can('restore', $record), "{$label}:{$model}:restore")->toBe($mayMutate)
                ->and($actor->can('restoreAny', $model), "{$label}:{$model}:restoreAny")->toBe($mayMutate)
                ->and($actor->can('forceDelete', $record), "{$label}:{$model}:forceDelete")->toBeFalse()
                ->and($actor->can('forceDeleteAny', $model), "{$label}:{$model}:forceDeleteAny")->toBeFalse()
                ->and($actor->can('replicate', $record), "{$label}:{$model}:replicate")->toBeFalse()
                ->and($actor->can('reorder', $model), "{$label}:{$model}:reorder")->toBeFalse();
        }
    }
});

test('product order and user policies match the complete role matrix', function (): void {
    $product = Product::factory()->create();
    $order = Order::factory()->create();
    $targetUser = User::factory()->create();
    $actors = [
        'super_admin' => User::factory()->superAdmin()->create(),
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'customer' => User::factory()->create(),
        'inactive_super_admin' => User::factory()->superAdmin()->inactive()->create(),
        'blocked_super_admin' => User::factory()->superAdmin()->blocked()->create(),
    ];

    foreach ($actors as $label => $actor) {
        $isAdministrative = in_array($label, ['super_admin', 'admin', 'manager'], true);
        $mayDeleteProduct = in_array($label, ['super_admin', 'admin'], true);
        $isSuperAdmin = $label === 'super_admin';
        $mayGenerateVariants = in_array($label, ['super_admin', 'admin'], true);
        $mayResetGallery = $label === 'super_admin';

        expect($actor->can('viewAny', Product::class), "{$label}:product:viewAny")->toBe($isAdministrative)
            ->and($actor->can('view', $product), "{$label}:product:view")->toBe($isAdministrative)
            ->and($actor->can('create', Product::class), "{$label}:product:create")->toBe($isAdministrative)
            ->and($actor->can('update', $product), "{$label}:product:update")->toBe($isAdministrative)
            ->and($actor->can('delete', $product), "{$label}:product:delete")->toBe($mayDeleteProduct)
            ->and($actor->can('deleteAny', Product::class), "{$label}:product:deleteAny")->toBe($mayDeleteProduct)
            ->and($actor->can('restore', $product), "{$label}:product:restore")->toBe($mayDeleteProduct)
            ->and($actor->can('restoreAny', Product::class), "{$label}:product:restoreAny")->toBe($mayDeleteProduct)
            ->and($actor->can('forceDelete', $product), "{$label}:product:forceDelete")->toBeFalse()
            ->and($actor->can('forceDeleteAny', Product::class), "{$label}:product:forceDeleteAny")->toBeFalse()
            ->and($actor->can('generateVariants', $product), "{$label}:product:generateVariants")->toBe($mayGenerateVariants)
            ->and($actor->can('resetGallery', $product), "{$label}:product:resetGallery")->toBe($mayResetGallery)
            ->and($actor->can('replicate', $product), "{$label}:product:replicate")->toBeFalse()
            ->and($actor->can('reorder', Product::class), "{$label}:product:reorder")->toBeFalse()
            ->and($actor->can('viewAny', Order::class), "{$label}:order:viewAny")->toBe($isAdministrative)
            ->and($actor->can('view', $order), "{$label}:order:view")->toBe($isAdministrative)
            ->and($actor->can('update', $order), "{$label}:order:update")->toBe($isAdministrative)
            ->and($actor->can('create', Order::class), "{$label}:order:create")->toBeFalse()
            ->and($actor->can('delete', $order), "{$label}:order:delete")->toBeFalse()
            ->and($actor->can('deleteAny', Order::class), "{$label}:order:deleteAny")->toBeFalse()
            ->and($actor->can('restore', $order), "{$label}:order:restore")->toBeFalse()
            ->and($actor->can('restoreAny', Order::class), "{$label}:order:restoreAny")->toBeFalse()
            ->and($actor->can('forceDelete', $order), "{$label}:order:forceDelete")->toBeFalse()
            ->and($actor->can('forceDeleteAny', Order::class), "{$label}:order:forceDeleteAny")->toBeFalse()
            ->and($actor->can('replicate', $order), "{$label}:order:replicate")->toBeFalse()
            ->and($actor->can('reorder', Order::class), "{$label}:order:reorder")->toBeFalse()
            ->and($actor->can('viewAny', User::class), "{$label}:user:viewAny")->toBe($isSuperAdmin)
            ->and($actor->can('view', $targetUser), "{$label}:user:view")->toBe($isSuperAdmin)
            ->and($actor->can('create', User::class), "{$label}:user:create")->toBe($isSuperAdmin)
            ->and($actor->can('update', $targetUser), "{$label}:user:update")->toBe($isSuperAdmin)
            ->and($actor->can('delete', $targetUser), "{$label}:user:delete")->toBeFalse()
            ->and($actor->can('deleteAny', User::class), "{$label}:user:deleteAny")->toBeFalse()
            ->and($actor->can('restore', $targetUser), "{$label}:user:restore")->toBeFalse()
            ->and($actor->can('restoreAny', User::class), "{$label}:user:restoreAny")->toBeFalse()
            ->and($actor->can('forceDelete', $targetUser), "{$label}:user:forceDelete")->toBeFalse()
            ->and($actor->can('forceDeleteAny', User::class), "{$label}:user:forceDeleteAny")->toBeFalse()
            ->and($actor->can('replicate', $targetUser), "{$label}:user:replicate")->toBeFalse()
            ->and($actor->can('reorder', User::class), "{$label}:user:reorder")->toBeFalse();
    }
});

test('option catalog policies allow privileged management but never destructive actions', function (): void {
    $models = [
        ProductOptionGroup::factory()->create(),
        ProductOptionValue::factory()->create(),
        ProductOptionTemplate::factory()->create(),
        ProductOptionTemplateItem::factory()->create(),
    ];
    $actors = [
        'super_admin' => User::factory()->superAdmin()->create(),
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'customer' => User::factory()->create(),
        'inactive' => User::factory()->superAdmin()->inactive()->create(),
        'blocked' => User::factory()->superAdmin()->blocked()->create(),
    ];

    foreach ($actors as $label => $actor) {
        $mayView = in_array($label, ['super_admin', 'admin', 'manager'], true);
        $mayMutate = in_array($label, ['super_admin', 'admin'], true);

        foreach ($models as $model) {
            $modelClass = $model::class;

            expect($actor->can('viewAny', $modelClass), "{$label}:{$modelClass}:viewAny")->toBe($mayView)
                ->and($actor->can('view', $model), "{$label}:{$modelClass}:view")->toBe($mayView)
                ->and($actor->can('create', $modelClass), "{$label}:{$modelClass}:create")->toBe($mayMutate)
                ->and($actor->can('update', $model), "{$label}:{$modelClass}:update")->toBe($mayMutate)
                ->and($actor->can('reorder', $modelClass), "{$label}:{$modelClass}:reorder")->toBe($mayMutate)
                ->and($actor->can('delete', $model))->toBeFalse()
                ->and($actor->can('deleteAny', $modelClass))->toBeFalse()
                ->and($actor->can('restore', $model))->toBeFalse()
                ->and($actor->can('restoreAny', $modelClass))->toBeFalse()
                ->and($actor->can('forceDelete', $model))->toBeFalse()
                ->and($actor->can('forceDeleteAny', $modelClass))->toBeFalse()
                ->and($actor->can('replicate', $model))->toBeFalse();
        }
    }
});

test('manager product actions hide destructive and structural operations', function (): void {
    $manager = User::factory()->manager()->create();
    $product = Product::factory()->create();
    $this->actingAs($manager);

    Livewire::test(ListProducts::class)
        ->assertTableActionVisible('edit', $product)
        ->assertTableActionVisible('table_make_default_main', $product)
        ->assertTableActionHidden('table_reset_gallery_to_default', $product)
        ->assertTableActionHidden('delete', $product)
        ->assertTableActionHidden('forceDelete', $product)
        ->assertTableBulkActionHidden('delete')
        ->assertTableBulkActionHidden('restore')
        ->assertTableBulkActionHidden('forceDelete');

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertActionHidden('generate_variants_from_template')
        ->assertActionHidden('delete')
        ->assertActionHidden('restore')
        ->assertActionHidden('forceDelete');

});

test('admin may generate variants but cannot reset gallery or force delete', function (): void {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();
    $this->actingAs($admin);

    Livewire::test(ListProducts::class)
        ->assertTableActionVisible('edit', $product)
        ->assertTableActionVisible('table_make_default_main', $product)
        ->assertTableActionHidden('table_reset_gallery_to_default', $product)
        ->assertTableActionVisible('delete', $product)
        ->assertTableActionHidden('forceDelete', $product)
        ->assertTableBulkActionVisible('delete')
        ->assertTableBulkActionHidden('forceDelete');

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertActionVisible('generate_variants_from_template')
        ->assertActionVisible('delete')
        ->assertActionHidden('forceDelete');
});

test('role database values remain unchanged by the authorization matrix', function (): void {
    expect(array_column(UserRole::cases(), 'value'))->toBe([
        'super_admin',
        'admin',
        'manager',
        'customer',
    ]);
});

test('an invalid persisted role is denied by panel resource and policy checks', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    DB::table('users')->whereKey($user->getKey())->update(['role' => 'invalid-role']);
    $user->refresh();

    expect($user->canAccessAdminPanel())->toBeFalse()
        ->and($user->can('viewAny', Product::class))->toBeFalse()
        ->and($user->can('view', $product))->toBeFalse()
        ->and($user->can('create', Product::class))->toBeFalse()
        ->and($user->can('update', $product))->toBeFalse()
        ->and($user->can('generateVariants', $product))->toBeFalse()
        ->and($user->can('resetGallery', $product))->toBeFalse()
        ->and($user->can('forceDelete', $product))->toBeFalse();

    $response = $this->actingAs($user)->get('/admin');
    expect($response->getStatusCode())->not->toBe(200)
        ->and($response->getStatusCode())->not->toBe(500);
});
