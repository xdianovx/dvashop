<?php

namespace App\Services\Storefront;

use App\Enums\StorefrontInquiryType;
use App\Events\StorefrontInquiryCreated;
use App\Models\StorefrontInquiry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class StorefrontInquiryService
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): StorefrontInquiry
    {
        $inquiry = DB::transaction(function () use ($attributes): StorefrontInquiry {
            $type = StorefrontInquiryType::from((string) $attributes['type']);
            $sourceCode = (string) $attributes['source_code'];
            if ($type === StorefrontInquiryType::ProductConsultation) {
                throw ValidationException::withMessages(['type' => 'Этот тип заявки больше не принимается.']);
            }

            if (! in_array($sourceCode, $type->allowedSourceCodes(), true)) {
                throw ValidationException::withMessages(['source_code' => 'Источник заявки не поддерживается.']);
            }

            return StorefrontInquiry::query()->create([
                ...Arr::only($attributes, ['name', 'phone', 'email', 'message', 'source_code']),
                'type' => $type,
                'email' => filled($attributes['email'] ?? null) ? mb_strtolower(trim((string) $attributes['email'])) : null,
                'message' => filled($attributes['message'] ?? null) ? trim((string) $attributes['message']) : null,
                'name' => trim((string) $attributes['name']),
                'phone' => trim((string) $attributes['phone']),
                'source_url' => $this->sourceUrl($sourceCode),
                ...$this->emptyProductSnapshot(),
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
