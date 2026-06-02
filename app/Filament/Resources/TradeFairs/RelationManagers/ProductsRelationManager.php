<?php

namespace App\Filament\Resources\TradeFairs\RelationManagers;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Resources\Catalog\Products\ProductResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'companyProducts';

    protected static ?string $title = 'Produtos';

    protected static ?string $recordTitleAttribute = 'external_name';

    protected static BackedEnum|string|null $icon = 'heroicon-o-cube';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['product.category', 'company']))
            ->columns([
                ImageColumn::make('avatar_path')
                    ->label('')
                    ->disk(fn (CompanyProduct $record) => $record->avatar_disk ?? 'public')
                    ->circular()
                    ->size(48)
                    ->defaultImageUrl(fn (CompanyProduct $record) => $record->product?->avatar
                        ? \Illuminate\Support\Facades\Storage::disk('public')->url($record->product->avatar)
                        : 'https://ui-avatars.com/api/?background=e2e8f0&color=94a3b8&name=P&size=48'),
                TextColumn::make('product.sku')
                    ->label('SKU')
                    ->searchable()
                    ->weight('bold')
                    ->copyable()
                    ->size('sm')
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable()
                    ->limit(40)
                    ->weight('bold')
                    ->description(fn (CompanyProduct $record) => $record->external_name ?: null),
                TextColumn::make('product.category.name')
                    ->label('Categoria')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('company.name')
                    ->label('Fornecedor')
                    ->searchable()
                    ->badge()
                    ->color('warning'),
                TextColumn::make('unit_price')
                    ->label('Preço unit.')
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state, 4) : '—')
                    ->prefix(fn (CompanyProduct $record) => $record->currency_code ? $record->currency_code.' ' : '$ ')
                    ->alignEnd(),
                TextColumn::make('moq')
                    ->label('MOQ')
                    ->numeric()
                    ->alignEnd()
                    ->placeholder('—'),
                TextColumn::make('product.status')
                    ->label('Status')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Capturado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Fornecedor')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                Action::make('viewProduct')
                    ->label('Ver produto')
                    ->icon('heroicon-o-eye')
                    ->url(fn (CompanyProduct $record) => $record->product_id
                        ? ProductResource::getUrl('view', ['record' => $record->product_id])
                        : null),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhum produto capturado nesta feira')
            ->emptyStateDescription('Produtos são adicionados ao registrar fornecedores via PWA mobile.')
            ->emptyStateIcon('heroicon-o-cube');
    }
}
