<?php

use App\Support\ProjectCleanTreeInspector;

test('project clean tree rejects tracked archives and runtime files', function () {
    $violations = (new ProjectCleanTreeInspector)->forbiddenTrackedFiles([
        '.env.example',
        '.env.docker.example',
        '.env.testing.example',
        'README.md',
        'backup.tar.gz',
        'backup.tgz',
        'fixtures/catalog.zip',
        'public/hot',
        'download.csv:Zone.Identifier',
    ]);

    expect($violations)->toBe([
        'backup.tar.gz',
        'backup.tgz',
        'fixtures/catalog.zip',
        'public/hot',
        'download.csv:Zone.Identifier',
    ]);
});

test('project clean tree accepts a clean tracked file list', function () {
    $violations = (new ProjectCleanTreeInspector)->forbiddenTrackedFiles([
        '.env.example',
        '.env.docker.example',
        '.env.testing.example',
        'app/Models/Product.php',
        'public/img/products_default/porog.png',
    ]);

    expect($violations)->toBeEmpty();
});

test('project clean tree strict mode detects physical hot file archives and zone metadata', function () {
    $root = sys_get_temp_dir().'/dvashop-clean-tree-'.bin2hex(random_bytes(8));
    mkdir($root.'/public', 0777, true);
    mkdir($root.'/storage/imports', 0777, true);
    file_put_contents($root.'/public/hot', 'http://localhost:5173');
    file_put_contents($root.'/dvashop_clean_20260728_120000.tar.gz', 'archive');
    file_put_contents($root.'/backup.zip', 'archive');
    file_put_contents($root.'/storage/imports/catalog.csv:Zone.Identifier', 'metadata');

    try {
        $violations = (new ProjectCleanTreeInspector)->strictLocalViolations($root);

        expect($violations)
            ->toContain('Запрещённый локальный файл/ссылка для strict clean tree: public/hot')
            ->toContain('Архив в корне проекта: dvashop_clean_20260728_120000.tar.gz')
            ->toContain('Архив в корне проекта: backup.zip')
            ->toContain('Zone.Identifier в проекте: storage/imports/catalog.csv:Zone.Identifier');
    } finally {
        unlink($root.'/public/hot');
        unlink($root.'/dvashop_clean_20260728_120000.tar.gz');
        unlink($root.'/backup.zip');
        unlink($root.'/storage/imports/catalog.csv:Zone.Identifier');
        rmdir($root.'/storage/imports');
        rmdir($root.'/storage');
        rmdir($root.'/public');
        rmdir($root);
    }
});
