<?php

namespace App\Mail;

use App\Models\StorefrontInquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StorefrontInquiryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public StorefrontInquiry $inquiry) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Новая заявка: '.$this->inquiry->type->label());
    }

    public function content(): Content
    {
        return new Content(view: 'emails.inquiries.created');
    }
}
