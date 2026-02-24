<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Domain\Infrastructure\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payment Details')->columns(3)->schema([
                TextEntry::make('direction')
                    ->label('Direction')
                    ->badge(),
                TextEntry::make('company.name')
                    ->label('Company')
                    ->placeholder('—'),
                TextEntry::make('payment_date')
                    ->label('Payment Date')
                    ->date('d/m/Y'),
                TextEntry::make('amount')
                    ->label('Total Amount')
                    ->formatStateUsing(fn ($state) => Money::format($state)),
                TextEntry::make('currency_code')
                    ->label('Currency'),
                TextEntry::make('paymentMethod.name')
                    ->label('Payment Method')
                    ->placeholder('—'),
                TextEntry::make('bankAccount.bank_name')
                    ->label('Bank Account')
                    ->placeholder('—'),
                TextEntry::make('reference')
                    ->label('Reference')
                    ->placeholder('—'),
                TextEntry::make('status')
                    ->label('Status')
                    ->badge(),
                TextEntry::make('allocated_total')
                    ->label('Total Allocated')
                    ->getStateUsing(fn ($record) => $record->allocated_total)
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->color('success'),
                TextEntry::make('unallocated_amount')
                    ->label('Unallocated (Credit)')
                    ->getStateUsing(fn ($record) => $record->unallocated_amount)
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
                TextEntry::make('creator.name')
                    ->label('Created By')
                    ->placeholder('—'),
                TextEntry::make('approvedByUser.name')
                    ->label('Approved By')
                    ->placeholder('—'),
                TextEntry::make('approved_at')
                    ->label('Approved At')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextEntry::make('notes')
                    ->label('Notes')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]),

            Section::make('Allocations')->schema([
                RepeatableEntry::make('allocations')
                    ->label('')
                    ->schema([
                        TextEntry::make('scheduleItem.payable.reference')
                            ->label('Document'),
                        TextEntry::make('scheduleItem.label')
                            ->label('Schedule Item'),
                        TextEntry::make('type')
                            ->label('Type')
                            ->getStateUsing(fn ($record) => $record->isCreditApplication() ? 'Credit' : 'Payment')
                            ->badge()
                            ->color(fn ($record) => $record->isCreditApplication() ? 'success' : 'primary'),
                        TextEntry::make('allocated_amount')
                            ->label('Allocated')
                            ->formatStateUsing(fn ($state, $record) => $record->isCreditApplication()
                                ? 'Credit: ' . Money::format($record->allocated_amount_in_document_currency)
                                : Money::format($state)),
                        TextEntry::make('exchange_rate')
                            ->label('Exchange Rate')
                            ->placeholder('1:1'),
                        TextEntry::make('allocated_amount_in_document_currency')
                            ->label('In Document Currency')
                            ->formatStateUsing(fn ($state) => Money::format($state))
                            ->placeholder('—'),
                    ])
                    ->columns(6),
            ]),
        ]);
    }
}
