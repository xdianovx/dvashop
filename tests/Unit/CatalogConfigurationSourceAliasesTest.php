<?php

use App\Services\Catalog\CatalogConfigurationSource;

// CSV parsing uses Laravel validation/facades; run with a bootstrapped application.
uses(\Tests\TestCase::class);

it('accepts current and legacy source labels for the one universal inner arch without generating extra combinations', function (): void {
    expect(CatalogConfigurationSource::TYPES['Барабан'])
        ->toBe(CatalogConfigurationSource::TYPES['Арка внутренняя универсальная']);

    $csv = tempnam(sys_get_temp_dir(), 'catalog-config-');
    try {
        // The thickness contains a comma and must be quoted in CSV.
        file_put_contents($csv, "Название,Профиль,Положение,Материал,Толщина металла,Цена\n".
            "Арка внутренняя универсальная,-,Левый,ХКС,\"0,8 мм\",2100\n");
        $rows = app(CatalogConfigurationSource::class)->read($csv);
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['type'])->toBe('arka/vnutrenniaia-universalnaia')
            ->and($rows[0]['price'])->toBe('2100.00');
    } finally {
        @unlink($csv);
    }
});
