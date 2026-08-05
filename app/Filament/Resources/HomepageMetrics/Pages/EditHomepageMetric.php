<?php

namespace App\Filament\Resources\HomepageMetrics\Pages;

use App\Filament\Resources\HomepageMetrics\HomepageMetricResource;
use App\Models\HomepageMetric;
use App\Services\Homepage\HomepageContentAdminService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditHomepageMetric extends EditRecord
{
    protected static string $resource = HomepageMetricResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var HomepageMetric $record */
        try {
            return app(HomepageContentAdminService::class)->updateMetric(HomepageMetricResource::actor(), $record, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])
                ->all());
        }
    }
}
