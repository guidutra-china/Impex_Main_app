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
                Section::make(__('forms.sections.document_details'))
                    ->schema([
                        Select::make('category')
                            ->label(__('forms.labels.category'))
                            ->options(DocumentCategory::class)
                            ->required()
                            ->searchable()
                            ->native(false),
                        TextInput::make('title')
                            ->label(__('forms.labels.title'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('forms.placeholders.eg_iso_9001_certificate_factory_front_photo')),
                        FileUpload::make('path')
                            ->label(__('forms.labels.file'))
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
                            ->helperText(__('forms.helpers.max_20mb_accepts_images_pdf_word_excel_powerpoint'))
                            ->columnSpanFull()
                            ->storeFileNamesIn('original_name')
                            ->visibility('public'),
                        DatePicker::make('expiry_date')
                            ->label(__('forms.labels.expiry_date'))
                            ->placeholder(__('forms.placeholders.leave_blank_if_not_applicable'))
                            ->helperText(__('forms.helpers.for_certificates_licenses_or_contracts_with_expiration')),
                        Textarea::make('notes')
                            ->label(__('forms.labels.notes'))
                            ->rows(2)
                            ->maxLength(1000)
                            ->placeholder(__('forms.placeholders.additional_context_or_description'))
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
                    ->label(__('forms.labels.category'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('title')
                    ->label(__('forms.labels.title'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->limit(40),
                TextColumn::make('original_name')
                    ->label(__('forms.labels.file'))
                    ->limit(30)
                    ->toggleable(),
                TextColumn::make('formatted_size')
                    ->label(__('forms.labels.size'))
                    ->toggleable(),
                TextColumn::make('expiry_date')
                    ->label(__('forms.labels.expires'))
                    ->date('Y-m-d')
                    ->placeholder('—')
                    ->sortable()
                    ->color(fn (CompanyDocument $record) => match (true) {
                        $record->isExpired() => 'danger',
                        $record->isExpiringSoon() => 'warning',
                        default => null,
                    }),
                TextColumn::make('uploader.name')
                    ->label(__('forms.labels.uploaded_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('forms.labels.uploaded'))
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
