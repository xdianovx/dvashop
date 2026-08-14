<?php

namespace App\Listeners;

use App\Events\StorefrontInquiryCreated;
use App\Models\StorefrontInquiry;
use App\Services\Integrations\BitrixWebhookClient;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendInquiryToBitrix implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(private readonly BitrixWebhookClient $client) {}

    public function handle(StorefrontInquiryCreated $event): void
    {
        if (! config('shop.inquiries.bitrix_enabled')) {
            return;
        }

        $inquiry = StorefrontInquiry::query()->findOrFail($event->inquiry->getKey());

        if ($inquiry->bitrix_sent_at !== null) {
            return;
        }

        try {
            $entityId = $this->client->addLead(
                $this->fields($inquiry),
                (string) config('shop.bitrix.inquiry_method', 'crm.lead.add'),
            );
            $inquiry->forceFill([
                'bitrix_sent_at' => now(),
                'bitrix_failed_at' => null,
                'bitrix_entity_id' => $entityId,
            ])->save();
        } catch (Throwable $exception) {
            $inquiry->forceFill(['bitrix_failed_at' => now()])->save();

            throw $exception;
        }
    }

    public function failed(StorefrontInquiryCreated $event, Throwable $exception): void
    {
        $event->inquiry->forceFill(['bitrix_failed_at' => now()])->save();

        Log::error('Queued inquiry Bitrix delivery failed.', [
            'inquiry_id' => $event->inquiry->getKey(),
            'exception' => $exception->getMessage(),
        ]);
    }

    /**
     * @return array{TITLE:string,NAME:string,PHONE:array<int, array{VALUE:string,VALUE_TYPE:string}>,EMAIL:array<int, array{VALUE:string,VALUE_TYPE:string}>,COMMENTS:string}
     */
    private function fields(StorefrontInquiry $inquiry): array
    {
        $product = collect([
            $inquiry->product_title_snapshot,
            $inquiry->variant_sku_snapshot ? 'SKU: '.$inquiry->variant_sku_snapshot : null,
            $inquiry->optionSummary() ?: null,
        ])->filter()->implode("\n");

        return [
            'TITLE' => 'Заявка с сайта: '.$inquiry->type->label(),
            'NAME' => $inquiry->name,
            'PHONE' => [['VALUE' => $inquiry->phone, 'VALUE_TYPE' => 'WORK']],
            'EMAIL' => filled($inquiry->email)
                ? [['VALUE' => $inquiry->email, 'VALUE_TYPE' => 'WORK']]
                : [],
            'COMMENTS' => collect([
                $inquiry->message,
                $product,
                'Код источника: '.$inquiry->source_code,
                'Источник: '.$inquiry->source_url,
            ])->filter()->implode("\n\n"),
        ];
    }
}
