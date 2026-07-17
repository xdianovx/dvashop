<?php

namespace App\Services\Catalog;

final readonly class CatalogPartTypeRepairIssue
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $code,
        public string $message,
        public array $context = [],
    ) {}
}
