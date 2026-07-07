<?php

namespace App\Filament\Pages;

use App\Enums\ImportRunStatus;
use App\Jobs\CatalogImportChunkJob;
use App\Jobs\CatalogImportStartJob;
use App\Models\ImportLog;
use App\Models\ImportRun;
use App\Services\ImportLogger;
use App\Services\ImportRunReportExporter;
use App\Services\ImportStatusService;
use BackedEnum;
use Filament\Actions\Action;
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
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogImportPage extends Page implements HasTable
{
    use InteractsWithTable;
    use WithFileUploads;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected string $view = 'filament.pages.catalog-import';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'imports/catalog';

    public mixed $file = null;

    public string $type = 'catalog';

    public int $chunkSize = 300;

    public bool $startAfterUpload = true;

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
        $user = auth()->user();

        return $user?->role?->canAccessAdminPanel() ?? false;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('import_help')
                ->label('Как работает импорт?')
                ->icon('heroicon-o-question-mark-circle')
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
                TextColumn::make('id')
                    ->label('ID')
                    ->formatStateUsing(fn (int $state): string => '#'.$state)
                    ->sortable(),
                TextColumn::make('original_name')
                    ->label('Файл')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('type')
                    ->label('Источник')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (ImportRunStatus|string|null $state): string => $state instanceof ImportRunStatus ? $state->color() : 'gray')
                    ->formatStateUsing(fn (ImportRunStatus|string|null $state): string => $state instanceof ImportRunStatus ? $state->label() : (string) $state)
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                                TextColumn::make('rows_progress')
                    ->label('Строки')
                    ->state(fn (ImportRun $record): string => $record->processed_rows.' / '.$record->total_rows.' · '.$record->rowsProgressPercent().'%'),
                TextColumn::make('products_stats')
                    ->label('Товары')
                    ->state(fn (ImportRun $record): string => '+'.$record->created_products.' / ~'.$record->updated_products.' / архив '.$record->archived_products),
                TextColumn::make('image_stats')
                    ->label('Изображения')
                    ->state(fn (ImportRun $record): string => $record->processed_images.' / '.$record->queued_images.' · ошибок '.$record->failed_images),
                TextColumn::make('log_stats')
                    ->label('Ошибки')
                    ->state(fn (ImportRun $record): string => $record->errors_count.' / предупреждений '.$record->warnings_count),
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
            ->recordActions([
                Action::make('details')
                    ->label('Подробности')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (ImportRun $record): string => 'Импорт #'.$record->getKey())
                    ->modalContent(fn (ImportRun $record) => view('filament.pages.catalog-import-details', [
                        'run' => $record->refresh(),
                        'logs' => $this->latestLogs($record, 30),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть'),
                Action::make('start')
                    ->label('Старт')
                    ->icon('heroicon-o-play')
                    ->visible(fn (ImportRun $record): bool => $record->status === ImportRunStatus::Ready)
                    ->action(fn (ImportRun $record): mixed => $this->start($record->getKey())),
                Action::make('pause')
                    ->label('Пауза')
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->visible(fn (ImportRun $record): bool => $record->status?->isRowsRunning())
                    ->action(fn (ImportRun $record): mixed => $this->pause($record->getKey())),
                Action::make('resume')
                    ->label('Продолжить')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (ImportRun $record): bool => $record->status === ImportRunStatus::Paused)
                    ->action(fn (ImportRun $record): mixed => $this->resume($record->getKey())),
                Action::make('cancel')
                    ->label('Отменить')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ImportRun $record): bool => ! $record->isTerminal())
                    ->action(fn (ImportRun $record): mixed => $this->cancel($record->getKey())),
                Action::make('download_original')
                    ->label('Файл')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn (ImportRun $record): BinaryFileResponse => $this->downloadOriginal($record->getKey())),
                Action::make('download_logs')
                    ->label('Логи CSV')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(fn (ImportRun $record): StreamedResponse => $this->downloadLogs($record->getKey())),
                Action::make('download_report')
                    ->label('Отчёт CSV')
                    ->icon('heroicon-o-document-text')
                    ->action(fn (ImportRun $record): StreamedResponse => $this->downloadReport($record->getKey())),
            ])
            ->paginated([10, 25, 50]);
    }

    public function submitImport(): void
    {
        $this->validate([
            'file' => ['required', 'file', 'mimes:csv,xlsx', 'max:51200'],
            'type' => ['required', 'string', 'max:64'],
            'chunkSize' => ['required', 'integer', 'in:100,300,500'],
            'startAfterUpload' => ['boolean'],
        ]);

        $statusService = app(ImportStatusService::class);

        $run = $statusService->createFromUpload($this->file, $this->type, $this->chunkSize);
        $this->reset('file');

        if ($this->startAfterUpload) {
            $this->start($run->getKey(), $statusService, false);
        }

        Notification::make()
            ->title('Файл загружен')
            ->body('Создан импорт #'.$run->getKey())
            ->success()
            ->send();
    }

    public function start(int $runId, ?ImportStatusService $statusService = null, bool $notify = true): mixed
    {
        $run = ImportRun::query()->findOrFail($runId);

        if ($run->status !== ImportRunStatus::Ready) {
            if ($notify) {
                Notification::make()->title('Импорт уже запущен или завершён')->warning()->send();
            }

            return null;
        }

        $run = ($statusService ?? app(ImportStatusService::class))->start($run);

        if ($run->status?->isRowsRunning()) {
            CatalogImportStartJob::dispatch($run->getKey())->onQueue('imports');
        }

        if ($notify) {
            Notification::make()->title('Импорт запущен')->success()->send();
        }

        return null;
    }

    public function pause(int $runId, ?ImportStatusService $statusService = null): mixed
    {
        ($statusService ?? app(ImportStatusService::class))->pause(ImportRun::query()->findOrFail($runId));

        Notification::make()->title('Импорт поставлен на паузу')->success()->send();

        return null;
    }

    public function resume(int $runId, ?ImportStatusService $statusService = null): mixed
    {
        $run = ($statusService ?? app(ImportStatusService::class))->resume(ImportRun::query()->findOrFail($runId));

        if ($run->status?->isRowsRunning()) {
            CatalogImportChunkJob::dispatch($run->getKey())->onQueue('imports');
        }

        Notification::make()->title('Импорт продолжен')->success()->send();

        return null;
    }

    public function cancel(int $runId, ?ImportStatusService $statusService = null): mixed
    {
        ($statusService ?? app(ImportStatusService::class))->cancel(ImportRun::query()->findOrFail($runId));

        Notification::make()->title('Импорт отменён')->warning()->send();

        return null;
    }

    public function downloadOriginal(int $runId): BinaryFileResponse
    {
        abort_unless(static::canAccess(), 403);

        $run = ImportRun::query()->findOrFail($runId);
        abort_unless(Storage::disk('local')->exists($run->stored_path), 404);

        return response()->download(
            Storage::disk('local')->path($run->stored_path),
            $run->original_name,
        );
    }

    public function downloadLogs(int $runId): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return app(ImportRunReportExporter::class)->logsCsv(ImportRun::query()->findOrFail($runId));
    }

    public function downloadReport(int $runId): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return app(ImportRunReportExporter::class)->summaryCsv(ImportRun::query()->findOrFail($runId));
    }

    public function refreshImportSnapshot(): void
    {
        // Livewire polling hook. Data is re-read through focused computed methods.
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
        return app(ImportLogger::class)->latest($run, $limit);
    }

    public function statusLabel(ImportRun $run): string
    {
        return $run->status instanceof ImportRunStatus ? $run->status->label() : (string) $run->status;
    }
}
