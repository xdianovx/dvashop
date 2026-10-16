<?php

use App\Services\Catalog\CatalogActions;
use App\Services\Catalog\CatalogBulkPrices;
use App\Services\Catalog\CatalogConfigurations;
use App\Services\Catalog\CatalogConfigurationSource;
use App\Services\Feeds\YandexFeedInvalidator;
use App\Services\Import\ImportFileInspector;
use App\Services\Search\CatalogSearchPublication;
use App\Support\ProjectCleanTreeInspector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Process\Process;

Schedule::call(fn () => app(YandexFeedInvalidator::class)->dispatchIfNeeded())
    ->name('yandex-feed-refresh')->everyTenMinutes()->withoutOverlapping();

Schedule::call(fn () => app(CatalogSearchPublication::class)->dispatchPending())
    ->name('catalog-search-publications')->everyMinute()->withoutOverlapping();

Artisan::command('import:inspect-file {path : Путь к csv/xlsx файлу}', function (ImportFileInspector $inspector): int {
    $path = (string) $this->argument('path');

    if (! is_file($path)) {
        $storagePath = storage_path('app/'.ltrim($path, '/'));
        if (is_file($storagePath)) {
            $path = $storagePath;
        }
    }

    if (! is_file($path)) {
        $this->error('Файл не найден: '.$path);

        return 1;
    }

    $result = $inspector->inspect($path);

    $this->info('Диагностика файла импорта');
    $this->line('Файл: '.$result['file']);
    $this->line('Лист: '.$result['sheet']);
    $this->line('Data rows: '.$result['data_rows']);
    $this->line('Марки: '.$result['makes']);
    $this->line('Модели: '.$result['models']);
    $this->line('Поколения/кузова: '.$result['generations']);
    $this->line('Заполненные товарные ячейки: '.$result['filled_detail_cells']);
    $this->line('Уникальные товары ориентировочно: '.$result['unique_products']);
    $this->line('URL в колонке A: '.$result['vehicle_image_urls']);
    $this->line('Availability-значения в колонке A: '.$result['vehicle_image_availability_values']);
    $this->line('Нестандартные значения в колонке A: '.$result['vehicle_image_non_standard_values']);
    $this->line('URL в товарных ячейках: '.$result['product_image_urls']);

    $this->newLine();
    $this->info('Категории из заголовков:');
    foreach ($result['category_tree'] as $root => $children) {
        $this->line('- '.$root);
        foreach ($children as $child) {
            $this->line('  - '.$child);
        }
    }

    $this->info('Проверка структуры и коллизий заголовков пройдена.');

    return 0;
})->purpose('Inspect catalog import file without writing to database');

Artisan::command('project:check-clean-tree {--strict : Also check physical local files before manual clean packaging}', function (ProjectCleanTreeInspector $inspector): int {
    $root = base_path();
    $errors = [];

    $requiredFiles = [
        'bootstrap/cache/.gitignore',
        'storage/app/.gitignore',
        'storage/app/public/.gitignore',
        'storage/framework/.gitignore',
        'storage/framework/cache/.gitignore',
        'storage/framework/cache/data/.gitignore',
        'storage/framework/sessions/.gitignore',
        'storage/framework/testing/.gitignore',
        'storage/framework/views/.gitignore',
        'storage/logs/.gitignore',
    ];

    foreach ($requiredFiles as $relativePath) {
        if (! is_file($root.DIRECTORY_SEPARATOR.$relativePath)) {
            $errors[] = 'Отсутствует обязательный файл структуры: '.$relativePath;
        }
    }

    if (is_dir($root.DIRECTORY_SEPARATOR.'.git')) {
        $process = new Process(['git', 'ls-files', '-z'], $root);
        $process->run();

        if (! $process->isSuccessful()) {
            $errors[] = 'Не удалось получить список tracked-файлов git: '.$process->getErrorOutput();
        } else {
            $trackedFiles = array_values(array_filter(explode("\0", $process->getOutput())));
            foreach ($inspector->forbiddenTrackedFiles($trackedFiles) as $path) {
                $errors[] = 'Запрещённый tracked-файл: '.$path;
            }
        }
    } else {
        $this->warn('Git metadata не найдена, tracked-файлы не проверялись.');
    }

    if ((bool) $this->option('strict')) {
        array_push($errors, ...$inspector->strictLocalViolations($root));
    }

    if ($errors !== []) {
        foreach ($errors as $error) {
            $this->error($error);
        }

        return 1;
    }

    $this->info('Clean tree check passed.');

    return 0;
})->purpose('Check repository and clean archive hygiene');

