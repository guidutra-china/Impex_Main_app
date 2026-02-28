<?php

namespace App\Filament\Resources\Catalog\Categories\RelationManagers;

use App\Domain\Catalog\Models\Category;
use App\Domain\CRM\Enums\CompanyRole;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CompaniesRelationManager extends RelationManager
{
    protected static string $relationship = 'companies';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('forms.labels.companies');
    }

    public function table(Table $table): Table
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
                TextColumn::make('country')
                    ->label(__('forms.labels.country'))
                    ->placeholder('—'),
                TextColumn::make('pivot.notes')
                    ->label(__('forms.labels.notes'))
                    ->limit(60)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label(__('forms.labels.roles'))
                    ->options(CompanyRole::class)
                    ->query(function ($query, array $data) {
                        if (filled($data['value'])) {
                            $query->whereHas('companyRoles', fn ($q) => $q->where('role', $data['value']));
                        }
                    }),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label(__('forms.labels.add_company'))
                    ->visible(fn () => auth()->user()?->can('manage-categories'))
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name'])
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Textarea::make('notes')
                            ->label(__('forms.labels.notes'))
                            ->rows(2)
                            ->maxLength(1000),
                    ]),
            ])
            ->recordActions([
                DetachAction::make()
                    ->visible(fn () => auth()->user()?->can('manage-categories')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('manage-categories')),
                ]),
            ])
            ->emptyStateHeading(__('forms.placeholders.no_companies_assigned'))
            ->emptyStateDescription(__('forms.descriptions.companies_associated_with_this_category'))
            ->emptyStateIcon('heroicon-o-building-office-2');
    }
}
