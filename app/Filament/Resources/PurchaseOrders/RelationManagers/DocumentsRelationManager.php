<?php

namespace App\Filament\Resources\PurchaseOrders\RelationManagers;

use App\Domain\Infrastructure\Enums\DocumentSourceType;
use App\Domain\Infrastructure\Models\Document;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    protected static BackedEnum|string|null $icon = 'heroicon-o-paper-clip';

    protected static ?string $recordTitleAttribute = 'name';

    protected const PO_DOCUMENT_TYPES = [
        'supplier_invoice' => 'Supplier Invoice',
        'packing_list' => 'Packing List',
        'purchase_order' => 'Purchase Order (Signed)',
        'commercial_invoice' => 'Commercial Invoice',
        'certificate_of_origin' => 'Certificate of Origin',
        'inspection_report' => 'Inspection Report',
        'test_report' => 'Test Report',
        'other' => 'Other',
    ];

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::PO_DOCUMENT_TYPES[$state] ?? ucfirst(str_replace('_', ' ', $state)))
                    ->color(fn (string $state) => match ($state) {
                        'supplier_invoice' => 'warning',
                        'packing_list' => 'info',
                        'purchase_order' => 'success',
                        'commercial_invoice' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->limit(40)
                    ->weight('bold'),
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
                    ->formatStateUsing(fn (?int $state) => $state ? self::formatBytes($state) : '—')
                    ->alignCenter(),
                TextColumn::make('creator.name')
                    ->label('Uploaded By')
                    ->placeholder('System')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Document Type')
                    ->options(self::PO_DOCUMENT_TYPES)
                    ->multiple(),
            ], layout: FiltersLayout::AboveContent)
            ->headerActions([
                Action::make('upload')
                    ->label('Upload Document')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->visible(fn () => auth()->user()?->can('edit-purchase-orders'))
                    ->form([
                        Select::make('type')
                            ->label('Document Type')
                            ->options(self::PO_DOCUMENT_TYPES)
                            ->required()
                            ->searchable()
                            ->native(false),
                        TextInput::make('name')
                            ->label('Document Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Supplier Invoice v2, Final Packing List'),
                        FileUpload::make('file')
                            ->label('File')
                            ->required()
                            ->disk('local')
                            ->directory(fn () => 'purchase-orders/' . $this->getOwnerRecord()->id . '/documents')
                            ->maxSize(20480)
                            ->acceptedFileTypes([
                                'application/pdf',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'image/jpeg',
                                'image/png',
                                'image/webp',
                            ])
                            ->helperText('Max 20MB. PDF, Excel, Word, or images.')
                            ->columnSpanFull(),
                    ])
                    ->action(function (array $data): void {
                        $po = $this->getOwnerRecord();
                        $filePath = $data['file'];
                        $disk = 'local';

                        $existing = $po->documents()
                            ->where('type', $data['type'])
                            ->where('name', $data['name'])
                            ->first();

                        if ($existing) {
                            $existing->versions()->create([
                                'path' => $existing->path,
                                'version' => $existing->version,
                                'size' => $existing->size,
                                'checksum' => $existing->checksum,
                                'created_by' => $existing->created_by,
                            ]);

                            $existing->update([
                                'path' => $filePath,
                                'version' => $existing->version + 1,
                                'source' => DocumentSourceType::UPLOADED,
                                'mime_type' => Storage::disk($disk)->mimeType($filePath),
                                'size' => Storage::disk($disk)->size($filePath),
                                'checksum' => hash_file('sha256', Storage::disk($disk)->path($filePath)),
                                'created_by' => auth()->id(),
                            ]);

                            Notification::make()
                                ->title('Document updated')
                                ->body("Version {$existing->version} uploaded successfully.")
                                ->success()
                                ->send();
                        } else {
                            $po->documents()->create([
                                'type' => $data['type'],
                                'name' => $data['name'],
                                'disk' => $disk,
                                'path' => $filePath,
                                'version' => 1,
                                'source' => DocumentSourceType::UPLOADED,
                                'mime_type' => Storage::disk($disk)->mimeType($filePath),
                                'size' => Storage::disk($disk)->size($filePath),
                                'checksum' => hash_file('sha256', Storage::disk($disk)->path($filePath)),
                                'created_by' => auth()->id(),
                            ]);

                            Notification::make()
                                ->title('Document uploaded')
                                ->success()
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->action(function (Document $record) {
                        $fullPath = Storage::disk($record->disk)->path($record->path);

                        if (! file_exists($fullPath)) {
                            Notification::make()
                                ->title('File not found')
                                ->body('The file could not be found on disk.')
                                ->danger()
                                ->send();

                            return;
                        }

                        return response()->streamDownload(
                            function () use ($fullPath) {
                                echo file_get_contents($fullPath);
                            },
                            $record->name . '.' . pathinfo($record->path, PATHINFO_EXTENSION),
                            ['Content-Type' => $record->mime_type ?? 'application/octet-stream'],
                        );
                    }),
                ViewAction::make('version_history')
                    ->label('History')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (Document $record) => "Version History — {$record->name}")
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
            ->emptyStateDescription('Upload supplier invoices, packing lists, and other documents for this purchase order.')
            ->emptyStateIcon('heroicon-o-document-plus');
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
