<?php

namespace App\Filament\Pages;

use App\Enums\AdminPermission;
use App\Enums\ImportRunStatus;
use App\Jobs\CatalogImportChunkJob;
use App\Jobs\CatalogImportStartJob;
use App\Models\ImportLog;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\Catalog\CatalogConfigurationInstallation;
use App\Services\Import\ImportProgress;
use App\Services\Import\ImportProgressService;
use App\Services\ImportLogger;
use App\Services\ImportRunReportExporter;
use App\Services\ImportStatusService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToRetrieveMetadata;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogImportPage extends Page implements HasTable
{
    use InteractsWithTable;
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected string $view = 'filament.pages.catalog-import';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'imports/catalog';

    public mixed $file = null;

    public mixed $configurationFile = null;

    public string $importMode = 'full';

    public string $type = 'catalog';

    public int $chunkSize = 10;

    public bool $startAfterUpload = true;

    public bool $uploadInProgress = false;

    #[Locked]
    public ?array $configurationReview = null;


    public static function getNavigationGroup(): ?string
    {
        return 'Импорт';
    }

    public static function getNavigationLabel(): string
    {
        return 'Импорт каталога';
    }

    public function getTitle(): string
    {
        return 'Импорт каталога';
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && $user->canPerformAdminAction(AdminPermission::ManageCatalogImports);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('import_help')
                ->label('Как работает импорт?')
                ->icon('heroicon-o-question-mark-circle')
                ->authorize(fn (): bool => static::canAccess())
                ->modalHeading('Как работает импорт каталога')
                ->modalContent(fn () => view('filament.pages.catalog-import-help'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрыть'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ImportRun::query()->latest('id'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('original_name')
                    ->label('Импорт')
                    ->description(fn (ImportRun $record): string => '#'.$record->getKey().' · '.($record->created_at?->format('d.m.Y H:i') ?? 'дата не указана').' · '.$record->type)
                    ->weight('semibold')
                    ->size('base')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (ImportRunStatus|string|null $state): string => $state instanceof ImportRunStatus ? $state->color() : 'gray')
                    ->formatStateUsing(fn (ImportRunStatus|string|null $state, ImportRun $record): string => $this->statusLabel($record))
                    ->sortable(),
                TextColumn::make('progress_summary')
                    ->label('Прогресс')
                    ->state(fn (ImportRun $record): array => [
                        'Строки: '.$record->processed_rows.' / '.$record->total_rows.' · '.$record->rowsProgressPercent().'%',
                        'Изображения: '.$record->processed_images.' / '.$record->queued_images,
                    ])
                    ->listWithLineBreaks()
                    ->wrap(),
                TextColumn::make('result_summary')
                    ->label('Результат')
                    ->state(fn (ImportRun $record): array => [
                        'Создано: '.$record->created_products,
                        'Обновлено: '.$record->updated_products,
                        'Архивировано: '.$record->archived_products,
                    ])
                    ->listWithLineBreaks()
                    ->wrap(),
                TextColumn::make('problems_summary')
                    ->label('Проблемы')
                    ->state(fn (ImportRun $record): array => [
                        'Предупреждения: '.$record->warnings_count,
                        'Ошибки: '.$record->errors_count,
                    ])
                    ->color(fn (ImportRun $record): string => match (true) {
                        $record->errors_count > 0 => 'danger',
                        $record->warnings_count > 0 => 'warning',
                        default => 'gray',
                    })
                    ->listWithLineBreaks()
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(ImportRunStatus::options()),
                SelectFilter::make('type')
                    ->label('Источник')
                    ->options(fn (): array => ImportRun::query()
                        ->select('type')
                        ->distinct()
                        ->orderBy('type')
                        ->pluck('type', 'type')
                        ->all()),
                Filter::make('created_at')
                    ->label('Дата')
                    ->schema([
                        DatePicker::make('from')->label('С'),
                        DatePicker::make('until')->label('По'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActionsColumnLabel('Действия')
            ->recordActions([
                Action::make('details')
                    ->label('Подробности')
                    ->icon('heroicon-o-eye')
                    ->authorize(fn (): bool => static::canAccess())
                    ->button()
                    ->modalHeading(fn (ImportRun $record): string => 'Импорт #'.$record->getKey())
                    ->modalContent(fn (ImportRun $record) => view('filament.pages.catalog-import-details', [
                        'run' => $record->refresh(),
                        'problems' => $this->latestProblems($record, 3),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть'),
                ActionGroup::make([
                    Action::make('logs')
                        ->label('Логи')
                        ->icon('heroicon-o-list-bullet')
                        ->authorize(fn (): bool => static::canAccess())
                        ->slideOver()
                        ->modalWidth('5xl')
                        ->modalHeading(fn (ImportRun $record): string => 'Логи импорта #'.$record->getKey())
                        ->modalContent(fn (ImportRun $record) => view('filament.pages.catalog-import-logs', [
                            'run' => $record,
                            'logs' => $this->latestLogs($record, 200),
                        ]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Закрыть'),
                    Action::make('start')
                        ->label(fn (ImportRun $record): string => in_array($record->import_mode, ['full', 'csv'], true) ? 'Проверить CSV и запустить' : 'Старт')
                        ->icon('heroicon-o-play')
                        ->authorize(fn (): bool => static::canAccess())
                        ->visible(fn (ImportRun $record): bool => $record->status === ImportRunStatus::Ready)
                        ->action(fn (ImportRun $record): mixed => $this->start($record->getKey())),
                    Action::make('pause')
                        ->label('Пауза')
                        ->icon('heroicon-o-pause')
                        ->color('warning')
                        ->authorize(fn (): bool => static::canAccess())
                        ->visible(fn (ImportRun $record): bool => $record->status?->isActive() && $record->status !== ImportRunStatus::Paused)
                        ->action(fn (ImportRun $record): mixed => $this->pause($record->getKey())),
                    Action::make('resume')
                        ->label('Продолжить')
                        ->icon('heroicon-o-arrow-path')
                        ->authorize(fn (): bool => static::canAccess())
                        ->visible(fn (ImportRun $record): bool => $record->status === ImportRunStatus::Paused)
                        ->action(fn (ImportRun $record): mixed => $this->resume($record->getKey())),
                    Action::make('cancel')
                        ->label('Отменить')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->authorize(fn (): bool => static::canAccess())
                        ->requiresConfirmation()
                        ->modalDescription('Уже внесённые изменения сохранятся.')
                        ->visible(fn (ImportRun $record): bool => ! $record->isTerminal())
                        ->action(fn (ImportRun $record): mixed => $this->cancel($record->getKey())),
                    Action::make('download_original')
                        ->label('Скачать исходный файл')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->authorize(fn (): bool => static::canAccess())
                        ->action(fn (ImportRun $record): BinaryFileResponse => $this->downloadOriginal($record->getKey())),
                    Action::make('download_logs')
                        ->label('Скачать логи CSV')
                        ->icon('heroicon-o-document-arrow-down')
                        ->authorize(fn (): bool => static::canAccess())
                        ->action(fn (ImportRun $record): StreamedResponse => $this->downloadLogs($record->getKey())),
                    Action::make('download_report')
                        ->label('Скачать отчёт CSV')
                        ->icon('heroicon-o-document-text')
                        ->authorize(fn (): bool => static::canAccess())
                        ->action(fn (ImportRun $record): StreamedResponse => $this->downloadReport($record->getKey())),
                ])
                    ->label('Ещё')
                    ->icon('heroicon-m-ellipsis-horizontal')
                    ->color('gray')
                    ->button(),
            ])
            ->emptyStateHeading('Импортов пока нет')
            ->emptyStateDescription('Загрузите XLSX с одним рабочим листом через форму выше.')
            ->emptyStateIcon('heroicon-o-document-arrow-up')
            ->paginated([10, 25, 50]);
    }

    public function removeSelectedFile(): void
    {
        $this->reset('file');
        $this->resetValidation('file');
    }

    public function updatedImportMode(): void
    {
        abort_unless(in_array($this->importMode, ['excel', 'full', 'csv', 'images', 'safe_partial'], true), 422);
        $this->reset('file', 'configurationFile');
        $this->resetValidation();
    }

    public function submitImport(): void
    {
        $this->authorizeImportAction();

        if ($this->discardMissingTemporaryUploads()) {
            return;
        }

        if ($this->uploadInProgress) {
            return;
        }

        $this->uploadInProgress = true;

        try {
            $this->validate(
                [
                    'importMode' => ['required', 'in:excel,full,csv,images,safe_partial'],
                    'file' => ['required_unless:importMode,csv', 'nullable', 'file', 'mimes:xlsx', 'max:51200'],
                    'configurationFile' => ['required_if:importMode,full,csv,safe_partial', 'nullable', 'file', 'mimes:csv,txt', 'max:10240'],
                    'type' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'],
                    'chunkSize' => ['required', 'integer', 'in:10,20,30'],
                    'startAfterUpload' => ['boolean'],
                ],
                [
                    'file.required' => 'Выберите файл импорта.',
                    'file.required_unless' => 'Выберите файл XLSX для выбранного режима.',
                    'file.file' => 'Выберите файл импорта.',
                    'file.mimes' => 'Нужен файл XLSX с одним рабочим листом.',
                    'file.max' => 'Файл импорта слишком большой.',
                    'configurationFile.required_if' => 'Выберите CSV с конфигурациями и ценами для выбранного режима.',
                    'configurationFile.file' => 'Выберите файл CSV с конфигурациями и ценами.',
                    'configurationFile.mimes' => 'Нужен файл CSV с конфигурациями и ценами.',
                    'configurationFile.max' => 'Файл CSV с конфигурациями и ценами слишком большой.',
                    'type.required' => 'Укажите корректный источник импорта.',
                    'type.regex' => 'Укажите корректный источник импорта.',
                    'type.max' => 'Укажите корректный источник импорта.',
                    'chunkSize.required' => 'Укажите корректный размер чанка.',
                    'chunkSize.integer' => 'Укажите корректный размер чанка.',
                    'chunkSize.in' => 'Укажите корректный размер чанка.',
                ],
                [
                    'file' => 'файл импорта',
                    'configurationFile' => 'файл конфигураций CSV',
                    'type' => 'источник импорта',
                    'chunkSize' => 'размер чанка',
                    'startAfterUpload' => 'запуск после загрузки',
                ],
            );

            $statusService = app(ImportStatusService::class);
            // Check both source structures before registering a runnable import.
            $configPath = null;
            $configHash = null;
            if (in_array($this->importMode, ['full', 'csv', 'safe_partial'], true)) {
                if ($this->importMode === 'full') {
                    app(\App\Services\Import\CatalogSourceContract::class)->validate(
                        $this->file->getRealPath(), $this->configurationFile->getRealPath(),
                    );
                } else {
                    app(\App\Services\Catalog\CatalogConfigurationSource::class)->read($this->configurationFile->getRealPath());
                }
                if (in_array($this->importMode, ['full', 'safe_partial'], true)) {
                    $configPath = $this->configurationFile->storeAs(
                        'imports/catalog/configurations', \Illuminate\Support\Str::uuid()->toString().'.csv', 'local',
                    );
                    if (! is_string($configPath)) {
                        throw new \RuntimeException('Не удалось сохранить загруженный CSV.');
                    }
                    $configHash = hash_file('sha256', \Illuminate\Support\Facades\Storage::disk('local')->path($configPath));
                }
            }
            $primaryFile = $this->importMode === 'csv' ? $this->configurationFile : $this->file;
            $run = $statusService->createFromUpload($primaryFile, $this->type, $this->chunkSize);
            $run->forceFill(['import_mode' => $this->importMode])->save();
            if ($this->importMode === 'csv') {
                $configPath = $run->stored_path;
                $configHash = $run->file_hash;
            }
            if ($configPath !== null) {
                $run->forceFill(['configuration_path' => $configPath, 'configuration_hash' => $configHash])->save();
            }
            $this->reset('file', 'configurationFile');

            if (in_array($run->import_mode, ['full', 'csv', 'safe_partial'], true)) {
                $this->previewConfigurationImport($run->getKey());
            } elseif ($this->startAfterUpload) {
                $this->start($run->getKey(), $statusService, false);
            }

            Notification::make()
                ->title('Файл загружен')
                ->body('Создан импорт #'.$run->getKey())
                ->success()
                ->send();
        } finally {
            $this->uploadInProgress = false;
        }
    }

    public function previewConfigurationImport(int $runId): void
    {
        $this->authorizeImportAction();
        $this->configurationReview = null;
        $this->resetValidation('configurationReview');
        $run = ImportRun::findOrFail($runId);
        abort_unless($run->status === ImportRunStatus::Ready && $run->initialized_at === null, 422);
        $this->configurationReview = app(CatalogConfigurationInstallation::class)->previewImport($run);
    }

    public function confirmConfigurationImport(): void
    {
        $this->authorizeImportAction();
        abort_unless($this->configurationReview !== null, 422);
        $run = ImportRun::findOrFail($this->configurationReview['run_id']);
        app(CatalogConfigurationInstallation::class)->confirmImport(
            $run, Filament::auth()->user(), $this->configurationReview['confirmation'],
        );
        $this->start($run->id);
        $this->configurationReview = null;
    }

    public function start(int $runId, ?ImportStatusService $statusService = null, bool $notify = true): mixed
    {
        $this->authorizeImportAction();

        $run = ImportRun::query()->findOrFail($runId);

        if ($run->status !== ImportRunStatus::Ready) {
            if ($notify) {
                Notification::make()->title('Импорт уже запущен или завершён')->warning()->send();
            }

            return null;
        }

        if (in_array($run->import_mode, ['full', 'csv'], true)) {
            if ($run->configuration_confirmed_at === null) {
                $this->previewConfigurationImport($run->id);
                return null;
            }
            $installation = app(CatalogConfigurationInstallation::class);
            $installation->assertImportConfirmation($run, $installation->previewImport($run));
        }
        if ($run->import_mode === 'safe_partial' && $run->configuration_confirmed_at === null) {
            $this->previewConfigurationImport($run->id);
            return null;
        }

        $run = ($statusService ?? app(ImportStatusService::class))->start($run);

        if ($run->status?->isRowsRunning()) {
            match ($run->import_mode ?? 'excel') {
                'safe_partial' => \App\Jobs\CatalogSafePartialJob::dispatch($run->id)->onQueue('imports'),
                'csv' => \App\Jobs\CatalogImportCsvStartJob::dispatch($run->getKey())->onQueue('imports'),
                'images' => \App\Jobs\CatalogImportImagesJob::dispatch($run->getKey())->onQueue('imports'),
                default => CatalogImportStartJob::dispatch($run->getKey())->onQueue('imports'),
            };
        }

        if ($notify) {
            Notification::make()->title('Импорт запущен')->success()->send();
        }

        return null;
    }

    public function pause(int $runId, ?ImportStatusService $statusService = null): mixed
    {
        $this->authorizeImportAction();

        ($statusService ?? app(ImportStatusService::class))->pause(ImportRun::query()->findOrFail($runId));

        Notification::make()->title('Импорт поставлен на паузу')->success()->send();

        return null;
    }

    public function resume(int $runId, ?ImportStatusService $statusService = null): mixed
    {
        $this->authorizeImportAction();

        $existingRun = ImportRun::query()->findOrFail($runId);
        $wasPaused = $existingRun->status === ImportRunStatus::Paused;
        $run = ($statusService ?? app(ImportStatusService::class))->resume($existingRun);

        if ($wasPaused && $run->status?->isRowsRunning()) {
            match ($run->import_mode ?? 'excel') {
                'safe_partial' => \App\Jobs\CatalogSafePartialJob::dispatch($run->id, $run->prepared_rows['last_item_id'] ?? null)->onQueue('imports'),
                'csv' => ($run->initialized_at === null
                    ? \App\Jobs\CatalogImportCsvStartJob::dispatch($run->getKey())->onQueue('imports')
                    : \App\Jobs\CatalogImportCsvChunkJob::dispatch($run->getKey())->onQueue('imports')),
                'images' => \App\Jobs\CatalogImportImagesJob::dispatch($run->getKey())->onQueue('imports'),
                default => ($run->initialized_at === null
                    ? CatalogImportStartJob::dispatch($run->getKey())->onQueue('imports')
                    : CatalogImportChunkJob::dispatch($run->getKey())->onQueue('imports')),
            };
        }

        Notification::make()->title('Импорт продолжен')->success()->send();

        return null;
    }

    public function cancel(int $runId, ?ImportStatusService $statusService = null): mixed
    {
        $this->authorizeImportAction();

        ($statusService ?? app(ImportStatusService::class))->cancel(ImportRun::query()->findOrFail($runId));

        Notification::make()->title('Импорт отменён')->warning()->send();

        return null;
    }

    public function downloadOriginal(int $runId): BinaryFileResponse
    {
        $this->authorizeImportAction();

        $run = ImportRun::query()->findOrFail($runId);
        abort_unless(Storage::disk('local')->exists($run->stored_path), 404);

        return response()->download(
            Storage::disk('local')->path($run->stored_path),
            $run->original_name,
        );
    }

    public function downloadLogs(int $runId): StreamedResponse
    {
        $this->authorizeImportAction();

        return app(ImportRunReportExporter::class)->logsCsv(ImportRun::query()->findOrFail($runId));
    }

    public function downloadReport(int $runId): StreamedResponse
    {
        $this->authorizeImportAction();

        return app(ImportRunReportExporter::class)->summaryCsv(ImportRun::query()->findOrFail($runId));
    }

    public function refreshImportSnapshot(): void
    {
        // Polls may arrive with a stale Livewire upload snapshot after the file
        // has been moved or cleaned up. Never let those snapshots crash rendering.
        $this->discardMissingTemporaryUploads();
    }

    private function discardMissingTemporaryUploads(): bool
    {
        $missing = false;

        foreach (['file', 'configurationFile'] as $property) {
            $upload = $this->{$property};

            if (! $upload instanceof TemporaryUploadedFile) {
                continue;
            }

            try {
                $upload->getSize();
            } catch (UnableToRetrieveMetadata) {
                $this->reset($property);
                $this->addError($property, 'Временный файл больше недоступен. Выберите файл заново.');
                $missing = true;
            }
        }

        return $missing;
    }

    public function hasActiveImport(): bool
    {
        return $this->activeRun() !== null;
    }

    public function activeRun(): ?ImportRun
    {
        return ImportRun::query()
            ->whereIn('status', [
                ImportRunStatus::Ready->value,
                ImportRunStatus::Running->value,
                ImportRunStatus::RunningRows->value,
                ImportRunStatus::ProcessingImages->value,
                ImportRunStatus::Paused->value,
            ])
            ->latest('id')
            ->first();
    }

    /** @return Collection<int, ImportLog> */
    public function latestLogs(ImportRun $run, int $limit = 10): Collection
    {
        return app(ImportLogger::class)->latest($run, min(200, max(1, $limit)));
    }

    /** @return Collection<int, ImportLog> */
    public function latestProblems(ImportRun $run, int $limit = 3): Collection
    {
        return app(ImportLogger::class)->latestProblems($run, min(3, max(1, $limit)));
    }

    public function progress(ImportRun $run): ImportProgress
    {
        return app(ImportProgressService::class)->forRun($run);
    }

    public function statusLabel(ImportRun $run): string
    {
        return app(ImportProgressService::class)->statusLabel($run);
    }

    private function authorizeImportAction(): void
    {
        abort_unless(static::canAccess(), 403);
    }
}
