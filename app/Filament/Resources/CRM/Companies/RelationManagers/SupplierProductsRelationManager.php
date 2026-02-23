<?php

namespace App\Filament\Resources\CRM\Companies\RelationManagers;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Settings\Models\Currency;
use BackedEnum;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
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
use App\Domain\Infrastructure\Support\Money;
use Illuminate\Database\Eloquent\Model;

class SupplierProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplierProducts';

    protected static ?string $title = 'Products (Supplier)';

    protected static ?string $recordTitleAttribute = 'name';

    protected static BackedEnum|string|null $icon = 'heroicon-o-truck';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->companyRoles()->where('role', CompanyRole::SUPPLIER)->exists();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('external_code')
                    ->label('Supplier Code')
                    ->maxLength(100)
                    ->helperText("Supplier's internal code for this product."),
                TextInput::make('external_name')
                    ->label('Supplier Product Name')
                    ->maxLength(255),
                Textarea::make('external_description')
                    ->label('Supplier Product Description')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('Will appear on invoices.')
                    ->columnSpanFull(),
                TextInput::make('unit_price')
                    ->label('Purchase Price')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->prefix('$')
                    ->inputMode('decimal'),
                Select::make('currency_code')
                    ->label('Currency')
                    ->options(fn () => Currency::pluck('code', 'code'))
                    ->searchable(),
                Select::make('incoterm')
                    ->label('Incoterm')
                    ->options(Incoterm::class)
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
                    ->label('Preferred Supplier')
                    ->columnSpanFull(),
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
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Product')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->color('primary'),
                TextColumn::make('pivot.external_code')
                    ->label('Supplier Code')
                    ->placeholder('—'),
                TextColumn::make('pivot.unit_price')
                    ->label('Purchase Price')
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state, 4) : '—')
                    ->prefix('$ ')
                    ->alignEnd(),
                TextColumn::make('pivot.currency_code')
                    ->label('Currency')
                    ->placeholder('—'),
                TextColumn::make('pivot.incoterm')
                    ->label('Incoterm')
                    ->placeholder('—'),
                TextColumn::make('pivot.moq')
                    ->label('MOQ')
                    ->numeric()
                    ->alignEnd()
                    ->placeholder('—'),
                TextColumn::make('pivot.lead_time_days')
                    ->label('Lead Time')
                    ->suffix(' days')
                    ->placeholder('—'),
                IconColumn::make('pivot.is_preferred')
                    ->label('Preferred')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Add Product')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['sku', 'name'])
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
                            ->label('Purchase Price')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->prefix('$')
                            ->inputMode('decimal')
                            ->default(0),
                        Select::make('currency_code')
                            ->label('Currency')
                            ->options(fn () => Currency::pluck('code', 'code'))
                            ->searchable(),
                        Select::make('incoterm')
                            ->label('Incoterm')
                            ->options(Incoterm::class)
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
                        $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);
                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mountUsing(function ($form, $record) {
                        $data = $record->pivot->toArray();
                        $data['unit_price'] = Money::toMajor($data['unit_price'] ?? 0);
                        $form->fill($data);
                    })
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);
                        return $data;
                    }),
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No products linked as supplier')
            ->emptyStateDescription('Link products to track purchase prices, MOQ, lead times and incoterms for this supplier.')
            ->emptyStateIcon('heroicon-o-cube');
    }
}
