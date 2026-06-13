<?php

namespace App\Filament\RelationManagers;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Resources\Finance\CreditNotes\CreditNoteResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CreditNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'creditNotes';

    protected static ?string $title = 'Credit Notes';

    protected static BackedEnum|string|null $icon = 'heroicon-o-document-minus';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('forms.labels.reference'))
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => CreditNoteResource::getUrl('view', ['record' => $record]))
                    ->color('primary'),
                TextColumn::make('party_type')
                    ->label(__('forms.labels.party'))
                    ->badge(),
                TextColumn::make('total_amount')
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd(),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency')),
                TextColumn::make('consumed_amount')
                    ->label(__('forms.labels.applied_amount'))
                    ->state(fn ($record) => $record->consumed_amount)
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->color(fn ($record) => $record->consumed_amount >= (int) $record->total_amount && (int) $record->total_amount > 0 ? 'success' : 'gray')
                    ->alignEnd(),
                TextColumn::make('remaining_amount')
                    ->label(__('forms.labels.remaining_amount'))
                    ->state(fn ($record) => $record->remaining_amount)
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->color(fn ($state) => ((int) $state) > 0 ? 'warning' : 'success')
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('issued_at')
                    ->label(__('forms.labels.issued'))
                    ->date('d/m/Y')
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Action::make('createCreditNote')
                    ->label(__('forms.labels.create_credit_note'))
                    ->icon('heroicon-o-plus-circle')
                    ->color('primary')
                    ->url(function () {
                        $owner = $this->getOwnerRecord();

                        if ($owner instanceof PurchaseOrder) {
                            return CreditNoteResource::getUrl('create', [
                                'party_type' => 'supplier',
                                'company_id' => $owner->supplier_company_id,
                                'purchase_order_id' => $owner->id,
                            ]);
                        }

                        return CreditNoteResource::getUrl('create', [
                            'party_type' => 'client',
                            'company_id' => $owner->company_id,
                            'proforma_invoice_id' => $owner->id,
                        ]);
                    })
                    ->openUrlInNewTab(),
            ]);
    }
}
