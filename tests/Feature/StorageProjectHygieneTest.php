<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('temporary project files are ignored and not present in repository root', function () {
    $root = base_path();
    $gitignore = file_get_contents(base_path('.gitignore')) ?: '';

    expect($gitignore)->toContain('.env')
        ->and($gitignore)->toContain('/public/hot')
        ->and($gitignore)->toContain('*.patch')
        ->and($gitignore)->toContain('*:Zone.Identifier')
        ->and(file_exists($root.'/public/hot'))->toBeFalse()
        ->and(glob($root.'/*.patch') ?: [])->toBeEmpty()
        ->and(glob($root.'/*:Zone.Identifier') ?: [])->toBeEmpty();
});
