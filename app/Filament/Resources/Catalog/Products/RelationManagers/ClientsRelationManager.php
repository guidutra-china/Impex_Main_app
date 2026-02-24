<?php

namespace App\Filament\Resources\Catalog\Products\RelationManagers;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Infrastructure\Support\Money;
use BackedEnum;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ClientsRelationManager extends RelationManager
{
    protected static string $relationship = 'clients';

    protected static ?string $title = 'Clients';

    protected static ?string $recordTitleAttribute = 'name';

    protected static BackedEnum|string|null $icon = 'heroicon-o-user-group';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('external_code')
                    ->label('Client Code')
                    ->maxLength(100)
                    ->helperText("Client's internal code for this product."),
                TextInput::make('external_name')
                    ->label('Client Name for Product')
                    ->maxLength(255)
                    ->helperText("How the client calls this product."),
                Textarea::make('external_description')
                    ->label('Client Product Description')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('Product description as used by the client. Will appear on invoices.')
                    ->columnSpanFull(),
                TextInput::make('unit_price')
                    ->label('Selling Price')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->prefix('$')
                    ->inputMode('decimal'),
                TextInput::make('custom_price')
                    ->label('Custom Price (CI Override)')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->prefix('$')
                    ->inputMode('decimal')
                    ->helperText('If set, Commercial Invoice uses this instead of PI price.'),
                Select::make('currency_code')
                    ->label('Currency')
                    ->options(fn () => \App\Domain\Settings\Models\Currency::pluck('code', 'code'))
                    ->searchable(),
                Checkbox::make('is_preferred')
                    ->label('Primary Client'),
                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Client')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('pivot.external_code')
                    ->label('Client Code')
                    ->placeholder('—'),
                TextColumn::make('pivot.external_name')
                    ->label('Client Product Name')
                    ->placeholder('—'),
                TextColumn::make('pivot.unit_price')
                    ->label('Selling Price')
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state, 4) : '—')
                    ->prefix('$ ')
                    ->alignEnd(),
                TextColumn::make('pivot.custom_price')
                    ->label('CI Price')
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state, 4) : '—')
                    ->prefix('$ ')
                    ->alignEnd(),
                TextColumn::make('pivot.currency_code')
                    ->label('Currency'),
                IconColumn::make('pivot.is_preferred')
                    ->label('Primary')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Add Client')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'legal_name'])
                    ->recordSelectOptionsQuery(
                        fn ($query) => $query->whereHas('companyRoles', fn ($q) => $q->where('role', \App\Domain\CRM\Enums\CompanyRole::CLIENT))
                    )
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        TextInput::make('external_code')
                            ->label('Client Code')
                            ->maxLength(100),
                        TextInput::make('external_name')
                            ->label('Client Product Name')
                            ->maxLength(255),
                        Textarea::make('external_description')
                            ->label('Client Product Description')
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText('Will appear on invoices.'),
                        TextInput::make('unit_price')
                            ->label('Selling Price')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->prefix('$')
                            ->inputMode('decimal')
                            ->default(0),
                        TextInput::make('custom_price')
                            ->label('Custom Price (CI Override)')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->prefix('$')
                            ->inputMode('decimal')
                            ->helperText('If set, CI uses this price.'),
                        Select::make('currency_code')
                            ->label('Currency')
                            ->options(fn () => \App\Domain\Settings\Models\Currency::pluck('code', 'code'))
                            ->searchable(),
                        Checkbox::make('is_preferred')
                            ->label('Primary Client'),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['role'] = 'client';
                        $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);
                        $data['custom_price'] = filled($data['custom_price'] ?? null)
                            ? Money::toMinor($data['custom_price'])
                            : null;
                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mountUsing(function ($form, $record) {
                        $data = $record->pivot->toArray();
                        $data['unit_price'] = Money::toMajor($data['unit_price'] ?? 0);
                        $data['custom_price'] = filled($data['custom_price'] ?? null)
                            ? Money::toMajor($data['custom_price'])
                            : null;
                        $form->fill($data);
                    })
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);
                        $data['custom_price'] = filled($data['custom_price'] ?? null)
                            ? Money::toMinor($data['custom_price'])
                            : null;
                        return $data;
                    }),
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    $this->getBulkPriceUpdateAction('selling_price', 'unit_price', 'Adjust Selling Price'),
                    $this->getBulkPriceUpdateAction('custom_price', 'custom_price', 'Adjust CI Price'),
                    DetachBulkAction::make(),
                ]),
            ]);
    }

    private function getBulkPriceUpdateAction(string $name, string $column, string $label): BulkAction
    {
        return BulkAction::make($name)
            ->label($label)
            ->icon('heroicon-o-calculator')
            ->form([
                TextInput::make('formula')
                    ->label('Formula')
                    ->required()
                    ->placeholder('e.g. *1.10 or +5 or -2.50')
                    ->helperText('Apply to current value: *1.10 = +10%, /1.05 = -5%, +5 = add $5, -2.50 = subtract $2.50'),
            ])
            ->action(function (Collection $records, array $data) use ($column): void {
                $formula = trim($data['formula']);

                if (! preg_match('/^[\*\/\+\-]\s*[\d\.]+$/', $formula)) {
                    Notification::make()
                        ->danger()
                        ->title('Invalid formula')
                        ->body('Use format: *1.10, /1.05, +5, or -2.50')
                        ->send();
                    return;
                }

                $operator = $formula[0];
                $operand = (float) trim(substr($formula, 1));
                $updated = 0;

                foreach ($records as $record) {
                    $currentMinor = $record->pivot->{$column} ?? 0;
                    $currentMajor = Money::toMajor($currentMinor);

                    $newMajor = match ($operator) {
                        '*' => $currentMajor * $operand,
                        '/' => $operand > 0 ? $currentMajor / $operand : $currentMajor,
                        '+' => $currentMajor + $operand,
                        '-' => $currentMajor - $operand,
                    };

                    $newMinor = Money::toMinor(max(0, $newMajor));

                    CompanyProduct::where('company_id', $record->pivot->company_id)
                        ->where('product_id', $record->pivot->product_id)
                        ->update([$column => $newMinor]);

                    $updated++;
                }

                Notification::make()
                    ->success()
                    ->title("Updated {$updated} records")
                    ->body("Applied formula: {$formula}")
                    ->send();
            })
            ->deselectRecordsAfterCompletion()
            ->requiresConfirmation()
            ->modalDescription('This will apply the formula to all selected records. This action cannot be undone.');
    }
}
