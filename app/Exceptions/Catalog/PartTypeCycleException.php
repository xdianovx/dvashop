<?php

namespace App\Exceptions\Catalog;

use DomainException;

class PartTypeCycleException extends DomainException
{
    public static function forPartType(string $title): self
    {
        return new self("Нельзя назначить родителя для типа детали «{$title}»: это создаст цикл в дереве.");
    }
}
