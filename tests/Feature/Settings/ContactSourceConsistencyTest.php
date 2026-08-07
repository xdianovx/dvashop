<?php

use App\Models\ShopSetting;
use Database\Seeders\ShopSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('shop settings are the single seeded contact source without placeholder contacts', function (): void {
    $this->seed(ShopSettingsSeeder::class);
    $setting = ShopSetting::query()->sole();

    expect($setting->phone_display)->toBe('8 800 100 56 25')
        ->and($setting->phone_href)->toBe('+78001005625')
        ->and($setting->phone_caption)->toBe('Бесплатный звонок')
        ->and($setting->public_email)->toBeNull()
        ->and($setting->order_notification_email)->toBeNull()
        ->and($setting->work_hours)->toBeNull()
        ->and($setting->vk_url)->toBeNull()
        ->and($setting->telegram_url)->toBeNull()
        ->and($setting->legal_name)->toBe('ООО «АРТ ГРУПП»')
        ->and($setting->inn)->toBe('7814593546')
        ->and($setting->ogrn)->toBe('1137847459936')
        ->and($setting->legal_address)->toBe('192082, Россия, г. Санкт-Петербург, ул. Туристская, д. 23 к. 2');

    $seedSources = collect(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(database_path('seeders'), FilesystemIterator::SKIP_DOTS),
    ))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php')
        ->map(fn (SplFileInfo $file): string => (string) file_get_contents($file->getPathname()))
        ->implode("\n");

    foreach ([
        '+7 (777) 777-77-77',
        '+7 (906) 244-41-51',
        '+7 (939) 555-49-25',
        'info@example.ru',
        '+77777777777',
        '+79062444151',
        '+79395554925',
    ] as $placeholder) {
        expect($seedSources, $placeholder)->not->toContain($placeholder);
    }
});
