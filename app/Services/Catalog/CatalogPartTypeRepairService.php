<?php

namespace App\Services\Catalog;

final readonly class CatalogPartTypeRepairService
{
    public function __construct(
        private CatalogPartTypeRepairInspector $inspector,
        private CatalogPartTypeRepairApplier $applier,
    ) {}

    public function inspect(): CatalogPartTypeRepairPlan
    {
        return $this->inspector->inspect();
    }

    public function apply(CatalogPartTypeRepairPlan $plan): CatalogPartTypeRepairResult
    {
        return $this->applier->apply($plan);
    }
}
