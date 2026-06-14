<?php

namespace App\Domain\Financial\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Counterparty type shared by financial documents (Debit/Credit Notes):
 * a client (money flows in, receivable side) or a supplier (money flows
 * out, payable side).
 */
enum PartyType: string implements HasLabel
{
    case CLIENT = 'client';
    case SUPPLIER = 'supplier';

    public function getLabel(): ?string
    {
        return __('enums.party_type.'.$this->value);
    }
}
