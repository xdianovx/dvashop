<?php

namespace Tests\Support;

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

final class CatalogPricesFixture
{
    public static function create(int $count = 1949): array
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'sqlite') {
            throw new \RuntimeException('Pricing fixture requires an isolated testing SQLite connection.');
        }
        $type = PartType::factory()->create(['title' => 'Тестовые детали', 'base_price' => 100]);
        $sample = Product::factory()->forPartType($type)->create(['title' => 'Деталь для длинного названия автомобиля — проверка интерфейса', 'price' => 100, 'price_mode' => 'unknown']);
        $variant = ProductVariant::factory()->create(['product_id' => $sample->id, 'price' => 100, 'price_mode' => 'unknown', 'is_default' => true]);
        $p = (array) DB::table('products')->find($sample->id);
        $v = (array) DB::table('product_variants')->find($variant->id);
        unset($v['id']);
        for ($start = 0; $start < $count; $start += 50) {
            $products = [];
            $variants = [];
            for ($i = $start; $i < min($start + 50, $count); $i++) {
                $id = $sample->id + $i;
                if ($i > 0) {
                    $products[] = array_replace($p, ['id' => $id, 'slug' => 'pricing-fixture-'.$id, 'sku' => null,
                        'title' => 'Деталь автомобиля '.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'status' => $i % 4 === 0 ? 'archived' : 'active']);
                }
                for ($j = $i === 0 ? 1 : 0; $j < 24; $j++) {
                    $variants[] = array_replace($v, ['product_id' => $id, 'sku' => null, 'is_default' => $j === 0]);
                }
            }
            if ($products !== []) {
                DB::table('products')->insert($products);
            }
            foreach (array_chunk($variants, 100) as $chunk) {
                DB::table('product_variants')->insert($chunk);
            }
        }

        return [$type, $sample];
    }
}
