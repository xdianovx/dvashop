<?php

namespace App\Services\Catalog;

final readonly class CatalogPartTypeRepairPlan
{
    /**
     * @param array<int, array<string, mixed>> $legacyStoreCategories
     * @param array<int, array<string, mixed>> $technicalCategories
     * @param array<int, array<string, mixed>> $unknownChildren
     * @param array<int, array<string, mixed>> $suspects
     * @param array<string, int> $previewCounters
     * @param array<int, CatalogPartTypeRepairIssue> $warnings
     * @param array<int, CatalogPartTypeRepairIssue> $blockers
     */
    public function __construct(
        public array $legacyStoreCategories,
        public array $technicalCategories,
        public array $unknownChildren,
        public array $suspects,
        public array $previewCounters,
        public array $warnings,
        public array $blockers,
    ) {}

    public function hasBlockers(): bool
    {
        return $this->blockers !== [];
    }

    public function preview(string $name): int
    {
        return $this->previewCounters[$name] ?? 0;
    }
}
