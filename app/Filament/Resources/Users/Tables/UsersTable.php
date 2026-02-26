<?php

namespace App\Filament\Resources\Users\Tables;

use App\Domain\Users\Enums\UserType;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-envelope'),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label(__('forms.labels.role'))
                    ->badge()
                    ->color('info')
                    ->separator(', '),
                TextColumn::make('company.name')
                    ->label(__('forms.labels.company'))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'inactive' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label(__('forms.labels.created'))
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(UserType::class),
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ]),
                SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->preload(),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('name');
    }
}
