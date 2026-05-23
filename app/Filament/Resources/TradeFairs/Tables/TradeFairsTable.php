<?php

namespace App\Filament\Resources\TradeFairs\Tables;

use App\Domain\TradeFairs\Models\TradeFair;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class TradeFairsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(
                fn () => TradeFair::query()
                    ->withCount('companies')
                    ->selectSub(
                        DB::table('company_product')
                            ->join('companies', 'companies.id', '=', 'company_product.company_id')
                            ->whereColumn('companies.trade_fair_id', 'trade_fairs.id')
                            ->selectRaw('COUNT(DISTINCT company_product.product_id)'),
                        'products_count'
                    )
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Feira')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('location')
                    ->label('Local')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('start_date')
                    ->label('Início')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('end_date')
                    ->label('Término')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('companies_count')
                    ->label('Fornecedores')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (?int $state) => ($state ?? 0) > 0 ? 'primary' : 'gray'),
                TextColumn::make('products_count')
                    ->label('Produtos')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (?int $state) => ($state ?? 0) > 0 ? 'info' : 'gray'),
                TextColumn::make('createdBy.name')
                    ->label('Criado por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('forms.labels.updated'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Em andamento',
                        'upcoming' => 'Futura',
                        'past' => 'Encerrada',
                    ])
                    ->query(function ($query, array $data) {
                        $today = today()->toDateString();

                        if ($data['value'] === 'active') {
                            $query->where('start_date', '<=', $today)
                                ->where('end_date', '>=', $today);
                        } elseif ($data['value'] === 'upcoming') {
                            $query->where('start_date', '>', $today);
                        } elseif ($data['value'] === 'past') {
                            $query->where('end_date', '<', $today);
                        }
                    }),
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
            ->defaultSort('start_date', 'desc')
            ->emptyStateHeading('Nenhuma feira cadastrada')
            ->emptyStateDescription('Crie uma feira para começar a registrar fornecedores via PWA mobile.')
            ->emptyStateIcon('heroicon-o-building-storefront');
    }
}
