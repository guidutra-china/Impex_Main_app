<?php

namespace App\Filament\RelationManagers;

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
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents';

    protected static BackedEnum|string|null $icon = 'heroicon-o-paper-clip';

    protected static ?string $recordTitleAttribute = 'name';

    protected const DOCUMENT_TYPES = [
        'quotation_pdf' => 'Quotation PDF',
        'supplier_quotation' => 'Supplier Quotation',
        'supplier_response' => 'Supplier Response',
        'proforma_invoice' => 'Proforma Invoice',
        'commercial_invoice' => 'Commercial Invoice',
        'packing_list' => 'Packing List',
        'bill_of_lading' => 'Bill of Lading',
        'certificate_of_origin' => 'Certificate of Origin',
        'inspection_report' => 'Inspection Report',
        'contract' => 'Contract',
        'rfq_pdf' => 'RFQ PDF',
        'other' => 'Other',
    ];

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label(__('forms.labels.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::DOCUMENT_TYPES[$state] ?? ucfirst(str_replace('_', ' ', $state)))
                    ->color(fn (string $state) => match ($state) {
                        'quotation_pdf', 'rfq_pdf' => 'success',
                        'supplier_quotation', 'supplier_response' => 'warning',
                        'proforma_invoice', 'commercial_invoice' => 'danger',
                        'packing_list', 'bill_of_lading' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('forms.labels.name'))
                    ->searchable()
                    ->limit(40)
                    ->weight('bold'),
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
                    ->formatStateUsing(fn (?int $state) => $state ? self::formatBytes($state) : '—')
                    ->alignCenter(),
                TextColumn::make('creator.name')
                    ->label(__('forms.labels.created_by'))
                    ->placeholder(__('forms.placeholders.system')),
                TextColumn::make('updated_at')
                    ->label(__('forms.labels.date'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('upload')
                    ->label(__('forms.labels.upload_document'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->form([
                        Select::make('type')
                            ->label(__('forms.labels.document_type'))
                            ->options(self::DOCUMENT_TYPES)
                            ->required()
                            ->searchable()
                            ->native(false),
                        TextInput::make('name')
                            ->label(__('forms.labels.document_name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Supplier Price List, Signed Contract'),
                        FileUpload::make('file')
                            ->label(__('forms.labels.file'))
                            ->required()
                            ->disk('local')
                            ->directory(fn () => 'documents/' . strtolower(class_basename($this->getOwnerRecord())) . '/' . $this->getOwnerRecord()->id)
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
                        $owner = $this->getOwnerRecord();
                        $filePath = $data['file'];
                        $disk = 'local';

                        $existing = $owner->documents()
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
                                ->title(__('messages.document_updated'))
                                ->body("Version {$existing->version} uploaded successfully.")
                                ->success()
                                ->send();
                        } else {
                            $owner->documents()->create([
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
                                ->title(__('messages.document_uploaded'))
                                ->success()
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('download')
                    ->label(__('forms.labels.download'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->action(function (Document $record) {
                        $fullPath = Storage::disk($record->disk)->path($record->path);

                        if (! file_exists($fullPath)) {
                            Notification::make()
                                ->title(__('messages.file_not_found'))
                                ->body(__('messages.file_not_found_disk'))
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
                Action::make('delete')
                    ->label(__('forms.labels.delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Document $record) {
                        Storage::disk($record->disk)->delete($record->path);
                        $record->versions->each(function ($version) use ($record) {
                            Storage::disk($record->disk)->delete($version->path);
                        });
                        $record->versions()->delete();
                        $record->delete();

                        Notification::make()
                            ->title('Document deleted')
                            ->success()
                            ->send();
                    }),
                ViewAction::make('version_history')
                    ->label(__('forms.labels.history'))
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (Document $record) => "Version History — {$record->name}")
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
            ->emptyStateDescription('Upload or generate documents to see them here.')
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
