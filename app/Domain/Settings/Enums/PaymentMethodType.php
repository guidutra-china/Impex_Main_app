<?php

namespace App\Domain\Settings\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethodType: string implements HasLabel
{
    case BANK_TRANSFER = 'bank_transfer';
    case WIRE_TRANSFER = 'wire_transfer';
    case PAYPAL = 'paypal';
    case CREDIT_CARD = 'credit_card';
    case DEBIT_CARD = 'debit_card';
    case CHECK = 'check';
    case CASH = 'cash';
    case WISE = 'wise';
    case CRYPTOCURRENCY = 'cryptocurrency';
    case OTHER = 'other';

    public function getLabel(): ?string
    {
        return __('enums.payment_method_type.' . $this->value);
    }


    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::BANK_TRANSFER => 'Bank Transfer',
            self::WIRE_TRANSFER => 'Wire Transfer',
            self::PAYPAL => 'PayPal',
            self::CREDIT_CARD => 'Credit Card',
            self::DEBIT_CARD => 'Debit Card',
            self::CHECK => 'Check',
            self::CASH => 'Cash',
            self::WISE => 'Wise (TransferWise)',
            self::CRYPTOCURRENCY => 'Cryptocurrency',
            self::OTHER => 'Other',
        };
    }
}
