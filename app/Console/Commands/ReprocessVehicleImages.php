<?php

namespace App\Console\Commands;

use App\Services\Media\VehicleImageReview;
use Illuminate\Console\Command;

class ReprocessVehicleImages extends Command
{
    protected $signature = 'vehicle-images:reprocess {plan : Absolute path to the reviewed signed JSON} {--confirm= : Required exact review seal} {--limit=50 : At most 50 sequential operations per batch} {--all-batches : Continue sequential batches to the end; no parallel downloads}';

    protected $description = 'Apply only a separately approved signed image scope, without catalogue import.';

    public function handle(VehicleImageReview $review): int
    {
        if (! $this->option('confirm')) {
            $this->error('Изображения не изменены: требуется отдельное подтверждение плана --confirm.');

            return self::FAILURE;
        }
        $plan = json_decode(file_get_contents($this->argument('plan')), true, flags: JSON_THROW_ON_ERROR);
        $result = $review->apply($plan, $this->option('confirm'), max(1, (int) $this->option('limit')), (bool) $this->option('all-batches'),
            fn ($batch) => $this->line(json_encode(['batch_processed' => count($batch), 'outcomes' => array_count_values($batch)], JSON_THROW_ON_ERROR)));
        $this->line(json_encode(['processed_this_run' => count($result), 'totals' => $review->summary($plan)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
