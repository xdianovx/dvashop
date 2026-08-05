<?php

use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Models\User;
use Tests\TestCase;

uses(TestCase::class);

test('admin permission matrix is explicit for every role and permission', function (): void {
    expect(array_column(AdminPermission::cases(), 'value'))->toBe([
        'access_panel',
        'manage_users',
        'view_catalog',
        'create_catalog',
        'update_catalog',
        'delete_catalog',
        'restore_catalog',
        'view_products',
        'create_products',
        'update_products',
        'delete_products',
        'restore_products',
        'generate_product_variants',
        'reset_product_gallery',
        'view_orders',
        'update_orders',
        'manage_catalog_imports',
        'view_store_settings',
        'update_store_settings',
        'view_site_navigation',
        'manage_site_navigation',
        'view_delivery_methods',
        'manage_delivery_methods',
        'view_payment_methods',
        'manage_payment_methods',
    ]);

    $matrix = [
        UserRole::SuperAdmin->value => [
            AdminPermission::AccessPanel,
            AdminPermission::ManageUsers,
            AdminPermission::ViewCatalog,
            AdminPermission::CreateCatalog,
            AdminPermission::UpdateCatalog,
            AdminPermission::DeleteCatalog,
            AdminPermission::RestoreCatalog,
            AdminPermission::ViewProducts,
            AdminPermission::CreateProducts,
            AdminPermission::UpdateProducts,
            AdminPermission::DeleteProducts,
            AdminPermission::RestoreProducts,
            AdminPermission::GenerateProductVariants,
            AdminPermission::ResetProductGallery,
            AdminPermission::ViewOrders,
            AdminPermission::UpdateOrders,
            AdminPermission::ManageCatalogImports,
            AdminPermission::ViewStoreSettings,
            AdminPermission::UpdateStoreSettings,
            AdminPermission::ViewSiteNavigation,
            AdminPermission::ManageSiteNavigation,
            AdminPermission::ViewDeliveryMethods,
            AdminPermission::ManageDeliveryMethods,
            AdminPermission::ViewPaymentMethods,
            AdminPermission::ManagePaymentMethods,
        ],
        UserRole::Admin->value => [
            AdminPermission::AccessPanel,
            AdminPermission::ViewCatalog,
            AdminPermission::CreateCatalog,
            AdminPermission::UpdateCatalog,
            AdminPermission::DeleteCatalog,
            AdminPermission::RestoreCatalog,
            AdminPermission::ViewProducts,
            AdminPermission::CreateProducts,
            AdminPermission::UpdateProducts,
            AdminPermission::DeleteProducts,
            AdminPermission::RestoreProducts,
            AdminPermission::GenerateProductVariants,
            AdminPermission::ViewOrders,
            AdminPermission::UpdateOrders,
            AdminPermission::ManageCatalogImports,
            AdminPermission::ViewStoreSettings,
            AdminPermission::UpdateStoreSettings,
            AdminPermission::ViewSiteNavigation,
            AdminPermission::ManageSiteNavigation,
            AdminPermission::ViewDeliveryMethods,
            AdminPermission::ManageDeliveryMethods,
            AdminPermission::ViewPaymentMethods,
            AdminPermission::ManagePaymentMethods,
        ],
        UserRole::Manager->value => [
            AdminPermission::AccessPanel,
            AdminPermission::ViewCatalog,
            AdminPermission::ViewProducts,
            AdminPermission::CreateProducts,
            AdminPermission::UpdateProducts,
            AdminPermission::ViewOrders,
            AdminPermission::UpdateOrders,
            AdminPermission::ViewStoreSettings,
            AdminPermission::ViewSiteNavigation,
            AdminPermission::ViewDeliveryMethods,
            AdminPermission::ViewPaymentMethods,
        ],
        UserRole::Customer->value => [],
    ];

    expect(array_keys($matrix))->toBe(array_map(
        fn (UserRole $role): string => $role->value,
        UserRole::cases(),
    ));

    foreach (UserRole::cases() as $role) {
        foreach (AdminPermission::cases() as $permission) {
            expect($role->allows($permission), "{$role->value}:{$permission->value}")
                ->toBe(in_array($permission, $matrix[$role->value], true));
        }
    }
});

test('inactive blocked and invalid role users have no admin permissions', function (): void {
    $makeUser = function (string $role, bool $isActive = true, ?string $blockedAt = null): User {
        $user = new User;
        $user->setRawAttributes([
            'role' => $role,
            'is_active' => $isActive,
            'blocked_at' => $blockedAt,
        ]);

        return $user;
    };

    $users = [
        'inactive super admin' => $makeUser(UserRole::SuperAdmin->value, false),
        'blocked super admin' => $makeUser(UserRole::SuperAdmin->value, true, '2026-08-03 12:00:00'),
        'invalid role' => $makeUser('invalid-role'),
    ];

    foreach ($users as $label => $user) {
        foreach (AdminPermission::cases() as $permission) {
            expect($user->canPerformAdminAction($permission), "{$label}:{$permission->value}")->toBeFalse();
        }

        expect($user->canAccessAdminPanel(), $label)->toBeFalse();
    }
});
