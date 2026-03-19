<?php

namespace App\Filament\Resources\CRM\Companies\RelationManagers;

use App\Domain\Catalog\Models\Category;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class CategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'categories';

    protected static ?string $title = 'Product Categories / Specialties';

    protected static ?string $recordTitleAttribute = 'name';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_path')
                    ->label(__('forms.labels.category'))
                    ->weight('bold'),
                TextColumn::make('sku_prefix')
                    ->label(__('forms.labels.sku_prefix'))
                    ->badge()
                    ->color('primary')
                    ->placeholder('—'),
                TextColumn::make('products_count')
                    ->label(__('forms.labels.products'))
                    ->counts('products')
                    ->alignCenter(),
                TextColumn::make('pivot.notes')
                    ->label(__('forms.labels.notes'))
                    ->limit(50)
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('create_category')
                    ->label('New Category')
                    ->icon('heroicon-o-plus-circle')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->can('edit-companies'))
                    ->form([
                        TextInput::make('name')
                            ->label(__('forms.labels.name'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (\Filament\Schemas\Components\Utilities\Set $set, $state) => $set('slug', Str::slug($state))),
                        TextInput::make('slug')
                            ->label(__('forms.labels.slug'))
                            ->required()
                            ->maxLength(255),
                        Select::make('parent_id')
                            ->label(__('forms.labels.parent_category'))
                            ->options(fn () => Category::active()->orderBy('name')->get()->pluck('full_path', 'id'))
                            ->searchable()
                            ->placeholder('None (top level)'),
                        TextInput::make('sku_prefix')
                            ->label(__('forms.labels.sku_prefix'))
                            ->maxLength(10),
                        Toggle::make('is_active')
                            ->label(__('forms.labels.active'))
                            ->default(true),
                    ])
                    ->action(function (array $data) {
                        $category = Category::create($data);

                        $this->getOwnerRecord()->categories()->attach($category->id);

                        Notification::make()
                            ->success()
                            ->title("Category \"{$category->name}\" created and assigned")
                            ->send();
                    }),

                AttachAction::make()
                    ->label(__('forms.labels.add_category'))
                    ->visible(fn () => auth()->user()?->can('edit-companies'))
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn ($query) => $query->active()->orderBy('name'))
                    ->recordTitle(fn (Category $record): string => $record->full_path)
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Textarea::make('notes')
                            ->label(__('forms.labels.notes'))
                            ->rows(2)
                            ->maxLength(1000)
                            ->placeholder(__('forms.placeholders.eg_primary_specialty_certified_manufacturer_etc')),
                    ]),
            ])
            ->recordActions([
                DetachAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-companies')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('edit-companies')),
                ]),
            ])
            ->emptyStateHeading('No categories assigned')
            ->emptyStateDescription('Assign product categories to indicate what this company specializes in or purchases.')
            ->emptyStateIcon('heroicon-o-squares-2x2');
    }
}
