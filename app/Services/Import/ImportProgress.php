<?php

namespace App\Services\Import;

final readonly class ImportProgress
{
    public function __construct(
        public int $rowsPercent,
        public int $imagesPercent,
        public int $overallPercent,
        public string $rowsLabel,
        public string $imagesLabel,
        public string $overallLabel,
        public string $stageLabel,
        public string $statusLabel,
    ) {}
}
