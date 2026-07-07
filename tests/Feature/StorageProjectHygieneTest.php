<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

test('temporary project files are ignored and not present in repository root', function () {
    $root = base_path();
    $gitignore = file_get_contents(base_path('.gitignore')) ?: '';

    expect($gitignore)->toContain('.env')
        ->and($gitignore)->toContain('.env.*')
        ->and($gitignore)->toContain('!.env.example')
        ->and($gitignore)->toContain('!.env.docker.example')
        ->and($gitignore)->toContain('/public/hot')
        ->and($gitignore)->toContain('/public/storage')
        ->and($gitignore)->toContain('*.patch')
        ->and($gitignore)->toContain('*:Zone.Identifier')
        ->and(file_exists($root.'/public/hot'))->toBeFalse()
        ->and(file_exists($root.'/.env.local.bak'))->toBeFalse()
        ->and(glob($root.'/*.patch') ?: [])->toBeEmpty()
        ->and(glob($root.'/*:Zone.Identifier') ?: [])->toBeEmpty();
});

test('local env and public storage are not tracked by git when repository metadata is available', function () {
    if (! is_dir(base_path('.git'))) {
        expect(true)->toBeTrue();

        return;
    }

    $process = new Process(['git', 'ls-files', '.env', '.env.local.bak', 'public/storage'], base_path());
    $process->run();

    expect(trim($process->getOutput()))->toBe('');
});
