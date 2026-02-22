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

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplierProducts';

    protected static ?string $title = 'Products';

    protected static ?string $recordTitleAttribute = 'name';

    protected static BackedEnum|string|null $icon = 'heroicon-o-cube';

    /**
     * O título da aba muda dinamicamente conforme o papel da empresa.
     * Se a empresa for apenas cliente, exibe os produtos como cliente.
     */
    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        $roles = $ownerRecord->companyRoles->pluck('role');

        if ($roles->contains(CompanyRole::SUPPLIER) && $roles->contains(CompanyRole::CLIENT)) {
            return 'Products (Supplier & Client)';
        }

        if ($roles->contains(CompanyRole::CLIENT)) {
            return 'Products (Client)';
        }

        return 'Products (Supplier)';
    }

    /**
     * Ajusta a relação usada conforme o papel da empresa.
     * Empresas que são apenas clientes usam clientProducts().
     * Empresas que são fornecedores (ou ambos) usam supplierProducts().
     */
    protected function getRelationship(): \Illuminate\Database\Eloquent\Relations\Relation
    {
        $ownerRecord = $this->getOwnerRecord();
        $roles = $ownerRecord->companyRoles->pluck('role');

        if (! $roles->contains(CompanyRole::SUPPLIER) && $roles->contains(CompanyRole::CLIENT)) {
            return $ownerRecord->clientProducts();
        }

        return $ownerRecord->supplierProducts();
    }

    public function form(Schema $schema): Schema
    {
        $ownerRecord = $this->getOwnerRecord();
        $roles = $ownerRecord->companyRoles->pluck('role');
        $isSupplier = $roles->contains(CompanyRole::SUPPLIER);

        return $schema
            ->columns(2)
            ->components([
                TextInput::make('external_code')
                    ->label($isSupplier ? 'Supplier Code' : 'Client Code')
                    ->maxLength(100)
                    ->helperText($isSupplier
                        ? "Supplier's internal code for this product."
                        : "Client's internal code for this product."),
                TextInput::make('external_name')
                    ->label($isSupplier ? 'Supplier Product Name' : 'Client Product Name')
                    ->maxLength(255),
                Textarea::make('external_description')
                    ->label('Product Description')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('Will appear on invoices.')
                    ->columnSpanFull(),
                TextInput::make('unit_price')
                    ->label($isSupplier ? 'Purchase Price' : 'Selling Price')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix('$')
                    ->inputMode('decimal'),
                Select::make('currency_code')
                    ->label('Currency')
                    ->options(fn () => Currency::pluck('code', 'code'))
                    ->searchable(),
                Select::make('incoterm')
                    ->label('Incoterm')
                    ->options(Incoterm::class)
                    ->searchable()
                    ->visible($isSupplier),
                TextInput::make('lead_time_days')
                    ->label('Lead Time (days)')
                    ->numeric()
                    ->minValue(0)
                    ->visible($isSupplier),
                TextInput::make('moq')
                    ->label('MOQ')
                    ->numeric()
                    ->minValue(1)
                    ->visible($isSupplier),
                Checkbox::make('is_preferred')
                    ->label($isSupplier ? 'Preferred Supplier' : 'Primary Client')
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
        $ownerRecord = $this->getOwnerRecord();
        $roles = $ownerRecord->companyRoles->pluck('role');
        $isSupplier = $roles->contains(CompanyRole::SUPPLIER);

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
                    ->label($isSupplier ? 'Supplier Code' : 'Client Code')
                    ->placeholder('—'),
                TextColumn::make('pivot.external_name')
                    ->label($isSupplier ? 'Supplier Name' : 'Client Name')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pivot.unit_price')
                    ->label($isSupplier ? 'Purchase Price' : 'Selling Price')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2) : '—')
                    ->alignEnd(),
                TextColumn::make('pivot.currency_code')
                    ->label('Currency')
                    ->placeholder('—'),
                TextColumn::make('pivot.incoterm')
                    ->label('Incoterm')
                    ->placeholder('—')
                    ->visible($isSupplier),
                TextColumn::make('pivot.moq')
                    ->label('MOQ')
                    ->numeric()
                    ->alignEnd()
                    ->placeholder('—')
                    ->visible($isSupplier),
                TextColumn::make('pivot.lead_time_days')
                    ->label('Lead Time')
                    ->suffix(' days')
                    ->placeholder('—')
                    ->visible($isSupplier),
                IconColumn::make('pivot.is_preferred')
                    ->label($isSupplier ? 'Preferred' : 'Primary')
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
                            ->label($isSupplier ? 'Supplier Code' : 'Client Code')
                            ->maxLength(100),
                        TextInput::make('external_name')
                            ->label($isSupplier ? 'Supplier Product Name' : 'Client Product Name')
                            ->maxLength(255),
                        Textarea::make('external_description')
                            ->label('Product Description')
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText('Will appear on invoices.'),
                        TextInput::make('unit_price')
                            ->label($isSupplier ? 'Purchase Price' : 'Selling Price')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->prefix('$')
                            ->inputMode('decimal')
                            ->default(0),
                        Select::make('currency_code')
                            ->label('Currency')
                            ->options(fn () => Currency::pluck('code', 'code'))
                            ->searchable(),
                        ...$isSupplier ? [
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
                        ] : [],
                        Checkbox::make('is_preferred')
                            ->label($isSupplier ? 'Preferred Supplier' : 'Primary Client'),
                    ])
                    ->mutateFormDataUsing(function (array $data) use ($isSupplier): array {
                        $data['role'] = $isSupplier ? 'supplier' : 'client';
                        $data['unit_price'] = (int) round(($data['unit_price'] ?? 0) * 100);
                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mountUsing(function ($form, $record) {
                        $data = $record->pivot->toArray();
                        $data['unit_price'] = ($data['unit_price'] ?? 0) / 100;
                        $form->fill($data);
                    })
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_price'] = (int) round(($data['unit_price'] ?? 0) * 100);
                        return $data;
                    }),
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No products linked')
            ->emptyStateDescription('Link products to track pricing, codes, and commercial terms for this company.')
            ->emptyStateIcon('heroicon-o-cube');
    }
}
