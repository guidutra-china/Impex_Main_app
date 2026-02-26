<?php

namespace App\Filament\Resources\CRM\Companies\Tables;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Enums\CompanyStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
                    ->label(__('forms.labels.company'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('companyRoles.role')
                    ->label(__('forms.labels.roles'))
                    ->badge()
                    ->separator(','),
                TextColumn::make('address_city')
                    ->label(__('forms.labels.city'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('address_country')
                    ->label(__('forms.labels.country'))
                    ->searchable()
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('contacts_count')
                    ->label(__('forms.labels.contacts'))
                    ->counts('contacts')
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('phone')
                    ->label(__('forms.labels.phone'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('email')
                    ->label(__('forms.labels.email'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('forms.labels.updated'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(CompanyStatus::class),
                SelectFilter::make('roles')
                    ->label(__('forms.labels.role'))
                    ->options(CompanyRole::class)
                    ->query(function ($query, array $data) {
                        if ($data['value']) {
                            $query->whereHas('companyRoles', fn ($q) => $q->where('role', $data['value']));
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
            ->defaultSort('name', 'asc')
            ->emptyStateHeading('No companies')
            ->emptyStateDescription('Create your first company to start managing clients, suppliers, and partners.')
            ->emptyStateIcon('heroicon-o-building-office-2');
    }
}
