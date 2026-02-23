<?php

namespace App\Filament\Resources\PurchaseOrders\RelationManagers;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Filament\RelationManagers\PaymentsRelationManager as BasePaymentsRelationManager;

class PaymentsRelationManager extends BasePaymentsRelationManager
{
    protected PaymentDirection $defaultDirection = PaymentDirection::OUTBOUND;
}
