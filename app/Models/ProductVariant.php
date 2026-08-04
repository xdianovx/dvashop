<?php

namespace App\Models;

use App\Enums\StockStatus;
use App\Services\Catalog\ProductVariantAdminService;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'product_id',
    'sku',
    'title',
    'options',
    'price',
    'old_price',
    'stock_quantity',
    'stock_status',
    'is_default',
    'is_active',
])]
class ProductVariant extends Model
{
    private const MANAGEMENT_METADATA_KEY = '__dvashop';

    public const MANAGEMENT_EXPLICIT = 'explicit';

    public const MANAGEMENT_TECHNICAL = 'technical';

    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    public function save(array $options = []): bool
    {
        return DB::transaction(fn (): bool => app(ProductVariantAdminService::class)
            ->guardVariantSkuSave(fn (): bool => parent::save($options)));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function variantOptionValues(): HasMany
    {
        return $this->hasMany(ProductVariantOptionValue::class);
    }

    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionValue::class, 'product_variant_option_values')
            ->withPivot('product_option_group_id')
            ->withTimestamps();
    }

    /** @return array<string, array{management: string}> */
    public static function technicalOptions(): array
    {
        return [
            self::MANAGEMENT_METADATA_KEY => [
                'management' => self::MANAGEMENT_TECHNICAL,
            ],
        ];
    }

    public function isTechnical(): bool
    {
        return data_get($this->options, self::MANAGEMENT_METADATA_KEY.'.management') === self::MANAGEMENT_TECHNICAL;
    }

    public function managementMode(): string
    {
        return $this->isTechnical() ? self::MANAGEMENT_TECHNICAL : self::MANAGEMENT_EXPLICIT;
    }

    /** @return array<string, mixed>|null */
    public static function optionsWithoutManagementMetadata(mixed $options): ?array
    {
        if (! is_array($options)) {
            return null;
        }

        unset($options[self::MANAGEMENT_METADATA_KEY]);

        return $options === [] ? null : $options;
    }

    /** @return array<array-key, mixed> */
    public function publicOptionsSnapshot(): array
    {
        $this->loadMissing('optionValues.group');

        $values = $this->optionValues
            ->filter(fn (ProductOptionValue $value): bool => $value->group instanceof ProductOptionGroup)
            ->sortBy(fn (ProductOptionValue $value): string => sprintf(
                '%010d:%010d:%010d',
                (int) $value->group?->position,
                (int) $value->position,
                (int) $value->getKey(),
            ));

        if ($values->isEmpty()) {
            return self::optionsWithoutManagementMetadata($this->options) ?? [];
        }

        return $values
            ->mapWithKeys(function (ProductOptionValue $value): array {
                $group = $value->group;
                $key = $group?->code ?: $group?->slug ?: (string) $group?->getKey();

                return [$key => [
                    'group' => (string) $group?->title,
                    'value' => $value->title,
                ]];
            })
            ->all();
    }

    public function optionSummary(): string
    {
        $options = $this->publicOptionsSnapshot();

        if ($options === []) {
            return '';
        }

        return collect($options)
            ->map(function (mixed $option, string|int $key): ?string {
                if (is_array($option) && filled($option['value'] ?? null)) {
                    return (string) (($option['group'] ?? null) ?: $key).': '.$option['value'];
                }

                return is_scalar($option) && filled((string) $option)
                    ? (string) $key.': '.$option
                    : null;
            })
            ->filter()
            ->implode('; ');
    }

    public function syncOptionsSnapshotFromValues(): void
    {
        $this->unsetRelation('optionValues');
        $this->load('optionValues.group');

        if ($this->optionValues->isEmpty()) {
            return;
        }

        $snapshot = $this->publicOptionsSnapshot();

        if ($snapshot !== []) {
            $this->forceFill(['options' => $snapshot])->saveQuietly();
        }
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'product_variant_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $variant): void {
            $variant->stock_status ??= StockStatus::InStock;
            $variant->is_active ??= true;
            $variant->is_default ??= false;

            app(ProductVariantAdminService::class)->prepareForSave($variant);
        });

        static::deleting(fn (self $variant) => app(ProductVariantAdminService::class)->assertCanDelete($variant));
    }

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'price' => 'decimal:2',
            'old_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'stock_status' => StockStatus::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
