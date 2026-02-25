<?php

namespace App\Filament\Resources\CRM\Companies\RelationManagers;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\CompanyProductDocument;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Enums\DocumentCategory;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Settings\Models\Currency;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class SupplierProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplierProducts';

    protected static ?string $title = 'Products (Supplier)';

    protected static ?string $recordTitleAttribute = 'name';

    protected static BackedEnum|string|null $icon = 'heroicon-o-truck';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->companyRoles()->where('role', CompanyRole::SUPPLIER)->exists();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                FileUpload::make('avatar_path')
                    ->label('Product Photo')
                    ->image()
                    ->disk('public')
                    ->directory(fn () => 'company-product-avatars/' . $this->getOwnerRecord()->id)
                    ->imageResizeMode('cover')
                    ->imageCropAspectRatio('1:1')
                    ->imageResizeTargetWidth('400')
                    ->imageResizeTargetHeight('400')
                    ->maxSize(5120)
                    ->columnSpanFull(),
                TextInput::make('external_code')
                    ->label('Supplier Code')
                    ->maxLength(100)
                    ->helperText("Supplier's internal code for this product."),
                TextInput::make('external_name')
                    ->label('Supplier Product Name')
                    ->maxLength(255),
                Textarea::make('external_description')
                    ->label('Supplier Product Description')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('Will appear on invoices.')
                    ->columnSpanFull(),
                TextInput::make('unit_price')
                    ->label('Purchase Price')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->prefix('$')
                    ->inputMode('decimal'),
                Select::make('currency_code')
                    ->label('Currency')
                    ->options(fn () => Currency::pluck('code', 'code'))
                    ->searchable(),
                Select::make('incoterm')
                    ->label('Incoterm')
                    ->options(Incoterm::class)
                    ->searchable(),
                TextInput::make('lead_time_days')
                    ->label('Lead Time (days)')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('moq')
                    ->label('MOQ')
                    ->numeric()
                    ->minValue(1),
                Checkbox::make('is_preferred')
                    ->label('Preferred Supplier')
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('pivot.avatar_path')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->size(40)
                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?name=P&background=e2e8f0&color=64748b&size=40')
                    ->width('50px'),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Product')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->color('primary'),
                TextColumn::make('pivot.external_code')
                    ->label('Supplier Code')
                    ->placeholder('—'),
                TextColumn::make('pivot.unit_price')
                    ->label('Purchase Price')
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state, 4) : '—')
                    ->prefix('$ ')
                    ->alignEnd(),
                TextColumn::make('pivot.currency_code')
                    ->label('Currency')
                    ->placeholder('—'),
                TextColumn::make('pivot.incoterm')
                    ->label('Incoterm')
                    ->placeholder('—'),
                TextColumn::make('pivot.moq')
                    ->label('MOQ')
                    ->numeric()
                    ->alignEnd()
                    ->placeholder('—'),
                TextColumn::make('pivot.lead_time_days')
                    ->label('Lead Time')
                    ->suffix(' days')
                    ->placeholder('—'),
                IconColumn::make('pivot.is_preferred')
                    ->label('Preferred')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Add Product')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['sku', 'name'])
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        FileUpload::make('avatar_path')
                            ->label('Product Photo')
                            ->image()
                            ->disk('public')
                            ->directory(fn () => 'company-product-avatars/' . $this->getOwnerRecord()->id)
                            ->imageResizeMode('cover')
                            ->imageCropAspectRatio('1:1')
                            ->imageResizeTargetWidth('400')
                            ->imageResizeTargetHeight('400')
                            ->maxSize(5120),
                        TextInput::make('external_code')
                            ->label('Supplier Code')
                            ->maxLength(100),
                        TextInput::make('external_name')
                            ->label('Supplier Product Name')
                            ->maxLength(255),
                        Textarea::make('external_description')
                            ->label('Supplier Product Description')
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText('Will appear on invoices.'),
                        TextInput::make('unit_price')
                            ->label('Purchase Price')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->prefix('$')
                            ->inputMode('decimal')
                            ->default(0),
                        Select::make('currency_code')
                            ->label('Currency')
                            ->options(fn () => Currency::pluck('code', 'code'))
                            ->searchable(),
                        Select::make('incoterm')
                            ->label('Incoterm')
                            ->options(Incoterm::class)
                            ->searchable(),
                        TextInput::make('moq')
                            ->label('MOQ')
                            ->numeric()
                            ->minValue(1),
                        TextInput::make('lead_time_days')
                            ->label('Lead Time (days)')
                            ->numeric()
                            ->minValue(0),
                        Checkbox::make('is_preferred')
                            ->label('Preferred Supplier'),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['role'] = 'supplier';
                        $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);
                        $data['avatar_disk'] = 'public';
                        return $data;
                    }),
            ])
            ->recordActions([
                $this->getManageDocumentsAction(),
                EditAction::make()
                    ->mountUsing(function ($form, $record) {
                        $data = $record->pivot->toArray();
                        $data['unit_price'] = Money::toMajor($data['unit_price'] ?? 0);
                        $form->fill($data);
                    })
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);
                        $data['avatar_disk'] = 'public';
                        return $data;
                    }),
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No products linked as supplier')
            ->emptyStateDescription('Link products to track purchase prices, MOQ, lead times and incoterms for this supplier.')
            ->emptyStateIcon('heroicon-o-cube');
    }

    protected function getManageDocumentsAction(): Action
    {
        return Action::make('documents')
            ->label('Docs')
            ->icon('heroicon-o-paper-clip')
            ->color('gray')
            ->modalHeading(fn (Model $record) => "Documents — {$record->name}")
            ->modalWidth('3xl')
            ->modalContentFooter(function (Model $record): HtmlString {
                $documents = CompanyProductDocument::where('company_product_id', $record->pivot->id)
                    ->orderByDesc('created_at')
                    ->get();

                return new HtmlString(
                    view('filament.components.company-product-documents-list', [
                        'documents' => $documents,
                    ])->render()
                );
            })
            ->form([
                Select::make('category')
                    ->label('Category')
                    ->options(DocumentCategory::class)
                    ->required()
                    ->searchable()
                    ->native(false),
                TextInput::make('title')
                    ->label('Title')
                    ->required()
                    ->maxLength(255),
                FileUpload::make('file_path')
                    ->label('File')
                    ->required()
                    ->disk('public')
                    ->directory(fn (Model $record) => 'company-product-docs/' . $record->pivot->id)
                    ->maxSize(20480)
                    ->acceptedFileTypes([
                        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->storeFileNamesIn('original_name')
                    ->helperText('Max 20MB. Images, PDF, Word, Excel.'),
                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->maxLength(1000),
            ])
            ->modalSubmitActionLabel('Upload Document')
            ->action(function (array $data, Model $record): void {
                CompanyProductDocument::create([
                    'company_product_id' => $record->pivot->id,
                    'category' => $data['category'],
                    'title' => $data['title'],
                    'disk' => 'public',
                    'path' => $data['file_path'],
                    'original_name' => $data['original_name'] ?? basename($data['file_path']),
                    'size' => Storage::disk('public')->size($data['file_path']),
                    'notes' => $data['notes'] ?? null,
                    'uploaded_by' => auth()->id(),
                ]);
            })
            ->badge(fn (Model $record) => CompanyProductDocument::where('company_product_id', $record->pivot->id)->count() ?: null)
            ->badgeColor('info');
    }
}
