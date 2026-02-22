<?php

namespace App\Domain\ProformaInvoices\Enums;

use Filament\Support\Contracts\HasLabel;

enum ConfirmationMethod: string implements HasLabel
{
    case EMAIL = 'email';
    case MESSAGE = 'message';
    case PHONE = 'phone';
    case IN_PERSON = 'in_person';
    case SIGNED_DOCUMENT = 'signed_document';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::EMAIL => 'Email',
            self::MESSAGE => 'Message (WhatsApp, WeChat, etc.)',
            self::PHONE => 'Phone Call',
            self::IN_PERSON => 'In Person',
            self::SIGNED_DOCUMENT => 'Signed Document',
            self::OTHER => 'Other',
        };
    }
}
