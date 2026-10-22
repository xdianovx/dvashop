<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Append-only audit of current price changes; never mutates order item price snapshots. */
final class CatalogMatrixPriceHistory
{
    public function sourceSha256(): string
    {
        $source = DB::table('catalog_matrix_sources')->where('id', 1)->first();
        if (! $source || ! preg_match('/^[a-f0-9]{64}$/D', (string) $source->sha256)) {
            throw ValidationException::withMessages([
                'configuration' => 'Источник CSV не зафиксирован. Переустановите исходную матрицу перед синхронизацией.',
            ]);
        }

        return $source->sha256;
    }

    public function record(
        int $productId,
        ?int $variantId,
        ?int $configurationId,
        ?string $previousPrice,
        ?string $previousMode,
        string $newPrice,
        string $newMode,
        string $operationRef,
        string $sourceSha256,
    ): void {
        if ($previousPrice === $newPrice && $previousMode === $newMode) {
            return;
        }
        DB::table('catalog_matrix_price_history')->insert([
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'catalog_configuration_id' => $configurationId,
            'old_price' => $previousPrice,
            'old_price_mode' => $previousMode,
            'new_price' => $newPrice,
            'new_price_mode' => $newMode,
            'operation_ref' => $operationRef,
            'source_sha256' => $sourceSha256,
            'created_at' => now(),
        ]);
    }
}
