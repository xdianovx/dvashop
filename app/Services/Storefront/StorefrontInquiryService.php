<?php

namespace App\Services\Storefront;

use App\Enums\StorefrontInquiryType;
use App\Events\StorefrontInquiryCreated;
use App\Models\ProductVariant;
use App\Models\StorefrontInquiry;
use App\Services\StorefrontProductAvailability;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class StorefrontInquiryService
{
    public function __construct(private readonly StorefrontProductAvailability $availability) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): StorefrontInquiry
    {
        $inquiry = DB::transaction(function () use ($attributes): StorefrontInquiry {
            $type = StorefrontInquiryType::from((string) $attributes['type']);
            $sourceCode = (string) $attributes['source_code'];
            $context = $type === StorefrontInquiryType::ProductConsultation
                ? $this->productContext(
                    (int) $attributes['product_context'],
                    (int) $attributes['product_variant_id'],
                )
                : [
                    'snapshot' => $this->emptyProductSnapshot(),
                    'source_url' => $this->sourceUrl($sourceCode),
                ];

            return StorefrontInquiry::query()->create([
                ...Arr::only($attributes, ['name', 'phone', 'email', 'message', 'source_code']),
                'type' => $type,
                'email' => filled($attributes['email'] ?? null) ? mb_strtolower(trim((string) $attributes['email'])) : null,
                'message' => filled($attributes['message'] ?? null) ? trim((string) $attributes['message']) : null,
                'name' => trim((string) $attributes['name']),
                'phone' => trim((string) $attributes['phone']),
                'source_url' => $context['source_url'],
                ...$context['snapshot'],
            ]);
        });

        try {
            StorefrontInquiryCreated::dispatch($inquiry);
        } catch (Throwable $exception) {
            Log::error('Unable to queue storefront inquiry delivery notifications.', [
                'inquiry_id' => $inquiry->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }

        return $inquiry;
    }

    /**
     * @return array{
     *     snapshot: array<string, mixed>,
     *     source_url: string
     * }
     */
    private function productContext(int $productId, int $variantId): array
    {
        $variant = $this->availability->variants(ProductVariant::query())
            ->with(['product', 'optionValues.group'])
            ->where('product_id', $productId)
            ->whereKey($variantId)
            ->first();

        if (! $variant instanceof ProductVariant) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Выбранный вариант товара недоступен для консультации.',
            ]);
        }

        return [
            'snapshot' => [
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->getKey(),
                'product_title_snapshot' => $variant->product->title,
                'variant_sku_snapshot' => $variant->sku ?: $variant->product->sku,
                'options_snapshot' => $variant->publicOptionsSnapshot() ?: null,
            ],
            'source_url' => route('products.show', $variant->product->slug),
        ];
    }

    private function sourceUrl(string $sourceCode): string
    {
        return match ($sourceCode) {
            'faq' => route('faq'),
            'about' => route('about'),
            'partners' => route('partners'),
            'home' => route('home'),
            default => route('home'),
        };
    }

    /** @return array<string, null> */
    private function emptyProductSnapshot(): array
    {
        return [
            'product_id' => null,
            'product_variant_id' => null,
            'product_title_snapshot' => null,
            'variant_sku_snapshot' => null,
            'options_snapshot' => null,
        ];
    }
}
