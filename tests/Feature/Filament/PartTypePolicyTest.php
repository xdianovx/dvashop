<?php

use App\Filament\Resources\PartTypes\Pages\ListPartTypes;
use App\Filament\Resources\PartTypes\PartTypeResource;
use App\Models\PartType;
use App\Models\User;
use App\Policies\PartTypePolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('part type policy is discovered and denies unsupported force deletion', function (): void {
    $partType = PartType::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();

    expect(app('auth')->getProvider())->not->toBeNull()
        ->and(app('Illuminate\Contracts\Auth\Access\Gate')->getPolicyFor(PartType::class))->toBeInstanceOf(PartTypePolicy::class)
        ->and($superAdmin->can('view', $partType))->toBeTrue()
        ->and($superAdmin->can('create', PartType::class))->toBeTrue()
        ->and($superAdmin->can('update', $partType))->toBeTrue()
        ->and($superAdmin->can('delete', $partType))->toBeTrue()
        ->and($superAdmin->can('restore', $partType))->toBeTrue()
        ->and($superAdmin->can('forceDelete', $partType))->toBeFalse()
        ->and($superAdmin->can('forceDeleteAny', PartType::class))->toBeFalse();
});

test('manager can view part types but cannot mutate them through policy url or actions', function (): void {
    $manager = User::factory()->manager()->create();
    $partType = PartType::factory()->create();
    $this->actingAs($manager);

    $this->get(PartTypeResource::getUrl('index'))->assertOk();
    expect($this->get(PartTypeResource::getUrl('create'))->getStatusCode())->not->toBe(200)
        ->and($this->get(PartTypeResource::getUrl('edit', ['record' => $partType]))->getStatusCode())->not->toBe(200)
        ->and($manager->can('view', $partType))->toBeTrue()
        ->and($manager->can('create', PartType::class))->toBeFalse()
        ->and($manager->can('update', $partType))->toBeFalse()
        ->and($manager->can('delete', $partType))->toBeFalse()
        ->and($manager->can('restore', $partType))->toBeFalse();

    Livewire::test(ListPartTypes::class)
        ->assertTableActionHidden('edit', $partType)
        ->assertTableActionHidden('delete', $partType)
        ->assertTableActionHidden('restore', $partType)
        ->assertTableBulkActionHidden('delete')
        ->assertTableBulkActionHidden('restore');
});

test('admin can create update soft delete and restore part types', function (): void {
    $admin = User::factory()->admin()->create();
    $partType = PartType::factory()->create();
    $this->actingAs($admin);

    $this->get(PartTypeResource::getUrl('index'))->assertOk();
    $this->get(PartTypeResource::getUrl('create'))->assertOk();
    $this->get(PartTypeResource::getUrl('edit', ['record' => $partType]))->assertOk();

    expect($admin->can('delete', $partType))->toBeTrue()
        ->and($admin->can('restore', $partType))->toBeTrue()
        ->and($admin->can('forceDelete', $partType))->toBeFalse();
});

test('inactive and blocked super admins have no part type permissions', function (): void {
    $partType = PartType::factory()->create();

    foreach ([
        User::factory()->superAdmin()->inactive()->create(),
        User::factory()->superAdmin()->blocked()->create(),
    ] as $actor) {
        expect($actor->can('viewAny', PartType::class))->toBeFalse()
            ->and($actor->can('view', $partType))->toBeFalse()
            ->and($actor->can('create', PartType::class))->toBeFalse()
            ->and($actor->can('update', $partType))->toBeFalse()
            ->and($actor->can('delete', $partType))->toBeFalse()
            ->and($actor->can('restore', $partType))->toBeFalse();
    }
});
