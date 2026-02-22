<?php

namespace App\Filament\RelationManagers;

use App\Domain\Infrastructure\Enums\DocumentSourceType;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\ViewAction;
use Illuminate\Support\Facades\URL;

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
                    ->label('Current Version')
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

                        return response()->streamDownload(
                            function () use ($record) {
                                echo file_get_contents($record->getFullPath());
                            },
                            $record->name,
                            ['Content-Type' => $record->mime_type ?? 'application/octet-stream'],
                        );
                    }),
                ViewAction::make('version_history')
                    ->label('History')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn ($record) => "Version History — {$record->name}")
                    ->infolist(fn (Schema $schema) => $schema->components([
                        TextEntry::make('version')
                            ->label('Current Version')
                            ->prefix('v')
                            ->badge()
                            ->color('success'),
                        TextEntry::make('updated_at')
                            ->label('Last Updated')
                            ->dateTime('d/m/Y H:i'),
                        RepeatableEntry::make('versions')
                            ->label('Previous Versions')
                            ->schema([
                                TextEntry::make('version')
                                    ->label('Version')
                                    ->prefix('v')
                                    ->badge()
                                    ->color('gray'),
                                TextEntry::make('size')
                                    ->label('Size')
                                    ->formatStateUsing(fn (?int $state) => $state ? self::formatBytes($state) : '—'),
                                TextEntry::make('created_at')
                                    ->label('Date')
                                    ->dateTime('d/m/Y H:i'),
                                TextEntry::make('id')
                                    ->label('Download')
                                    ->formatStateUsing(function ($state) {
                                        $url = URL::signedRoute('document-version.download', ['version' => $state]);

                                        return new \Illuminate\Support\HtmlString(
                                            '<a href="' . e($url) . '" target="_blank" class="text-primary-600 hover:underline text-sm font-medium">Download</a>'
                                        );
                                    }),
                            ])
                            ->columns(4),
                    ])),
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
