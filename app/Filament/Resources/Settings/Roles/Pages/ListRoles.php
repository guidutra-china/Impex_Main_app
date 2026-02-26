<?php

namespace App\Filament\Resources\Settings\Roles\Pages;

use App\Filament\Resources\Settings\Roles\RoleResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('forms.labels.role'))
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('permissions_count')
                    ->label(__('forms.labels.permissions'))
                    ->counts('permissions')
                    ->badge()
                    ->color('primary'),
                TextColumn::make('users_count')
                    ->label(__('forms.labels.users'))
                    ->counts('users')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('updated_at')
                    ->label(__('forms.labels.last_updated'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->paginated(false);
    }
}
