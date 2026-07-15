<?php

namespace App\Exceptions\Catalog;

use RuntimeException;

class RequiredCatalogCategoryMissingException extends RuntimeException
{
    public static function forPath(string $fullSlug): self
    {
        return new self("Обязательная магазинная категория «{$fullSlug}» не найдена.");
    }
}
