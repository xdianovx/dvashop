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
    case ManageCatalogImports = 'manage_catalog_imports';
}
