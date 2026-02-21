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
                    ->label('Version')
                    ->prefix('v')
                    ->sortable()
                    ->weight('bold')
                    ->alignCenter(),
                TextColumn::make('creator.name')
                    ->label('Created By')
                    ->placeholder('System'),
                TextColumn::make('change_notes')
                    ->label('Change Notes')
                    ->limit(50)
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('version', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->modalHeading(fn ($record) => "Version v{$record->version} Snapshot")
                    ->infolist(fn (Schema $schema) => $schema->components([
                        TextEntry::make('version')
                            ->label('Version')
                            ->prefix('v'),
                        TextEntry::make('creator.name')
                            ->label('Created By')
                            ->placeholder('System'),
                        TextEntry::make('created_at')
                            ->label('Snapshot Date')
                            ->dateTime('d/m/Y H:i:s'),
                        TextEntry::make('change_notes')
                            ->label('Change Notes')
                            ->placeholder('No notes.')
                            ->columnSpanFull(),
                        TextEntry::make('snapshot')
                            ->label('Snapshot Data')
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
