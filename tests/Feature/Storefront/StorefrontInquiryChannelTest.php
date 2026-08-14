<?php

use App\Enums\StorefrontInquiryType;
use App\Events\StorefrontInquiryCreated;
use App\Listeners\SendInquiryEmail;
use App\Listeners\SendInquiryToBitrix;
use App\Mail\StorefrontInquiryMail;
use App\Models\StorefrontInquiry;
use App\Services\Settings\ShopSettingsService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function inquiryForDelivery(): StorefrontInquiry
{
    return StorefrontInquiry::factory()->create([
        'type' => StorefrontInquiryType::ProductConsultation,
        'name' => 'Иван Петров',
        'phone' => '+79991112233',
        'email' => 'ivan@example.test',
        'message' => 'Перезвоните после 18:00',
        'product_title_snapshot' => 'Исторический товар',
        'variant_sku_snapshot' => 'HISTORIC-SKU',
        'options_snapshot' => ['material' => ['group' => 'Материал', 'value' => 'Сталь']],
        'source_url' => 'https://shop.example.test/products/historic',
        'source_code' => 'product',
    ]);
}

beforeEach(function (): void {
    config()->set([
        'shop.inquiries.manager_email' => 'env-manager@example.test',
        'shop.bitrix.webhook_url' => 'https://bitrix.example.test/rest/1/secret-token',
        'shop.bitrix.inquiry_method' => 'crm.lead.add',
    ]);
    Mail::fake();
});

test('inquiry channels honor every feature flag combination', function (bool $emailEnabled, bool $bitrixEnabled): void {
    Http::fake(['bitrix.example.test/*' => Http::response(['result' => 731], 200)]);
    config()->set([
        'shop.inquiries.email_enabled' => $emailEnabled,
        'shop.inquiries.bitrix_enabled' => $bitrixEnabled,
    ]);
    $inquiry = inquiryForDelivery();
    $event = new StorefrontInquiryCreated($inquiry);

    app(SendInquiryEmail::class)->handle($event);
    app(SendInquiryToBitrix::class)->handle($event);

    if ($emailEnabled) {
        Mail::assertSent(StorefrontInquiryMail::class, fn (StorefrontInquiryMail $mail): bool => $mail->hasTo('env-manager@example.test'));
    } else {
        Mail::assertNothingSent();
    }

    Http::assertSentCount($bitrixEnabled ? 1 : 0);
    $inquiry->refresh();
    expect($inquiry->email_sent_at !== null)->toBe($emailEnabled)
        ->and($inquiry->bitrix_sent_at !== null)->toBe($bitrixEnabled)
        ->and($inquiry->bitrix_entity_id)->toBe($bitrixEnabled ? '731' : null);
})->with([
    'email on bitrix off' => [true, false],
    'email off bitrix on' => [false, true],
    'both on' => [true, true],
    'both off' => [false, false],
]);

test('database inquiry email takes priority over env fallback and mail uses snapshots', function (): void {
    $setting = app(ShopSettingsService::class)->current();
    $setting->forceFill(['inquiry_notification_email' => 'db-manager@example.test'])->save();
    config()->set('shop.inquiries.email_enabled', true);
    $inquiry = inquiryForDelivery();

    app(SendInquiryEmail::class)->handle(new StorefrontInquiryCreated($inquiry));

    Mail::assertSent(StorefrontInquiryMail::class, function (StorefrontInquiryMail $mail): bool {
        $html = $mail->render();

        return $mail->hasTo('db-manager@example.test')
            && str_contains($html, 'Исторический товар')
            && str_contains($html, 'HISTORIC-SKU')
            && str_contains($html, 'Материал: Сталь');
    });
});

test('inquiry email falls back to environment and a missing recipient only logs a warning', function (): void {
    $setting = app(ShopSettingsService::class)->current();
    $setting->forceFill(['inquiry_notification_email' => null])->save();
    config()->set('shop.inquiries.email_enabled', true);
    $first = inquiryForDelivery();

    app(SendInquiryEmail::class)->handle(new StorefrontInquiryCreated($first));
    Mail::assertSent(StorefrontInquiryMail::class, fn (StorefrontInquiryMail $mail): bool => $mail->hasTo('env-manager@example.test'));

    Mail::fake();
    Log::spy();
    config()->set('shop.inquiries.manager_email', null);
    $second = inquiryForDelivery();
    app(SendInquiryEmail::class)->handle(new StorefrontInquiryCreated($second));

    Mail::assertNothingSent();
    expect($second->refresh()->email_sent_at)->toBeNull()
        ->and($second->email_failed_at)->not->toBeNull()
        ->and($second->email_delivery_status)->toBe('Ошибка');
    Log::shouldHaveReceived('warning')->once();
});

