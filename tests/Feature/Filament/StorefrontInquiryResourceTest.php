<?php

use App\Enums\AdminPermission;
use App\Filament\Resources\StorefrontInquiries\StorefrontInquiryResource;
use App\Models\StorefrontInquiry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('inquiries resource is read only for manager admin and super admin', function (string $role): void {
    $actor = match ($role) {
        'super_admin' => User::factory()->superAdmin()->create(),
        'admin' => User::factory()->admin()->create(),
        'manager' => User::factory()->manager()->create(),
        'inactive' => User::factory()->admin()->inactive()->create(),
        'blocked' => User::factory()->admin()->blocked()->create(),
        default => User::factory()->create(),
    };
    $inquiry = StorefrontInquiry::factory()->create();
    $mayView = in_array($role, ['super_admin', 'admin', 'manager'], true);

    expect($actor->canPerformAdminAction(AdminPermission::ViewInquiries))->toBe($mayView)
        ->and($actor->can('viewAny', StorefrontInquiry::class))->toBe($mayView)
        ->and($actor->can('view', $inquiry))->toBe($mayView)
        ->and($actor->can('create', StorefrontInquiry::class))->toBeFalse()
        ->and($actor->can('update', $inquiry))->toBeFalse()
        ->and($actor->can('delete', $inquiry))->toBeFalse()
        ->and($actor->can('forceDelete', $inquiry))->toBeFalse();

    $this->actingAs($actor);

    foreach (['index' => [], 'view' => ['record' => $inquiry]] as $page => $parameters) {
        $response = $this->get(StorefrontInquiryResource::getUrl($page, $parameters));
        $mayView ? $response->assertOk() : expect($response->getStatusCode())->not->toBe(200);
    }
})->with([
    'super admin' => ['super_admin'],
    'admin' => ['admin'],
    'manager' => ['manager'],
    'customer' => ['customer'],
    'inactive admin' => ['inactive'],
    'blocked admin' => ['blocked'],
]);

test('inquiry list displays independent channel delivery status', function (): void {
    $admin = User::factory()->admin()->create();
    StorefrontInquiry::factory()->create([
        'name' => 'Клиент со статусом',
        'email_sent_at' => now(),
        'bitrix_failed_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(StorefrontInquiryResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Клиент со статусом')
        ->assertSee('Отправлено')
        ->assertSee('Ошибка');
});

test('inquiry detail exposes sent and failed timestamps without edit actions', function (): void {
    $admin = User::factory()->admin()->create();
    $inquiry = StorefrontInquiry::factory()->create([
        'email_failed_at' => now(),
        'bitrix_failed_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(StorefrontInquiryResource::getUrl('view', ['record' => $inquiry]))
        ->assertOk()
        ->assertSee('Ошибка Email')
        ->assertSee('Ошибка Bitrix');
});