Schedule::call(fn () => app(CatalogBulkPrices::class)->dispatchPending())->everyMinute()->name('catalog-prices-recovery')->withoutOverlapping();

Schedule::call(fn () => app(CatalogActions::class)->dispatchPending())->everyMinute()->name('catalog-actions-recovery')->withoutOverlapping();

Artisan::command('catalog:configurations {csv} {--apply : Install configuration definitions without changing existing products} {--confirm= : Confirmation code from the current CSV preview}', function (): int {
    $rows = app(CatalogConfigurationSource::class)->read($this->argument('csv'));
    $this->table(['Тип', 'Комбинаций'], collect($rows)->countBy('type')->map(fn ($count, $type) => [$type, $count])->values()->all());
    $review = app(\App\Services\Catalog\CatalogConfigurationInstallation::class)->preview($rows);
    $this->line(json_encode($review, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if ($this->option('apply')) {
        $this->line(json_encode(app(CatalogConfigurations::class)->install($this->argument('csv'), $this->option('confirm')), JSON_UNESCAPED_UNICODE));
    } else {
        $this->info('Только предпросмотр. Данные не изменены.');
    }

    return 0;
});

// Run all remaining types through the same confirmed, resumable actions used by Filament.
// Dry-run is the default; applying requires the current catalogue's preview token.
Artisan::command('catalog:configurations-remaining
    {--user-id=1 : Active superadministrator whose permissions authorize the operation}
    {--apply : Confirm and enqueue actions for remaining types}
    {--confirm= : Exact token emitted by a fresh dry-run}
    {--timeout=1200 : Maximum seconds to wait per type}', function (): int {
    $connection = \Illuminate\Support\Facades\DB::connection();
    if (! app()->environment('local')
        || $connection->getDriverName() !== 'mysql'
        || $connection->getDatabaseName() !== 'dvashop'
        || ! in_array($connection->getConfig('host'), ['mysql', 'dvashop_mysql'], true)) {
        $this->error('REFUSED: Only the explicitly approved local dvashop MySQL is allowed.');

        return 1;
    }

    if (! app(CatalogConfigurations::class)->ready()
        || \Illuminate\Support\Facades\DB::table('catalog_configurations')->where('is_active', true)->count() < 106) {
        $this->error('REFUSED: Configuration matrix is not installed.');

        return 1;
    }

    $user = \App\Models\User::find((int) $this->option('user-id'));
    if (! $user?->isActiveSuperAdmin()) {
        $this->error('REFUSED: Active superadministrator required.');

        return 1;
    }

    $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7200]]);
    if ($timeout === false) {
        $this->error('Invalid --timeout (1–7200 seconds).');

        return 1;
    }

    $db = \Illuminate\Support\Facades\DB::connection();
    if ($db->table('import_runs')->whereIn('status', ['running', 'running_rows', 'paused'])->exists()
        || $db->table('catalog_actions')->whereIn('status', ['pending', 'running', 'paused'])->exists()
        || $db->table('catalog_price_locks')->whereNotNull('catalog_action_id')->exists()) {
        $this->error('REFUSED: An import, action or catalog reservation is unfinished.');

        return 1;
    }

    $service = app(CatalogActions::class);
    $typeIds = $db->table('catalog_configurations')->distinct()->orderBy('part_type_id')->pluck('part_type_id');
    $plan = [];
    $summary = array_fill_keys(['products', 'changed', 'protected', 'variants_to_update', 'variants_to_create', 'variants_to_retire'], 0);

    foreach ($typeIds as $typeId) {
        $parameters = ['kind' => 'configurations', 'type_id' => (int) $typeId,
            'descendants' => false, 'source_status' => 'all', 'force' => false];
        $preview = $service->preview($user, $parameters);
        $counts = $preview['counts'];
        if ($counts['products'] === 0 || $counts['changed'] === 0) {
            continue; // Finished types (including previous successful runs) are automatically skipped.
        }
        if ($counts['protected'] !== 0) {
            $this->error("REFUSED: Type {$typeId} contains {$counts['protected']} protected products. Review it separately in Filament.");

            return 1;
        }
        $plan[] = ['type_id' => (int) $typeId, 'hash' => $preview['hash'], 'counts' => $counts];
        foreach ($summary as $key => &$total) {
            $total += $counts[$key];
        }
        unset($total);
    }

    $this->info('Local DVASHOP — remaining configuration types');
    $this->table(['Type ID', 'Products', 'Changed', 'Create', 'Retire', 'Price updates'], array_map(
        fn (array $row) => [$row['type_id'], $row['counts']['products'], $row['counts']['changed'],
            $row['counts']['variants_to_create'], $row['counts']['variants_to_retire'], $row['counts']['variants_to_update']], $plan));
    $this->line('Summary: '.json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    if ($plan === []) {
        $this->info('NOTHING_TO_CHANGE: all installed types already match their matrices.');

        return 0;
    }

    $token = strtoupper(substr(hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)), 0, 20));
    if (! $this->option('apply')) {
        $this->info('DRY_RUN_PASS: no database changes were made.');
        $this->line('When verified, run: php artisan catalog:configurations-remaining --apply --confirm='.$token);

        return 0;
    }
    if (! hash_equals($token, (string) $this->option('confirm'))) {
        $this->error('REFUSED: Missing or stale confirmation token. Run dry-run again.');

        return 1;
    }

    foreach ($plan as $item) {
        $typeId = $item['type_id'];
        // Recalculate immediately before confirming: service checks the complete catalogue fingerprint transactionally.
        $parameters = ['kind' => 'configurations', 'type_id' => $typeId,
            'descendants' => false, 'source_status' => 'all', 'force' => false];
        $preview = $service->preview($user, $parameters);
        if (! hash_equals($item['hash'], $preview['hash'])) {
            $this->error("STOP: Type {$typeId} changed since preview. Re-run dry-run and review the new plan.");

            return 1;
        }
        $id = $service->confirm($user, $preview); // Uses catalog reservation, durable journal, queued batches.
        $this->line("Action #{$id}: type {$typeId}, waiting for background queue and confirmed publication…");
        $started = time();
        $lastProgress = -1;
        while (true) {
            $op = $db->table('catalog_actions')->find($id);
            if (! $op) {
                $this->error("STOP: Action #{$id} disappeared.");

                return 1;
            }
            if ($op->status === 'paused' || $op->status === 'canceled') {
                $this->error("STOP: Action #{$id} {$op->status}: ".($op->error ?? 'no error').'. Do not start another action.');

                return 1;
            }
            $report = json_decode($op->report ?? '{}', true);
            $processed = (int) ($report['processed'] ?? 0);
            if ($processed !== $lastProgress) {
                $this->line("  processed {$processed}/".($report['total'] ?? '?')."; status {$op->status}");
                $lastProgress = $processed;
            }
            if ($op->status === 'done' && $op->published_at !== null) {
                break;
            }
            if (time() - $started >= $timeout) {
                $this->error("STOP: Action #{$id} did not finish and publish within {$timeout}s. Check queue and journal; do not start another action.");

                return 1;
            }
            sleep(2);
        }
        $repeat = $service->preview($user, $parameters);
        if ($repeat['counts']['changed'] !== 0) {
            $this->error("STOP: Type {$typeId} is not idempotent after action #{$id}. Review before continuing.");

            return 1;
        }
        $this->info("Type {$typeId}: PASS (action #{$id}, confirmed publication, 0 repeat changes).");
    }

    $this->info('ALL_REMAINING_CONFIGURATIONS=PASS');

    return 0;
})->purpose('Safely preview or apply all remaining catalogue matrices using durable CatalogActions');
