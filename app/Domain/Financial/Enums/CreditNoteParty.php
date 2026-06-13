<?php

namespace App\Domain\Financial\Enums;

use Filament\Support\Contracts\HasLabel;

enum CreditNoteParty: string implements HasLabel
{
    case CLIENT = 'client';
    case SUPPLIER = 'supplier';

    public function getLabel(): ?string
    {
        return __('enums.credit_note_party.'.$this->value);
    }
}
