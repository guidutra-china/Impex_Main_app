<?php

namespace App\Filament\Resources\Quotations\RelationManagers;

use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\ViewAction;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Version History';

    protected static string | \BackedEnum | null $icon = 'heroicon-o-clock';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')
                    ->label(__('forms.labels.version'))
                    ->prefix('v')
                    ->sortable()
                    ->weight('bold')
                    ->alignCenter(),
                TextColumn::make('creator.name')
                    ->label(__('forms.labels.created_by'))
                    ->placeholder(__('forms.placeholders.system')),
                TextColumn::make('change_notes')
                    ->label(__('forms.labels.change_notes'))
                    ->limit(50)
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label(__('forms.labels.date'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('version', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->modalHeading(fn ($record) => "Version v{$record->version} Snapshot")
                    ->infolist(fn (Schema $schema) => $schema->components([
                        TextEntry::make('version')
                            ->label(__('forms.labels.version'))
                            ->prefix('v'),
                        TextEntry::make('creator.name')
                            ->label(__('forms.labels.created_by'))
                            ->placeholder(__('forms.placeholders.system')),
                        TextEntry::make('created_at')
                            ->label(__('forms.labels.snapshot_date'))
                            ->dateTime('d/m/Y H:i:s'),
                        TextEntry::make('change_notes')
                            ->label(__('forms.labels.change_notes'))
                            ->placeholder(__('forms.placeholders.no_notes_2'))
                            ->columnSpanFull(),
                        TextEntry::make('snapshot')
                            ->label(__('forms.labels.snapshot_data'))
                            ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                            ->columnSpanFull()
                            ->prose(),
                    ])),
            ])
            ->emptyStateHeading('No versions yet')
            ->emptyStateDescription('Use the "Save Version" button to create a snapshot before making changes.')
            ->emptyStateIcon('heroicon-o-clock');
    }
}
