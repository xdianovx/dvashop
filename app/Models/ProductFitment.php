<?php

namespace App\Models;

use App\Services\Catalog\ProductAdminService;
use Database\Factories\ProductFitmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'vehicle_generation_id',
    'note',
    'is_primary',
])]
class ProductFitment extends Model
{
    /** @use HasFactory<ProductFitmentFactory> */
    use HasFactory;

    public function save(array $options = []): bool
    {
        return app(ProductAdminService::class)
            ->saveFitment($this, fn (): bool => parent::save($options));
    }

    protected static function booted(): void
    {
        static::saving(fn (self $fitment) => app(ProductAdminService::class)->validateFitment($fitment));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(VehicleGeneration::class, 'vehicle_generation_id');
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }
}
