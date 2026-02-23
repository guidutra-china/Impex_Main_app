<?php

namespace App\Filament\RelationManagers;

use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class AdditionalCostsRelationManager extends RelationManager
{
    protected static string $relationship = 'additionalCosts';

    protected static ?string $title = 'Additional Costs';

    protected static BackedEnum|string|null $icon = 'heroicon-o-receipt-percent';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cost_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Description')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->description),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd(),
                TextColumn::make('currency_code')
                    ->label('Currency'),
                TextColumn::make('amount_in_document_currency')
                    ->label('Doc. Amount')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd()
                    ->summarize(Sum::make()
                        ->label('Total')
                        ->formatStateUsing(fn ($state) => Money::format((int) $state))),
                TextColumn::make('billable_to')
                    ->label('Billable To')
                    ->badge(),
                TextColumn::make('supplierCompany.name')
                    ->label('Supplier')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cost_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('creator.name')
                    ->label('Created By')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('cost_date', 'desc')
            ->filters([
                SelectFilter::make('cost_type')
                    ->options(AdditionalCostType::class)
                    ->label('Type'),
                SelectFilter::make('billable_to')
                    ->options(BillableTo::class)
                    ->label('Billable To'),
                SelectFilter::make('status')
                    ->options(AdditionalCostStatus::class)
                    ->label('Status'),
            ])
            ->headerActions([
                $this->addCostAction(),
            ])
            ->recordActions([
                $this->editCostAction(),
                $this->markAsPaidAction(),
                $this->deleteCostAction(),
            ]);
    }

    protected function addCostAction(): Action
    {
        return Action::make('addCost')
            ->label('Add Cost')
            ->icon('heroicon-o-plus-circle')
            ->color('primary')
            ->visible(fn () => auth()->user()?->can('create-payments'))
            ->form($this->costFormSchema())
            ->action(function (array $data) {
                $this->saveCost($data);

                Notification::make()
                    ->title('Additional cost added')
                    ->success()
                    ->send();
            });
    }

    protected function editCostAction(): Action
    {
        return Action::make('edit')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn ($record) => $record->status === AdditionalCostStatus::PENDING && auth()->user()?->can('create-payments'))
            ->fillForm(fn ($record) => [
                'cost_type' => $record->cost_type->value,
                'description' => $record->description,
                'amount' => Money::toMajor($record->amount),
                'currency_code' => $record->currency_code,
                'exchange_rate' => $record->exchange_rate,
                'billable_to' => $record->billable_to->value,
                'supplier_company_id' => $record->supplier_company_id,
                'cost_date' => $record->cost_date,
                'notes' => $record->notes,
                'attachment_path' => $record->attachment_path,
            ])
            ->form($this->costFormSchema())
            ->action(function ($record, array $data) {
                $this->saveCost($data, $record);

                Notification::make()
                    ->title('Cost updated')
                    ->success()
                    ->send();
            });
    }

    protected function markAsPaidAction(): Action
    {
        return Action::make('markAsPaid')
            ->label('Mark Paid')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn ($record) => in_array($record->status, [AdditionalCostStatus::PENDING, AdditionalCostStatus::INVOICED]) && auth()->user()?->can('approve-payments'))
            ->action(function ($record) {
                $record->update(['status' => AdditionalCostStatus::PAID]);

                Notification::make()->title('Cost marked as paid')->success()->send();
            });
    }

    protected function deleteCostAction(): Action
    {
        return Action::make('delete')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn ($record) => $record->status === AdditionalCostStatus::PENDING && auth()->user()?->can('create-payments'))
            ->action(function ($record) {
                $record->delete();

                Notification::make()->title('Cost deleted')->danger()->send();
            });
    }

    protected function costFormSchema(): array
    {
        return [
            Section::make('Cost Details')->columns(2)->schema([
                Select::make('cost_type')
                    ->label('Cost Type')
                    ->options(AdditionalCostType::class)
                    ->required()
                    ->searchable(),
                TextInput::make('description')
                    ->label('Description')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->step('0.0001')
                    ->minValue(0.0001)
                    ->required(),
                Select::make('currency_code')
                    ->label('Currency')
                    ->options(fn () => Currency::pluck('code', 'code'))
                    ->default(fn () => $this->getOwnerRecord()->currency_code)
                    ->required()
                    ->live(),
                TextInput::make('exchange_rate')
                    ->label('Exchange Rate')
                    ->numeric()
                    ->step('0.00000001')
                    ->helperText('Rate to convert to document currency. Leave empty if same currency.')
                    ->visible(fn ($get) => $get('currency_code') && $get('currency_code') !== $this->getOwnerRecord()->currency_code),
                Select::make('billable_to')
                    ->label('Billable To')
                    ->options(BillableTo::class)
                    ->default(BillableTo::CLIENT->value)
                    ->required(),
                Select::make('supplier_company_id')
                    ->label('Supplier (if applicable)')
                    ->relationship('supplierCompany', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('—'),
                DatePicker::make('cost_date')
                    ->label('Date')
                    ->default(now()),
                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->columnSpanFull(),
                FileUpload::make('attachment_path')
                    ->label('Attachment (Invoice/Receipt)')
                    ->directory('additional-cost-attachments')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize(5120)
                    ->columnSpanFull(),
            ]),
        ];
    }

    protected function saveCost(array $data, $record = null): void
    {
        $owner = $this->getOwnerRecord();
        $amountMinor = Money::toMinor((float) $data['amount']);
        $documentCurrencyCode = $owner->currency_code;
        $costCurrencyCode = $data['currency_code'];

        $exchangeRate = null;
        $amountInDocCurrency = $amountMinor;

        if ($costCurrencyCode !== $documentCurrencyCode) {
            $exchangeRate = $data['exchange_rate'] ?? null;

            if ($exchangeRate) {
                $amountInDocCurrency = (int) round($amountMinor * (float) $exchangeRate);
            } else {
                $costCurrency = Currency::where('code', $costCurrencyCode)->first();
                $documentCurrency = Currency::where('code', $documentCurrencyCode)->first();

                if ($costCurrency && $documentCurrency) {
                    $converted = ExchangeRate::convert(
                        $costCurrency->id,
                        $documentCurrency->id,
                        Money::toMajor($amountMinor)
                    );

                    if ($converted !== null) {
                        $amountInDocCurrency = Money::toMinor($converted);
                        $exchangeRate = $amountInDocCurrency / max($amountMinor, 1);
                    }
                }
            }
        }

        $payload = [
            'cost_type' => $data['cost_type'],
            'description' => $data['description'],
            'amount' => $amountMinor,
            'currency_code' => $costCurrencyCode,
            'exchange_rate' => $exchangeRate,
            'amount_in_document_currency' => $amountInDocCurrency,
            'billable_to' => $data['billable_to'],
            'supplier_company_id' => $data['supplier_company_id'] ?? null,
            'cost_date' => $data['cost_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'attachment_path' => $data['attachment_path'] ?? null,
        ];

        if ($record) {
            $record->update($payload);
        } else {
            $payload['status'] = AdditionalCostStatus::PENDING->value;
            $owner->additionalCosts()->create($payload);
        }
    }
}
