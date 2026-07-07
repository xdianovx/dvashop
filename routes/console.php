<?php

use App\Services\Import\ImportFileInspector;
use Illuminate\Support\Facades\Artisan;

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
