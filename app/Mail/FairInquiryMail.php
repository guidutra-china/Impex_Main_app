<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FairInquiryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $companyName,
        public string $tradeFairName,
        public array $productNames,
        public string $customMessage,
        public string $subject,
        public string $senderName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.fair-inquiry',
            with: [
                'recipientName' => $this->recipientName,
                'companyName' => $this->companyName,
                'tradeFairName' => $this->tradeFairName,
                'productNames' => $this->productNames,
                'customMessage' => $this->customMessage,
                'senderName' => $this->senderName,
            ],
        );
    }
}
