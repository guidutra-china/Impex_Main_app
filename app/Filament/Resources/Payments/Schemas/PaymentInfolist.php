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
            Section::make(__('forms.sections.payment_details'))->columns(3)->columnSpanFull()->schema([
                TextEntry::make('direction')
                    ->label(__('forms.labels.direction'))
                    ->badge(),
                TextEntry::make('company.name')
                    ->label(__('forms.labels.company'))
                    ->placeholder('—'),
                TextEntry::make('payment_date')
                    ->label(__('forms.labels.payment_date'))
                    ->date('d/m/Y'),
                TextEntry::make('amount')
                    ->label(__('forms.labels.total_amount'))
                    ->formatStateUsing(fn ($state) => Money::format($state)),
                TextEntry::make('currency_code')
                    ->label(__('forms.labels.currency')),
                TextEntry::make('paymentMethod.name')
                    ->label(__('forms.labels.payment_method'))
                    ->placeholder('—'),
                TextEntry::make('bankAccount.bank_name')
                    ->label(__('forms.labels.bank_account'))
                    ->placeholder('—'),
                TextEntry::make('reference')
                    ->label(__('forms.labels.reference'))
                    ->placeholder('—'),
                TextEntry::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge(),
                TextEntry::make('allocated_total')
                    ->label(__('forms.labels.total_allocated'))
                    ->getStateUsing(fn ($record) => $record->allocated_total)
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->color('success'),
                TextEntry::make('unallocated_amount')
                    ->label(__('forms.labels.unallocated_credit'))
                    ->getStateUsing(fn ($record) => $record->unallocated_amount)
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
                TextEntry::make('creator.name')
                    ->label(__('forms.labels.created_by'))
                    ->placeholder('—'),
                TextEntry::make('approvedByUser.name')
                    ->label(__('forms.labels.approved_by'))
                    ->placeholder('—'),
                TextEntry::make('approved_at')
                    ->label(__('forms.labels.approved_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
                TextEntry::make('notes')
                    ->label(__('forms.labels.notes'))
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]),

            Section::make(__('forms.sections.allocations'))->columnSpanFull()->schema([
                RepeatableEntry::make('allocations')
                    ->label('')
                    ->schema([
                        TextEntry::make('scheduleItem.payable.reference')
                            ->label(__('forms.labels.document')),
                        TextEntry::make('scheduleItem.label')
                            ->label(__('forms.labels.schedule_item')),
                        TextEntry::make('type')
                            ->label(__('forms.labels.type'))
                            ->getStateUsing(fn ($record) => $record->isCreditApplication() ? 'Credit' : 'Payment')
                            ->badge()
                            ->color(fn ($record) => $record->isCreditApplication() ? 'success' : 'primary'),
                        TextEntry::make('allocated_amount')
                            ->label(__('forms.labels.allocated'))
                            ->formatStateUsing(fn ($state, $record) => $record->isCreditApplication()
                                ? 'Credit: ' . Money::format($record->allocated_amount_in_document_currency)
                                : Money::format($state)),
                        TextEntry::make('exchange_rate')
                            ->label(__('forms.labels.exchange_rate'))
                            ->placeholder('1:1'),
                        TextEntry::make('allocated_amount_in_document_currency')
                            ->label(__('forms.labels.in_document_currency'))
                            ->formatStateUsing(fn ($state) => Money::format($state))
                            ->placeholder('—'),
                    ])
                    ->columns(6),
            ]),
        ]);
    }
}
