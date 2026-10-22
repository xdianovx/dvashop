<?php

namespace App\Console\Commands;

use App\Services\Media\VehicleImageReview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReviewVehicleImages extends Command
{
    protected $signature = 'vehicle-images:review {--redownload-all : Back up all bindings/files and plan fresh automatic downloads; requires --xlsx} {--verify : Use the configured visual verifier} {--xlsx= : Optional exact vehicle/photo workbook} {--after-id=0} {--limit=0} {--output= : Private local disk relative JSON path}';

    protected $description = 'Read-only image review and signed repair scope; never runs a FULL or changes images.';

    public function handle(VehicleImageReview $review): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Команда разрешена только в local/testing.');

            return self::FAILURE;
        }
        $path = $this->option('output') ?: 'vehicle-image-reviews/plans/'.Str::uuid().'.json';
        if (str_contains($path, '..') || str_starts_with($path, '/') || str_contains($path, ':') || str_contains($path, '\\')) {
            $this->error('Укажите относительный путь private local disk.');

            return self::FAILURE;
        }
        if (Storage::disk('local')->exists($path)) {
            $this->error('Подписанный план уже существует. Выберите новый --output.');

            return self::FAILURE;
        }
        $plan = $review->plan((bool) $this->option('verify'), $this->option('xlsx'), max(0, (int) $this->option('after-id')), max(0, (int) $this->option('limit')), (bool) $this->option('redownload-all'));
        if (! Storage::disk('local')->put($path, json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
            throw new \RuntimeException('Не удалось сохранить план и привязки изображений.');
        }
        $this->line(json_encode(['plan' => Storage::disk('local')->path($path), 'counts' => $plan['counts'],
            'states' => array_count_values(array_column(array_column($plan['entries'], 'assessment'), 'state')),
            'unresolved_source_rows' => $plan['unresolved_source_rows'], 'backup' => $plan['backup'] ? [
                'directory' => Storage::disk('local')->path($plan['backup']['directory']), 'files' => count($plan['backup']['files']),
            ] : null,
            'preserved' => array_count_values(array_filter(array_column($plan['entries'], 'preservation'))),
            'confirmation' => $plan['seal'], 'applied' => false], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
