<?php

namespace App\Events;

use App\Models\StorefrontInquiry;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StorefrontInquiryCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public StorefrontInquiry $inquiry) {}
}
