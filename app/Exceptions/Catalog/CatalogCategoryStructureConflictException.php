<?php

namespace App\Exceptions\Catalog;

use DomainException;

class CatalogCategoryStructureConflictException extends DomainException
{
    public static function forPath(string $expectedPath, string $actualPath): self
    {
        return new self(
            "Нельзя создать магазинную категорию «{$expectedPath}»: категория с тем же назначением уже находится по пути «{$actualPath}».",
        );
    }
}
