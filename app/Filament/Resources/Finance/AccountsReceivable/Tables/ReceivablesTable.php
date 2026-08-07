<?php

namespace App\Filament\Resources\Finance\AccountsReceivable\Tables;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Filament\Resources\Finance\AccountsReceivable\AccountsReceivableResource;
use App\Filament\Resources\Finance\Concerns\HasPaymentApprovalActions;
use App\Filament\Resources\Finance\Concerns\HasPaymentColumns;
use Filament\Tables\Table;

class ReceivablesTable
{
    use HasPaymentApprovalActions;
    use HasPaymentColumns;

    public static function configure(Table $table): Table
    {
        return $table
            ->columns(static::tableColumns(PaymentDirection::INBOUND))
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('payment_date', 'desc')
            ->filters(static::tableFilters(PaymentDirection::INBOUND))
            ->deferFilters(false)
            ->recordActions([
                static::allocationsRecordAction(AccountsReceivableResource::class),
                ...static::approvalRecordActions(),
                ...static::viewAndEditActions(),
            ]);
    }
}
