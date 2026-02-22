<?php

namespace App\Filament\RelationManagers;

use App\Domain\Infrastructure\Enums\DocumentSourceType;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    protected static BackedEnum|string|null $icon = 'heroicon-o-paper-clip';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('version')
                    ->label('Version')
                    ->prefix('v')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->color(fn (DocumentSourceType $state) => match ($state) {
                        DocumentSourceType::GENERATED => 'success',
                        DocumentSourceType::UPLOADED => 'info',
                    }),
                TextColumn::make('size')
                    ->label('Size')
                    ->formatStateUsing(fn (int $state) => self::formatBytes($state))
                    ->alignCenter(),
                TextColumn::make('creator.name')
                    ->label('Created By')
                    ->placeholder('System'),
                TextColumn::make('updated_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->action(function ($record) {
                        if (! $record->exists()) {
                            Notification::make()
                                ->title('File not found')
                                ->body('The file could not be found on disk.')
                                ->danger()
                                ->send();

                            return;
                        }

                        return response()->download(
                            $record->getFullPath(),
                            $record->name,
                            ['Content-Type' => $record->mime_type ?? 'application/octet-stream'],
                        );
                    }),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('No documents yet')
            ->emptyStateDescription('Generate or upload documents to see them here.')
            ->emptyStateIcon('heroicon-o-document');
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }
}
