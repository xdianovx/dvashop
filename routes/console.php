<?php

use App\Services\Import\ImportFileInspector;
use App\Support\ProjectCleanTreeInspector;
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
