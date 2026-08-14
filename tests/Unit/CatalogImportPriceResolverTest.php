<?php

use App\Services\Import\CatalogImportPriceResolver;

test('catalog import reference price resolver contains the exact approved PartType mapping', function (): void {
    $resolver = new CatalogImportPriceResolver;
    $expected = [
        'porog' => ['price' => '1790.00', 'old_price' => null],
        'arka/zadniaia' => ['price' => '1950.00', 'old_price' => null],
        'arka/peredniaia' => ['price' => '1950.00', 'old_price' => null],
        'arka/vnutrenniaia' => ['price' => '2090.00', 'old_price' => null],
        'arka/vnutrenniaia-universalnaia' => ['price' => '2090.00', 'old_price' => null],
        'arka/karman-zadniaia' => ['price' => '1950.00', 'old_price' => null],
        'penka/zadnei-dveri' => ['price' => '2090.00', 'old_price' => null],
        'penka/perednei-dveri' => ['price' => '2090.00', 'old_price' => null],
        'penka/bagazhnika' => ['price' => '2090.00', 'old_price' => '2500.00'],
        'lonzheron' => ['price' => '1200.00', 'old_price' => null],
        'remkomplekt-pola' => ['price' => '2190.00', 'old_price' => null],
        'tortsevaia-zaglushka' => ['price' => '600.00', 'old_price' => null],
        'usilitel/soedinitel-porogov' => ['price' => '900.00', 'old_price' => null],
    ];

    foreach ($expected as $fullSlug => $reference) {
        expect($resolver->resolve($fullSlug))->toBe($reference);
    }

    expect($resolver->resolve('arka'))->toBeNull()
        ->and($resolver->resolve('penka'))->toBeNull()
        ->and($resolver->resolve('usilitel'))->toBeNull()
        ->and($resolver->resolve('unknown'))->toBeNull();
});
