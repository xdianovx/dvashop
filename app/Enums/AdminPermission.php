<?php

namespace App\Enums;

enum AdminPermission: string
{
    case AccessPanel = 'access_panel';
    case ManageUsers = 'manage_users';
    case ViewCatalog = 'view_catalog';
    case CreateCatalog = 'create_catalog';
    case UpdateCatalog = 'update_catalog';
    case DeleteCatalog = 'delete_catalog';
    case RestoreCatalog = 'restore_catalog';
    case ViewProducts = 'view_products';
    case CreateProducts = 'create_products';
    case UpdateProducts = 'update_products';
    case DeleteProducts = 'delete_products';
    case RestoreProducts = 'restore_products';
    case GenerateProductVariants = 'generate_product_variants';
    case ResetProductGallery = 'reset_product_gallery';
    case ViewOrders = 'view_orders';
    case UpdateOrders = 'update_orders';
    case ViewInquiries = 'view_inquiries';
    case ManageCatalogImports = 'manage_catalog_imports';
    case ViewStoreSettings = 'view_store_settings';
    case UpdateStoreSettings = 'update_store_settings';
    case ViewSiteNavigation = 'view_site_navigation';
    case ManageSiteNavigation = 'manage_site_navigation';
    case ViewDeliveryMethods = 'view_delivery_methods';
    case ManageDeliveryMethods = 'manage_delivery_methods';
    case ViewPaymentMethods = 'view_payment_methods';
    case ManagePaymentMethods = 'manage_payment_methods';
    case ViewHomepageContent = 'view_homepage_content';
    case ManageHomepageContent = 'manage_homepage_content';
    case ViewStaticContent = 'view_static_content';
    case ManageStaticContent = 'manage_static_content';
}
