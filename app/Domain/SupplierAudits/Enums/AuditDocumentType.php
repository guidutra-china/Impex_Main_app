<?php

namespace App\Domain\SupplierAudits\Enums;

use Filament\Support\Contracts\HasLabel;

enum AuditDocumentType: string implements HasLabel
{
    case PHOTO = 'photo';
    case CERTIFICATE = 'certificate';
    case REPORT = 'report';
    case CONTRACT = 'contract';
    case OTHER = 'other';

    public function getLabel(): ?string
    {
        return __('enums.audit_document_type.' . $this->value);
    }


    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::PHOTO => 'Photo',
            self::CERTIFICATE => 'Certificate',
            self::REPORT => 'Report',
            self::CONTRACT => 'Contract',
            self::OTHER => 'Other',
        };
    }
}
