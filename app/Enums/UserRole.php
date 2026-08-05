<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Manager = 'manager';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Суперадминистратор',
            self::Admin => 'Администратор',
            self::Manager => 'Менеджер',
            self::Customer => 'Покупатель',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $role): array => [$role->value => $role->label()])
            ->all();
    }

    public function canAccessAdminPanel(): bool
    {
        return $this->allows(AdminPermission::AccessPanel);
    }

    public function allows(AdminPermission $permission): bool
    {
        $permissions = match ($this) {
            self::SuperAdmin => [
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
                AdminPermission::ViewHomepageContent,
                AdminPermission::ManageHomepageContent,
                AdminPermission::ViewStaticContent,
                AdminPermission::ManageStaticContent,
            ],
            self::Admin => [
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
                AdminPermission::ViewHomepageContent,
                AdminPermission::ManageHomepageContent,
                AdminPermission::ViewStaticContent,
                AdminPermission::ManageStaticContent,
            ],
            self::Manager => [
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
                AdminPermission::ViewHomepageContent,
                AdminPermission::ViewStaticContent,
            ],
            self::Customer => [],
        };

        return in_array($permission, $permissions, true);
    }
}
