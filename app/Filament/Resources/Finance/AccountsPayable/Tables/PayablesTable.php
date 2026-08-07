<?php

namespace App\Filament\Resources\Finance\AccountsPayable\Tables;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Filament\Resources\Finance\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\Finance\Concerns\HasPaymentApprovalActions;
use App\Filament\Resources\Finance\Concerns\HasPaymentColumns;
use Filament\Tables\Table;

class PayablesTable
{
    use HasPaymentApprovalActions;
    use HasPaymentColumns;

    public static function configure(Table $table): Table
    {
        return $table
            ->columns(static::tableColumns(PaymentDirection::OUTBOUND))
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('payment_date', 'desc')
            ->filters(static::tableFilters(PaymentDirection::OUTBOUND))
            ->deferFilters(false)
            ->recordActions([
                static::allocationsRecordAction(AccountsPayableResource::class),
                ...static::approvalRecordActions(),
                ...static::viewAndEditActions(),
            ]);
    }
}
