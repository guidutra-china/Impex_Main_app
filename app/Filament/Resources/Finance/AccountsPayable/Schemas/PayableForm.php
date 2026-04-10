<?php

namespace App\Filament\Resources\Finance\AccountsPayable\Schemas;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Filament\Resources\Finance\Concerns\HasPaymentFormSections;
use Filament\Schemas\Schema;

class PayableForm
{
    use HasPaymentFormSections;

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(static::formSchema(PaymentDirection::OUTBOUND));
    }
}
