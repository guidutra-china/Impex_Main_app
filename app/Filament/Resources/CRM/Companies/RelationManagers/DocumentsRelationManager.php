<?php

namespace App\Filament\Resources\CRM\Companies\RelationManagers;

use App\Domain\CRM\Enums\DocumentCategory;
use App\Domain\CRM\Models\CompanyDocument;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documents & Photos';

    protected static BackedEnum|string|null $icon = 'heroicon-o-document-text';

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Document Details')
                    ->schema([
                        Select::make('category')
                            ->label('Category')
                            ->options(DocumentCategory::class)
                            ->required()
                            ->searchable()
                            ->native(false),
                        TextInput::make('title')
                            ->label('Title')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. ISO 9001 Certificate, Factory Front Photo'),
                        FileUpload::make('path')
                            ->label('File')
                            ->required()
                            ->disk('public')
                            ->directory(fn () => 'company-documents/' . $this->getOwnerRecord()->id)
                            ->maxSize(20480)
                            ->acceptedFileTypes([
                                'image/jpeg', 'image/png', 'image/webp', 'image/gif',
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-powerpoint',
                                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                            ])
                            ->helperText('Max 20MB. Accepts images, PDF, Word, Excel, PowerPoint.')
                            ->columnSpanFull()
                            ->storeFileNamesIn('original_name')
                            ->visibility('public'),
                        DatePicker::make('expiry_date')
                            ->label('Expiry Date')
                            ->placeholder('Leave blank if not applicable')
                            ->helperText('For certificates, licenses, or contracts with expiration'),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(2)
                            ->maxLength(1000)
                            ->placeholder('Additional context or description')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->limit(40),
                TextColumn::make('original_name')
                    ->label('File')
                    ->limit(30)
                    ->toggleable(),
                TextColumn::make('formatted_size')
                    ->label('Size')
                    ->toggleable(),
                TextColumn::make('expiry_date')
                    ->label('Expires')
                    ->date('Y-m-d')
                    ->placeholder('—')
                    ->sortable()
                    ->color(fn (CompanyDocument $record) => match (true) {
                        $record->isExpired() => 'danger',
                        $record->isExpiringSoon() => 'warning',
                        default => null,
                    }),
                TextColumn::make('uploader.name')
                    ->label('Uploaded By')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(DocumentCategory::class)
                    ->multiple(),
            ], layout: FiltersLayout::AboveContent)
            ->headerActions([
                CreateAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-companies'))
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['disk'] = 'public';
                        $data['uploaded_by'] = auth()->id();
                        $data['size'] = $data['path']
                            ? Storage::disk('public')->size($data['path'])
                            : 0;

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-companies')),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('delete-companies'))
                    ->after(function (CompanyDocument $record) {
                        Storage::disk($record->disk)->delete($record->path);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('delete-companies'))
                        ->after(function ($records) {
                            foreach ($records as $record) {
                                Storage::disk($record->disk)->delete($record->path);
                            }
                        }),
                ]),
            ])
            ->recordUrl(fn (CompanyDocument $record) => Storage::disk($record->disk)->url($record->path))
            ->openRecordUrlInNewTab()
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No documents yet')
            ->emptyStateDescription('Upload certificates, photos, contracts, and other files for this company.')
            ->emptyStateIcon('heroicon-o-document-plus');
    }
}
