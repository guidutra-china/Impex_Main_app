<?php

namespace App\Mail;

use App\Domain\Infrastructure\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Document $document,
        public string $recipientName,
        public string $customMessage = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->document->documentable?->reference
                ? "{$this->document->documentable->reference} — {$this->document->name}"
                : $this->document->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.document',
        );
    }

    public function attachments(): array
    {
        if (! $this->document->exists()) {
            return [];
        }

        return [
            Attachment::fromPath($this->document->getFullPath())
                ->as($this->document->name)
                ->withMime($this->document->mime_type ?? 'application/pdf'),
        ];
    }
}
