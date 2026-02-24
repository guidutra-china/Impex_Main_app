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
use App\Domain\Infrastructure\Support\Money;
use Filament\Tables\Table;

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
                DetachBulkAction::make(),
            ]);
    }
}
