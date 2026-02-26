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
                    ->label(__('forms.labels.type'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('forms.labels.name'))
                    ->searchable()
                    ->limit(40),
                TextColumn::make('version')
                    ->label(__('forms.labels.current_version'))
                    ->prefix('v')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('source')
                    ->label(__('forms.labels.source'))
                    ->badge()
                    ->color(fn (DocumentSourceType $state) => match ($state) {
                        DocumentSourceType::GENERATED => 'success',
                        DocumentSourceType::UPLOADED => 'info',
                    }),
                TextColumn::make('size')
                    ->label(__('forms.labels.size'))
                    ->formatStateUsing(fn (int $state) => self::formatBytes($state))
                    ->alignCenter(),
                TextColumn::make('creator.name')
                    ->label(__('forms.labels.created_by'))
                    ->placeholder(__('forms.placeholders.system')),
                TextColumn::make('updated_at')
                    ->label(__('forms.labels.date'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('download')
                    ->label(__('forms.labels.download'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->action(function ($record) {
                        if (! $record->exists()) {
                            Notification::make()
                                ->title(__('messages.file_not_found'))
                                ->body(__('messages.file_not_found_disk'))
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
                    ->label(__('forms.labels.history'))
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn ($record) => "Version History — {$record->name}")
                    ->infolist(fn (Schema $schema) => $schema->components([
                        TextEntry::make('version')
                            ->label(__('forms.labels.current_version'))
                            ->prefix('v')
                            ->badge()
                            ->color('success'),
                        TextEntry::make('updated_at')
                            ->label(__('forms.labels.last_updated'))
                            ->dateTime('d/m/Y H:i'),
                        RepeatableEntry::make('versions')
                            ->label(__('forms.labels.previous_versions'))
                            ->schema([
                                TextEntry::make('version')
                                    ->label(__('forms.labels.version'))
                                    ->prefix('v')
                                    ->badge()
                                    ->color('gray'),
                                TextEntry::make('size')
                                    ->label(__('forms.labels.size'))
                                    ->formatStateUsing(fn (?int $state) => $state ? self::formatBytes($state) : '—'),
                                TextEntry::make('created_at')
                                    ->label(__('forms.labels.date'))
                                    ->dateTime('d/m/Y H:i'),
                                TextEntry::make('id')
                                    ->label(__('forms.labels.download'))
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
