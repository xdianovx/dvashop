<?php

use App\Enums\AdminPermission;
use App\Enums\StorefrontInquiryType;
use App\Filament\Resources\StorefrontInquiries\Pages\ViewStorefrontInquiry;
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

test('historical product consultation remains readable with saved product snapshots', function (): void {
    $inquiry = StorefrontInquiry::factory()->create([
        'type' => 'product_consultation',
        'product_title_snapshot' => 'Исторический порог',
        'variant_sku_snapshot' => 'HISTORY-SKU',
        'options_snapshot' => ['Материал' => 'Сталь'],
    ])->fresh();
    $before = $inquiry->getAttributes();
    expect($inquiry->type)->toBe(StorefrontInquiryType::ProductConsultation)
        ->and($inquiry->type->label())->toBe('Консультация по товару');
    $this->actingAs(User::factory()->admin()->create());
    $this->get(StorefrontInquiryResource::getUrl('index'))->assertOk()->assertSee('Консультация по товару');
    Livewire\Livewire::test(ViewStorefrontInquiry::class, ['record' => $inquiry->getKey()])
        ->assertSet('data.product_title_snapshot', 'Исторический порог')
        ->assertSet('data.variant_sku_snapshot', 'HISTORY-SKU')
        ->assertSet('data.options_snapshot', 'Материал: Сталь')
        ->assertSet('data.type', 'Консультация по товару');
    expect($inquiry->refresh()->getAttributes())->toBe($before);
});

test('historical checkout inquiry remains readable without changing its saved data', function (): void {
    $inquiry = StorefrontInquiry::factory()->create([
        'type' => StorefrontInquiryType::GeneralConsultation,
        'source_code' => 'checkout',
        'source_url' => route('checkout.show'),
        'message' => 'Историческая заявка в один клик',
        'email_sent_at' => now(),
        'bitrix_entity_id' => 'historical-lead',
    ])->fresh();
    $before = $inquiry->getAttributes();
    expect($inquiry->type)->toBe(StorefrontInquiryType::GeneralConsultation);
    $this->actingAs(User::factory()->admin()->create());
    $this->get(StorefrontInquiryResource::getUrl('index'))->assertOk();
    Livewire\Livewire::test(ViewStorefrontInquiry::class, ['record' => $inquiry->getKey()])
        ->assertSet('data.source_code', 'checkout')
        ->assertSet('data.source_url', route('checkout.show'))
        ->assertSet('data.message', 'Историческая заявка в один клик');
    expect($inquiry->refresh()->getAttributes())->toBe($before);
});
