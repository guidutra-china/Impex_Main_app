<?php

namespace App\Filament\Resources\Inquiries\RelationManagers;

use App\Domain\Inquiries\Enums\ProjectTeamRole;
use App\Domain\Users\Enums\UserType;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProjectTeamRelationManager extends RelationManager
{
    protected static string $relationship = 'teamMembers';

    protected static ?string $recordTitleAttribute = 'user.name';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('forms.labels.project_team');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->label(__('forms.labels.team_member'))
                ->options(
                    User::where('type', UserType::INTERNAL)
                        ->where('status', 'active')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                )
                ->searchable()
                ->required(),
            Select::make('role')
                ->label(__('forms.labels.team_role'))
                ->options(ProjectTeamRole::class)
                ->required(),
            Textarea::make('notes')
                ->label(__('forms.labels.notes'))
                ->rows(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('forms.labels.team_member'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('user.email')
                    ->label(__('forms.labels.email'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('role')
                    ->label(__('forms.labels.team_role'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('notes')
                    ->label(__('forms.labels.notes'))
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('forms.labels.assigned_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label(__('forms.labels.team_role'))
                    ->options(ProjectTeamRole::class),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('forms.labels.add_team_member'))
                    ->visible(fn () => auth()->user()?->can('edit-inquiries')),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-inquiries')),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-inquiries')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('edit-inquiries')),
                ]),
            ])
            ->defaultSort('role')
            ->emptyStateHeading(__('forms.placeholders.no_team_members'))
            ->emptyStateDescription(__('forms.descriptions.add_team_members_to_assign'));
    }
}