test('bitrix sends only standard fields without invented custom fields', function (): void {
    Http::fake(['bitrix.example.test/*' => Http::response(['result' => 731], 200)]);
    config()->set('shop.inquiries.bitrix_enabled', true);

    app(SendInquiryToBitrix::class)->handle(new StorefrontInquiryCreated(inquiryForDelivery()));

    Http::assertSent(function (Request $request): bool {
        $fields = $request->data()['fields'] ?? [];

        return $request->url() === 'https://bitrix.example.test/rest/1/secret-token/crm.lead.add.json'
            && array_keys($fields) === ['TITLE', 'NAME', 'PHONE', 'EMAIL', 'COMMENTS']
            && ! str_contains(json_encode($fields, JSON_THROW_ON_ERROR), 'UF_')
            && str_contains($fields['COMMENTS'], 'Исторический товар')
            && str_contains($fields['COMMENTS'], 'Код источника: product')
            && str_contains($fields['COMMENTS'], 'https://shop.example.test/products/historic');
    });
});

test('bitrix failure keeps local inquiry and does not prevent email delivery', function (): void {
    config()->set([
        'shop.inquiries.email_enabled' => true,
        'shop.inquiries.bitrix_enabled' => true,
    ]);
    Http::fake(['bitrix.example.test/*' => Http::response(['error' => 'temporary'], 500)]);
    $inquiry = inquiryForDelivery();
    $event = new StorefrontInquiryCreated($inquiry);

    expect(fn () => app(SendInquiryToBitrix::class)->handle($event))
        ->toThrow(RequestException::class);
    app(SendInquiryEmail::class)->handle($event);

    expect(StorefrontInquiry::query()->whereKey($inquiry->getKey())->exists())->toBeTrue()
        ->and($inquiry->refresh()->bitrix_failed_at)->not->toBeNull()
        ->and($inquiry->email_sent_at)->not->toBeNull();
    Mail::assertSent(StorefrontInquiryMail::class);
});

test('email failure keeps local inquiry and does not prevent bitrix delivery', function (): void {
    Http::fake(['bitrix.example.test/*' => Http::response(['result' => 731], 200)]);
    config()->set([
        'shop.inquiries.email_enabled' => true,
        'shop.inquiries.bitrix_enabled' => true,
    ]);
    $inquiry = inquiryForDelivery();
    $event = new StorefrontInquiryCreated($inquiry);
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP unavailable'));

    expect(fn () => app(SendInquiryEmail::class)->handle($event))->toThrow(RuntimeException::class);
    app(SendInquiryToBitrix::class)->handle($event);

    expect(StorefrontInquiry::query()->whereKey($inquiry->getKey())->exists())->toBeTrue()
        ->and($inquiry->refresh()->email_failed_at)->not->toBeNull()
        ->and($inquiry->bitrix_sent_at)->not->toBeNull();
    Http::assertSentCount(1);
});

test('inquiry event has exactly two independent after commit queued listeners', function (): void {
    $listeners = app(Dispatcher::class)->getRawListeners()[StorefrontInquiryCreated::class] ?? [];

    expect($listeners)->toHaveCount(2)
        ->and($listeners)->toEqualCanonicalizing([
            SendInquiryEmail::class.'@handle',
            SendInquiryToBitrix::class.'@handle',
        ])
        ->and(app(SendInquiryEmail::class))->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and(app(SendInquiryToBitrix::class))->toBeInstanceOf(ShouldQueueAfterCommit::class);
});

test('confirmed inquiry channels are idempotent across retries', function (): void {
    Http::fake(['bitrix.example.test/*' => Http::response(['result' => 731], 200)]);
    config()->set([
        'shop.inquiries.email_enabled' => true,
        'shop.inquiries.bitrix_enabled' => true,
    ]);
    $inquiry = inquiryForDelivery();
    $event = new StorefrontInquiryCreated($inquiry);

    app(SendInquiryEmail::class)->handle($event);
    app(SendInquiryToBitrix::class)->handle($event);
    app(SendInquiryEmail::class)->handle($event);
    app(SendInquiryToBitrix::class)->handle($event);

    Mail::assertSentCount(1);
    Http::assertSentCount(1);
});
