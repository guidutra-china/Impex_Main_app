<?php

namespace App\Filament\Resources\Catalog\Products\Tables;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Infrastructure\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar')
                    ->label('')
                    ->circular()
                    ->size(48)
                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?background=e2e8f0&color=94a3b8&name=P&size=48'),
                TextColumn::make('sku')
                    ->label(__('forms.labels.sku'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable()
                    ->size('sm')
                    ->color('gray'),
                TextColumn::make('name')
                    ->label(__('forms.labels.name'))
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->weight('bold')
                    ->description(fn ($record) => $record->category?->name),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge(),
                TextColumn::make('costing.base_price')
                    ->label(__('forms.labels.base_price'))
                    ->formatStateUsing(function ($state, $record) {
                        if (! $state) {
                            return '—';
                        }
                        $currency = $record->costing?->currency?->code ?? 'USD';
                        return $currency . ' ' . Money::format($state);
                    })
                    ->sortable()
                    ->alignEnd()
                    ->color(fn ($state) => $state ? null : 'gray'),
                TextColumn::make('suppliers_count')
                    ->label(__('forms.labels.suppliers'))
                    ->counts('suppliers')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'primary' : 'gray'),
                TextColumn::make('clients_count')
                    ->label(__('forms.labels.clients'))
                    ->counts('clients')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('variants_count')
                    ->label(__('forms.labels.variants'))
                    ->counts('variants')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'info' : 'gray'),
                TextColumn::make('parent.name')
                    ->label(__('forms.labels.variant_of'))
                    ->placeholder(__('forms.placeholders.base_product'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('hs_code')
                    ->label(__('forms.labels.hs_code'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('origin_country')
                    ->label(__('forms.labels.origin'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('moq')
                    ->label(__('forms.labels.moq'))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('forms.labels.updated'))
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ProductStatus::class),
                SelectFilter::make('category_id')
                    ->label(__('forms.labels.category'))
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('has_suppliers')
                    ->label(__('forms.labels.has_suppliers'))
                    ->options([
                        'yes' => 'With Suppliers',
                        'no' => 'Without Suppliers',
                    ])
                    ->query(function ($query, array $data) {
                        if ($data['value'] === 'yes') {
                            $query->whereHas('suppliers');
                        } elseif ($data['value'] === 'no') {
                            $query->whereDoesntHave('suppliers');
                        }
                    }),
                TrashedFilter::make(),
            ])
            ->groups([
                Group::make('category.name')
                    ->label(__('forms.labels.category'))
                    ->collapsible(),
                Group::make('status')
                    ->label(__('forms.labels.status'))
                    ->collapsible(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('updated_at', 'desc')
            ->striped()
            ->emptyStateHeading('No products')
            ->emptyStateDescription('Create your first product to start building your catalog.')
            ->emptyStateIcon('heroicon-o-cube');
    }
}
