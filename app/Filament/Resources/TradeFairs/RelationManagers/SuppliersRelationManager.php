<?php

namespace App\Filament\Resources\TradeFairs\RelationManagers;

use App\Domain\CRM\Models\Company;
use App\Filament\Resources\CRM\Companies\CompanyResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SuppliersRelationManager extends RelationManager
{
    protected static string $relationship = 'companies';

    protected static ?string $title = 'Fornecedores';

    protected static ?string $recordTitleAttribute = 'name';

    protected static BackedEnum|string|null $icon = 'heroicon-o-truck';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('primary_photo')
                    ->label('Foto')
                    ->getStateUsing(fn (Company $record) => $record->photos->first()?->path)
                    ->disk('public')
                    ->circular()
                    ->size(40)
                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?background=e2e8f0&color=64748b&name=F&size=40'),
                TextColumn::make('name')
                    ->label('Fornecedor')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('primaryContact.name')
                    ->label('Contato')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('primaryContact.phone')
                    ->label('Telefone')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('address_city')
                    ->label('Cidade')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('address_country')
                    ->label('País')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('categories.name')
                    ->label('Categorias')
                    ->badge()
                    ->color('info')
                    ->separator(',')
                    ->limitList(3)
                    ->toggleable(),
                TextColumn::make('supplier_products_count')
                    ->label('Produtos')
                    ->counts('supplierProducts')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'primary' : 'gray'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Capturado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('forms.actions.view') ?: 'Ver')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Company $record) => CompanyResource::getUrl('view', ['record' => $record])),
                Action::make('edit')
                    ->label(__('forms.actions.edit') ?: 'Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (Company $record) => CompanyResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn () => auth()->user()?->can('edit-companies')),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhum fornecedor capturado nesta feira')
            ->emptyStateDescription('Os fornecedores são registrados via PWA mobile em /fair-mobile durante a feira.')
            ->emptyStateIcon('heroicon-o-truck');
    }
}
