<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** One-time reference bootstrap; never used to resolve runtime prices. */
class CatalogBasePriceSeeder extends Seeder
{
    public const PRICES = [
        'porog/standartnyi' => ['price' => '1790.00', 'old_price' => null],
        'porog/uvelichennyi' => ['price' => '1790.00', 'old_price' => null],
        'arka/vnutrenniaia-zadniaia' => ['price' => '2090.00', 'old_price' => null],
        'arka/vnutrenniaia-peredniaia' => ['price' => '2090.00', 'old_price' => null],
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

    public function run(): void
    {
        foreach (self::PRICES as $slug => $prices) {
            DB::table('part_types')->where('full_slug', $slug)->whereNull('base_price')->update([
                'base_price' => $prices['price'], 'base_old_price' => $prices['old_price'],
            ]);
        }
    }
}
