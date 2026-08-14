<?php

namespace Database\Factories;

use App\Enums\StorefrontInquiryType;
use App\Models\StorefrontInquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StorefrontInquiry> */
class StorefrontInquiryFactory extends Factory
{
    protected $model = StorefrontInquiry::class;

    public function definition(): array
    {
        return [
            'type' => StorefrontInquiryType::GeneralConsultation,
            'name' => fake()->name(),
            'phone' => '+79990000000',
            'email' => fake()->safeEmail(),
            'message' => fake()->optional()->sentence(),
            'product_id' => null,
            'product_variant_id' => null,
            'product_title_snapshot' => null,
            'variant_sku_snapshot' => null,
            'options_snapshot' => null,
            'source_url' => 'https://example.test/faq',
            'source_code' => 'faq',
            'email_sent_at' => null,
            'email_failed_at' => null,
            'bitrix_sent_at' => null,
            'bitrix_failed_at' => null,
            'bitrix_entity_id' => null,
        ];
    }
}
