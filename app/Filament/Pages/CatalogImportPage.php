<?php

namespace App\Filament\Pages;

use App\Enums\ImportRunStatus;
use App\Jobs\CatalogImportStartJob;
use App\Models\ImportRun;
use App\Services\ImportLogger;
use App\Services\ImportStatusService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\WithFileUploads;

class CatalogImportPage extends Page
{
    use WithFileUploads;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected string $view = 'filament.pages.catalog-import';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'imports/catalog';

    public mixed $file = null;

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

    public function upload(ImportStatusService $statusService): void
    {
        $this->validate([
            'file' => ['required', 'file', 'mimes:csv,xlsx,txt', 'max:51200'],
        ]);

        $run = $statusService->createFromUpload($this->file);
        $this->reset('file');

        Notification::make()
            ->title('Файл загружен')
            ->body('Создан импорт #'.$run->getKey())
            ->success()
            ->send();
    }

    public function start(int $runId, ImportStatusService $statusService): void
    {
        $run = ImportRun::query()->findOrFail($runId);

        if ($run->isTerminal()) {
            return;
        }

        $statusService->start($run);
        CatalogImportStartJob::dispatch($run->getKey())->onQueue('imports');

        Notification::make()->title('Импорт запущен')->success()->send();
    }

    public function pause(int $runId, ImportStatusService $statusService): void
    {
        $statusService->pause(ImportRun::query()->findOrFail($runId));

        Notification::make()->title('Импорт поставлен на паузу')->success()->send();
    }

    public function resume(int $runId, ImportStatusService $statusService): void
    {
        $run = $statusService->resume(ImportRun::query()->findOrFail($runId));
        CatalogImportStartJob::dispatch($run->getKey())->onQueue('imports');

        Notification::make()->title('Импорт продолжен')->success()->send();
    }

    public function cancel(int $runId, ImportStatusService $statusService): void
    {
        $statusService->cancel(ImportRun::query()->findOrFail($runId));

        Notification::make()->title('Импорт отменён')->warning()->send();
    }

    /** @return Collection<int, ImportRun> */
    public function runs(): Collection
    {
        return ImportRun::query()
            ->withCount('logs')
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /** @return Collection<int, \App\Models\ImportLog> */
    public function latestLogs(ImportRun $run): Collection
    {
        return app(ImportLogger::class)->latest($run, 8);
    }

    public function statusLabel(ImportRun $run): string
    {
        return $run->status instanceof ImportRunStatus ? $run->status->label() : (string) $run->status;
    }
}
