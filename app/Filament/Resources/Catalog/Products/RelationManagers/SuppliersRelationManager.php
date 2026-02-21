<?php

namespace App\Filament\Resources\Catalog\Products\RelationManagers;

use BackedEnum;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SuppliersRelationManager extends RelationManager
{
    protected static string $relationship = 'suppliers';

    protected static ?string $title = 'Suppliers';

    protected static ?string $recordTitleAttribute = 'name';

    protected static BackedEnum|string|null $icon = 'heroicon-o-truck';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('external_code')
                    ->label('Supplier Code')
                    ->maxLength(100)
                    ->helperText("Supplier's internal code for this product."),
                TextInput::make('external_name')
                    ->label('Supplier Name for Product')
                    ->maxLength(255)
                    ->helperText("How the supplier calls this product."),
                Textarea::make('external_description')
                    ->label('Supplier Product Description')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('Product description as used by the supplier. Will appear on invoices.')
                    ->columnSpanFull(),
                TextInput::make('unit_price')
                    ->label('Unit Price (minor units)')
                    ->numeric()
                    ->minValue(0),
                Select::make('currency_code')
                    ->label('Currency')
                    ->options(fn () => \App\Domain\Settings\Models\Currency::pluck('code', 'code'))
                    ->searchable(),
                TextInput::make('lead_time_days')
                    ->label('Lead Time (days)')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('moq')
                    ->label('MOQ')
                    ->numeric()
                    ->minValue(1),
                Checkbox::make('is_preferred')
                    ->label('Preferred Supplier'),
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
                    ->label('Supplier')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('pivot.external_code')
                    ->label('Supplier Code')
                    ->placeholder('—'),
                TextColumn::make('pivot.unit_price')
                    ->label('Unit Price')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('pivot.currency_code')
                    ->label('Currency'),
                TextColumn::make('pivot.moq')
                    ->label('MOQ')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('pivot.lead_time_days')
                    ->label('Lead Time')
                    ->suffix(' days'),
                IconColumn::make('pivot.is_preferred')
                    ->label('Preferred')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Add Supplier')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'legal_name'])
                    ->recordSelectOptionsQuery(
                        fn ($query) => $query->whereHas('companyRoles', fn ($q) => $q->where('role', \App\Domain\CRM\Enums\CompanyRole::SUPPLIER))
                    )
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        TextInput::make('external_code')
                            ->label('Supplier Code')
                            ->maxLength(100),
                        TextInput::make('external_name')
                            ->label('Supplier Product Name')
                            ->maxLength(255),
                        Textarea::make('external_description')
                            ->label('Supplier Product Description')
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText('Will appear on invoices.'),
                        TextInput::make('unit_price')
                            ->label('Unit Price (minor units)')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        Select::make('currency_code')
                            ->label('Currency')
                            ->options(fn () => \App\Domain\Settings\Models\Currency::pluck('code', 'code'))
                            ->searchable(),
                        TextInput::make('moq')
                            ->label('MOQ')
                            ->numeric()
                            ->minValue(1),
                        TextInput::make('lead_time_days')
                            ->label('Lead Time (days)')
                            ->numeric()
                            ->minValue(0),
                        Checkbox::make('is_preferred')
                            ->label('Preferred Supplier'),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['role'] = 'supplier';
                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mountUsing(function ($form, $record) {
                        $form->fill($record->pivot->toArray());
                    }),
                DetachAction::make(),
            ])
            ->toolbarActions([
                DetachBulkAction::make(),
            ]);
    }
}
