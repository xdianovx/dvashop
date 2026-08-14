<?php

namespace App\Listeners;

use App\Events\StorefrontInquiryCreated;
use App\Mail\StorefrontInquiryMail;
use App\Models\ShopSetting;
use App\Models\StorefrontInquiry;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendInquiryEmail implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function handle(StorefrontInquiryCreated $event): void
    {
        if (! config('shop.inquiries.email_enabled')) {
            return;
        }

        $inquiry = StorefrontInquiry::query()->findOrFail($event->inquiry->getKey());

        if ($inquiry->email_sent_at !== null) {
            return;
        }

        $recipient = trim((string) (ShopSetting::query()
            ->where('singleton_key', ShopSetting::SINGLETON_KEY)
            ->value('inquiry_notification_email') ?: config('shop.inquiries.manager_email')));

        if ($recipient === '') {
            $inquiry->forceFill(['email_failed_at' => now()])->save();

            Log::warning('Inquiry manager email is not configured; notification was skipped.', [
                'inquiry_id' => $inquiry->getKey(),
            ]);

            return;
        }

        try {
            Mail::to($recipient)->send(new StorefrontInquiryMail($inquiry));
            $inquiry->forceFill([
                'email_sent_at' => now(),
                'email_failed_at' => null,
            ])->save();
        } catch (Throwable $exception) {
            $inquiry->forceFill(['email_failed_at' => now()])->save();

            throw $exception;
        }
    }

    public function failed(StorefrontInquiryCreated $event, Throwable $exception): void
    {
        $event->inquiry->forceFill(['email_failed_at' => now()])->save();

        Log::error('Queued inquiry email notification failed.', [
            'inquiry_id' => $event->inquiry->getKey(),
            'exception' => $exception->getMessage(),
        ]);
    }
}
