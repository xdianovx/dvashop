<?php

namespace App\Models;

use App\Enums\StorefrontInquiryType;
use Database\Factories\StorefrontInquiryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'type',
    'name',
    'phone',
    'email',
    'message',
    'product_id',
    'product_variant_id',
    'product_title_snapshot',
    'variant_sku_snapshot',
    'options_snapshot',
    'source_url',
    'source_code',
    'email_sent_at',
    'email_failed_at',
    'bitrix_sent_at',
    'bitrix_failed_at',
    'bitrix_entity_id',
])]
class StorefrontInquiry extends Model
{
    /** @use HasFactory<StorefrontInquiryFactory> */
    use HasFactory;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function optionSummary(): string
    {
        if (! is_array($this->options_snapshot)) {
            return '';
        }

        return collect($this->options_snapshot)
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

    protected function emailDeliveryStatus(): Attribute
    {
        return Attribute::get(fn (): string => match (true) {
            $this->email_sent_at !== null => 'Отправлено',
            $this->email_failed_at !== null => 'Ошибка',
            ! config('shop.inquiries.email_enabled') => 'Отключено',
            default => 'Ожидает',
        });
    }

    protected function bitrixDeliveryStatus(): Attribute
    {
        return Attribute::get(fn (): string => match (true) {
            $this->bitrix_sent_at !== null => 'Отправлено',
            $this->bitrix_failed_at !== null => 'Ошибка',
            ! config('shop.inquiries.bitrix_enabled') => 'Отключено',
            default => 'Ожидает',
        });
    }

    protected function casts(): array
    {
        return [
            'type' => StorefrontInquiryType::class,
            'options_snapshot' => 'array',
            'email_sent_at' => 'datetime',
            'email_failed_at' => 'datetime',
            'bitrix_sent_at' => 'datetime',
            'bitrix_failed_at' => 'datetime',
        ];
    }
}
