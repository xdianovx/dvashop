<?php

use App\Enums\ImportRunStatus;
use App\Filament\Pages\CatalogImportPage;
use App\Models\ImportRun;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('catalog import page access follows the role and account status matrix', function (string $state, bool $allowed): void {
    $user = match ($state) {
        'super_admin' => User::factory()->superAdmin()->create(),
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'inactive' => User::factory()->superAdmin()->inactive()->create(),
        'blocked' => User::factory()->superAdmin()->blocked()->create(),
        default => User::factory()->create(),
    };
    $this->actingAs($user);

    expect(CatalogImportPage::canAccess(), $state)->toBe($allowed);

    $response = $this->get(CatalogImportPage::getUrl());

    if ($allowed) {
        $response->assertOk();
    } else {
        expect($response->getStatusCode(), $state)->not->toBe(200)
            ->and($response->getStatusCode(), $state)->not->toBe(500);
    }
})->with([
    'super admin' => ['super_admin', true],
    'admin' => ['admin', true],
    'manager' => ['manager', false],
    'customer' => ['customer', false],
    'inactive super admin' => ['inactive', false],
    'blocked super admin' => ['blocked', false],
]);

test('every import lifecycle method rejects forbidden actors before changing state', function (string $state): void {
    $actor = match ($state) {
        'manager' => User::factory()->manager()->create(),
        'inactive' => User::factory()->superAdmin()->inactive()->create(),
        'blocked' => User::factory()->superAdmin()->blocked()->create(),
        default => User::factory()->create(),
    };
    $this->actingAs($actor);
    $page = app(CatalogImportPage::class);
    $ready = ImportRun::factory()->create(['status' => ImportRunStatus::Ready]);
    $running = ImportRun::factory()->create(['status' => ImportRunStatus::RunningRows]);
    $paused = ImportRun::factory()->create(['status' => ImportRunStatus::Paused]);
    $assertForbidden = function (callable $operation) use ($state): void {
        try {
            $operation();
            test()->fail("{$state}: import action must be forbidden.");
        } catch (HttpException $exception) {
            expect($exception->getStatusCode(), $state)->toBe(403);
        }
    };

    $assertForbidden(fn () => $page->submitImport());
    $assertForbidden(fn () => $page->start($ready->getKey(), notify: false));
    $assertForbidden(fn () => $page->pause($running->getKey()));
    $assertForbidden(fn () => $page->resume($paused->getKey()));
    $assertForbidden(fn () => $page->cancel($ready->getKey()));
    $assertForbidden(fn () => $page->downloadOriginal($ready->getKey()));
    $assertForbidden(fn () => $page->downloadLogs($ready->getKey()));
    $assertForbidden(fn () => $page->downloadReport($ready->getKey()));

    expect($ready->refresh()->status)->toBe(ImportRunStatus::Ready)
        ->and($running->refresh()->status)->toBe(ImportRunStatus::RunningRows)
        ->and($paused->refresh()->status)->toBe(ImportRunStatus::Paused)
        ->and(ImportRun::query()->count())->toBe(3);
})->with([
    'manager' => ['manager'],
    'customer' => ['customer'],
    'inactive super admin' => ['inactive'],
    'blocked super admin' => ['blocked'],
]);

test('manager cannot regain import access through a stale panel session', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get(CatalogImportPage::getUrl())->assertOk();

    $admin->forceFill(['role' => 'manager'])->save();
    auth()->forgetGuards();

    $response = $this->get(CatalogImportPage::getUrl());

    expect($response->getStatusCode())->not->toBe(200)
        ->and($response->getStatusCode())->not->toBe(500);
});

test('catalog import source contains explicit authorization for every state changing method', function (): void {
    $source = file_get_contents(app_path('Filament/Pages/CatalogImportPage.php'));

    expect($source)->toContain('AdminPermission::ManageCatalogImports')
        ->and(substr_count($source, '$this->authorizeImportAction();'))->toBeGreaterThanOrEqual(8)
        ->and(substr_count($source, '->authorize(fn (): bool => static::canAccess())'))->toBeGreaterThanOrEqual(10);
});
