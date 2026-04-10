<?php

namespace App\Filament\Resources\Finance\Concerns;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Infrastructure\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

trait HasPaymentColumns
{
    public static function tableColumns(PaymentDirection $direction): array
    {
        $companyLabel = $direction === PaymentDirection::INBOUND
            ? __('forms.labels.client')
            : __('forms.labels.supplier');

        return [
            TextColumn::make('payment_date')
                ->label(__('forms.labels.date'))
                ->date('d/m/Y')
                ->sortable(),
            TextColumn::make('company.name')
                ->label($companyLabel)
                ->searchable()
                ->sortable(),
            TextColumn::make('amount')
                ->label(__('forms.labels.amount'))
                ->formatStateUsing(fn ($state) => Money::format($state))
                ->alignEnd()
                ->sortable(),
            TextColumn::make('currency_code')
                ->label(__('forms.labels.currency')),
            TextColumn::make('allocated_total')
                ->label(__('forms.labels.allocated'))
                ->getStateUsing(fn ($record) => $record->allocated_total)
                ->formatStateUsing(fn ($state) => Money::format($state))
                ->alignEnd()
                ->color('success'),
            TextColumn::make('unallocated_amount')
                ->label(__('forms.labels.unallocated'))
                ->getStateUsing(fn ($record) => $record->unallocated_amount)
                ->formatStateUsing(fn ($state) => Money::format($state))
                ->alignEnd()
                ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
            TextColumn::make('paymentMethod.name')
                ->label(__('forms.labels.method'))
                ->placeholder('—'),
            TextColumn::make('reference')
                ->label(__('forms.labels.reference'))
                ->placeholder('—')
                ->limit(20)
                ->tooltip(fn ($record) => $record->reference)
                ->searchable(),
            TextColumn::make('status')
                ->label(__('forms.labels.status'))
                ->badge()
                ->sortable(),
            TextColumn::make('creator.name')
                ->label(__('forms.labels.created_by'))
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('approvedByUser.name')
                ->label(__('forms.labels.approved_by'))
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function tableFilters(PaymentDirection $direction): array
    {
        return [
            SelectFilter::make('status')
                ->options(PaymentStatus::class),
            SelectFilter::make('company_id')
                ->label($direction === PaymentDirection::INBOUND
                    ? __('forms.labels.client')
                    : __('forms.labels.supplier'))
                ->relationship('company', 'name')
                ->searchable()
                ->preload(),
        ];
    }
}
