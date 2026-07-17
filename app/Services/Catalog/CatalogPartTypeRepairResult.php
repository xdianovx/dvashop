<?php

namespace App\Services\Catalog;

final readonly class CatalogPartTypeRepairResult
{
    /**
     * @param array<string, int> $counters
     * @param array<int, CatalogPartTypeRepairIssue> $warnings
     */
    public function __construct(
        public array $counters,
        public array $warnings = [],
    ) {}

    public function counter(string $name): int
    {
        return $this->counters[$name] ?? 0;
    }

    /** @return array<string, int> */
    public static function emptyCounters(): array
    {
        return [
            'legacy_store_categories_moved' => 0,
            'legacy_store_categories_merged' => 0,
            'part_types_created' => 0,
            'part_types_restored' => 0,
            'part_types_existing' => 0,
            'imported_products_updated' => 0,
            'manual_products_updated' => 0,
            'products_already_correct' => 0,
            'technical_categories_deactivated' => 0,
            'fallback_used' => 0,
        ];
    }
}
