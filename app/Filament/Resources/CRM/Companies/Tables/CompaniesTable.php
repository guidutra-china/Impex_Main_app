<?php

namespace App\Filament\Resources\CRM\Companies\Tables;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Enums\CompanyStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Company')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('companyRoles.role')
                    ->label('Roles')
                    ->badge()
                    ->separator(','),
                TextColumn::make('address_city')
                    ->label('City')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('address_country')
                    ->label('Country')
                    ->searchable()
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('contacts_count')
                    ->label('Contacts')
                    ->counts('contacts')
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('phone')
                    ->label('Phone')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(CompanyStatus::class),
                SelectFilter::make('roles')
                    ->label('Role')
                    ->options(CompanyRole::class)
                    ->query(function ($query, array $data) {
                        if ($data['value']) {
                            $query->whereHas('companyRoles', fn ($q) => $q->where('role', $data['value']));
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name', 'asc')
            ->emptyStateHeading('No companies')
            ->emptyStateDescription('Create your first company to start managing clients, suppliers, and partners.')
            ->emptyStateIcon('heroicon-o-building-office-2');
    }
}
