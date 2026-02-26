<?php

namespace App\Filament\Resources\CRM\Companies\RelationManagers;

use App\Domain\CRM\Enums\CompanyRole;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\BulkActionGroup;

class RolesRelationManager extends RelationManager
{
    protected static string $relationship = 'companyRoles';

    protected static ?string $title = 'Roles';

    protected static ?string $recordTitleAttribute = 'role';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('role')
                    ->label(__('forms.labels.role'))
                    ->options(CompanyRole::class)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('role')
                    ->label(__('forms.labels.role'))
                    ->badge(),
                TextColumn::make('created_at')
                    ->label(__('forms.labels.assigned_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('forms.labels.add_role'))
                    ->visible(fn () => auth()->user()?->can('edit-companies')),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-companies')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('edit-companies')),
                ]),
            ]);
    }
}
