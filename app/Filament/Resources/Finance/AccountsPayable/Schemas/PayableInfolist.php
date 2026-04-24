<?php

namespace App\Filament\Resources\Finance\AccountsPayable\Schemas;

use App\Domain\Infrastructure\Support\Money;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PayableInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('forms.sections.payment_details'))->columns(3)->columnSpanFull()->schema([
                TextEntry::make('company.name')
                    ->label(__('forms.labels.supplier'))
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
                    ->formatStateUsing(fn ($state, $record) => $record->currency_code.' '.Money::format($state))
                    ->color('success'),
                TextEntry::make('unallocated_amount')
                    ->label(__('forms.labels.unallocated_credit'))
                    ->getStateUsing(fn ($record) => $record->unallocated_amount)
                    ->formatStateUsing(fn ($state, $record) => $record->currency_code.' '.Money::format($state))
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
                ImageEntry::make('attachment_path')
                    ->label(__('forms.labels.receipt_attachment'))
                    ->disk('public')
                    ->visibility('public')
                    ->placeholder('—')
                    ->columnSpanFull()
                    ->visible(fn ($record) => filled($record->attachment_path)),
            ]),

            Section::make(__('forms.sections.allocations'))->columnSpanFull()->schema([
                RepeatableEntry::make('allocations')
                    ->label('')
                    ->schema([
                        TextEntry::make('scheduleItem.payable.reference')
                            ->label(__('forms.labels.document')),
                        TextEntry::make('scheduleItem.payable.client_reference')
                            ->label(__('forms.labels.client_reference'))
                            ->placeholder('—'),
                        TextEntry::make('scheduleItem.label')
                            ->label(__('forms.labels.schedule_item')),
                        TextEntry::make('type')
                            ->label(__('forms.labels.type'))
                            ->getStateUsing(fn ($record) => $record->isCreditApplication() ? 'Credit' : 'Payment')
                            ->badge()
                            ->color(fn ($record) => $record->isCreditApplication() ? 'success' : 'primary'),
                        TextEntry::make('allocated_amount')
                            ->label(__('forms.labels.allocated'))
                            ->formatStateUsing(function ($state, $record) {
                                if ($record->isCreditApplication()) {
                                    $itemCurrency = $record->scheduleItem?->currency_code ?? '';

                                    return 'Credit: '.trim($itemCurrency.' '.Money::format($record->allocated_amount_in_document_currency));
                                }

                                $pmtCurrency = $record->payment?->currency_code ?? '';

                                return trim($pmtCurrency.' '.Money::format($state));
                            }),
                        TextEntry::make('exchange_rate')
                            ->label(__('forms.labels.exchange_rate'))
                            ->formatStateUsing(function ($state, $record) {
                                if ($record->isCreditApplication()) {
                                    return '—';
                                }

                                $pmt = $record->payment?->currency_code ?? '';
                                $doc = $record->scheduleItem?->currency_code ?? '';

                                if ($pmt !== '' && $doc !== '' && $pmt === $doc) {
                                    return '1.00000000 ('.$pmt.' = '.$doc.')';
                                }

                                if ($state === null) {
                                    return '—';
                                }

                                return number_format((float) $state, 8, '.', '').' ('.$pmt.' → '.$doc.')';
                            })
                            ->placeholder('—'),
                        TextEntry::make('allocated_amount_in_document_currency')
                            ->label(__('forms.labels.in_document_currency'))
                            ->formatStateUsing(function ($state, $record) {
                                $docCurrency = $record->scheduleItem?->currency_code ?? '';

                                return trim($docCurrency.' '.Money::format($state));
                            })
                            ->placeholder('—'),
                    ])
                    ->columns(7),
            ]),
        ]);
    }
}
