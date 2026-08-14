<?php

namespace App\Services\Import;

use App\Models\PartType;

final class CatalogImportPriceResolver
{
    /**
     * Reference prices extracted from the dvaporoga.ru Yandex feed.
     *
     * Source: https://dvaporoga.ru/feeds/yandex.yml
     * Feed snapshot: 2026-07-23 10:30
     * Feed SHA-256: 81647599e3dda7f505b83d796102f0825b42dfa15089d55d8c5d0703c9b7cce0
     * Extracted TSV SHA-256: 81ee6e0d8730f513b59dea313a35bcc47df1c65f4dd9b8120b59dd22de30fc09
     *
     * Runtime import never fetches the source URL. The mapping is intentionally
     * deterministic and keyed only by PartType.full_slug.
     *
     * @var array<string, array{price: string, old_price: string|null}>
     */
    private const PRICES = [
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

    /** @return array{price: string, old_price: string|null}|null */
    public function resolve(PartType|string $partType): ?array
    {
        $fullSlug = $partType instanceof PartType ? (string) $partType->full_slug : $partType;

        return self::PRICES[$fullSlug] ?? null;
    }
}
