<?php

namespace App\Filament\Resources\CRM\Companies\RelationManagers;

use App\Domain\CRM\Models\Company;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BranchesRelationManager extends RelationManager
{
    protected static string $relationship = 'branches';

    protected static ?string $title = 'Branches';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->isMatrix();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('forms.sections.branch_information'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('forms.labels.branch_name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('legal_name')
                            ->label(__('forms.labels.legal_name'))
                            ->maxLength(255),
                        TextInput::make('tax_number')
                            ->label(__('forms.labels.tax_number'))
                            ->maxLength(50)
                            ->unique(table: Company::class, ignoreRecord: true),
                        TextInput::make('email')
                            ->label(__('forms.labels.email'))
                            ->email()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label(__('forms.labels.phone'))
                            ->tel()
                            ->maxLength(50),
                    ])
                    ->columns(2),

                Section::make(__('forms.sections.address'))
                    ->schema([
                        TextInput::make('address_street')
                            ->label(__('forms.labels.street'))
                            ->maxLength(255),
                        TextInput::make('address_number')
                            ->label(__('forms.labels.number'))
                            ->maxLength(20),
                        TextInput::make('address_complement')
                            ->label(__('forms.labels.complement'))
                            ->maxLength(255),
                        TextInput::make('address_city')
                            ->label(__('forms.labels.city'))
                            ->maxLength(255),
                        TextInput::make('address_state')
                            ->label(__('forms.labels.state_province'))
                            ->maxLength(255),
                        TextInput::make('address_zip')
                            ->label(__('forms.labels.zip_postal_code'))
                            ->maxLength(20),
                        TextInput::make('address_country')
                            ->label(__('forms.labels.country_code'))
                            ->maxLength(2)
                            ->placeholder(__('forms.placeholders.us')),
                    ])
                    ->columns(3),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('forms.labels.branch_name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('legal_name')
                    ->label(__('forms.labels.legal_name'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('tax_number')
                    ->label(__('forms.labels.tax_number'))
                    ->placeholder('—')
                    ->copyable(),
                TextColumn::make('address_city')
                    ->label(__('forms.labels.city'))
                    ->placeholder('—'),
                TextColumn::make('address_country')
                    ->label(__('forms.labels.country'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('phone')
                    ->label(__('forms.labels.phone'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('email')
                    ->label(__('forms.labels.email'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-companies')),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-companies')),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('delete-companies')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('delete-companies')),
                ]),
            ])
            ->defaultSort('name', 'asc')
            ->emptyStateHeading(__('forms.placeholders.no_branches'))
            ->emptyStateDescription(__('forms.helpers.add_branches_for_different_addresses'))
            ->emptyStateIcon('heroicon-o-building-office');
    }
}
