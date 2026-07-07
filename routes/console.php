<?php

use App\Services\Import\ImportFileInspector;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

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

    if ($result['penka_leak_detected']) {
        $this->warn('Внимание: обнаружено ошибочное попадание P:S внутрь Пенка.');
    } else {
        $this->info('Проверка P:S: корневые категории не попали внутрь Пенка.');
    }

    return 0;
})->purpose('Inspect catalog import file without writing to database');


Artisan::command('project:check-clean-tree {--strict : Also check physical local files before manual clean packaging}', function (): int {
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
            $forbiddenTracked = [];

            foreach ($trackedFiles as $path) {
                $isEnvExample = in_array($path, ['.env.example', '.env.docker.example'], true);

                if ($path === '.env'
                    || ($path !== '.env.example' && $path !== '.env.docker.example' && str_starts_with($path, '.env.'))
                    || $path === '.env.local.bak'
                    || $path === 'public/storage'
                    || str_starts_with($path, 'public/storage/')
                    || $path === 'public/hot'
                    || str_starts_with($path, 'public/hot/')
                    || preg_match('#^bootstrap/cache/.+\.php$#', $path)
                    || preg_match('#(^|/)vendor/#', $path)
                    || preg_match('#(^|/)node_modules/#', $path)
                    || str_ends_with($path, '.patch')
                    || str_ends_with($path, ':Zone.Identifier')) {
                    if (! $isEnvExample) {
                        $forbiddenTracked[] = $path;
                    }
                }
            }

            foreach ($forbiddenTracked as $path) {
                $errors[] = 'Запрещённый tracked-файл: '.$path;
            }
        }
    } else {
        $this->warn('Git metadata не найдена, tracked-файлы не проверялись.');
    }

    if ((bool) $this->option('strict')) {
        $forbiddenLocalPaths = [
            '.env',
            '.env.local.bak',
            'public/hot',
            'public/storage',
            'bootstrap/cache/packages.php',
            'bootstrap/cache/services.php',
            'bootstrap/cache/config.php',
            'bootstrap/cache/events.php',
        ];

        foreach ($forbiddenLocalPaths as $path) {
            if (file_exists($root.DIRECTORY_SEPARATOR.$path) || is_link($root.DIRECTORY_SEPARATOR.$path)) {
                $errors[] = 'Запрещённый локальный файл/ссылка для strict clean tree: '.$path;
            }
        }

        foreach (glob($root.DIRECTORY_SEPARATOR.'bootstrap/cache/routes*.php') ?: [] as $path) {
            $errors[] = 'Запрещённый generated route cache: '.str_replace($root.DIRECTORY_SEPARATOR, '', $path);
        }

        foreach (glob($root.DIRECTORY_SEPARATOR.'*.patch') ?: [] as $path) {
            $errors[] = 'Patch-файл в корне проекта: '.basename($path);
        }

        foreach (glob($root.DIRECTORY_SEPARATOR.'*:Zone.Identifier') ?: [] as $path) {
            $errors[] = 'Zone.Identifier в корне проекта: '.basename($path);
        }
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
