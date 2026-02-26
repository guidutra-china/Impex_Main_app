<?php

namespace App\Filament\Resources\CRM\Companies\RelationManagers;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\CompanyProductDocument;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Enums\DocumentCategory;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\Models\Currency;
use BackedEnum;
use App\Filament\Actions\ImportProductsFromExcelAction;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class ClientProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'clientProducts';

    protected static ?string $title = 'Products (Client)';

    protected static ?string $recordTitleAttribute = 'name';

    protected static BackedEnum|string|null $icon = 'heroicon-o-user-group';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->companyRoles()->where('role', CompanyRole::CLIENT)->exists();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                FileUpload::make('avatar_path')
                    ->label(__('forms.labels.product_photo'))
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
                    ->label(__('forms.labels.client_code'))
                    ->maxLength(100)
                    ->helperText("Client's internal code for this product."),
                TextInput::make('external_name')
                    ->label(__('forms.labels.client_product_name'))
                    ->maxLength(255),
                Textarea::make('external_description')
                    ->label(__('forms.labels.client_product_description'))
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText(__('forms.helpers.will_appear_on_invoices'))
                    ->columnSpanFull(),
                TextInput::make('unit_price')
                    ->label(__('forms.labels.selling_price'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->prefix('$')
                    ->inputMode('decimal'),
                TextInput::make('custom_price')
                    ->label(__('forms.labels.custom_price_ci_override'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->prefix('$')
                    ->inputMode('decimal')
                    ->helperText(__('forms.helpers.if_set_commercial_invoice_uses_this_instead_of_pi_price')),
                Select::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->options(fn () => Currency::pluck('code', 'code'))
                    ->searchable(),
                Checkbox::make('is_preferred')
                    ->label(__('forms.labels.primary_client'))
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->label(__('forms.labels.notes'))
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
                    ->label(__('forms.labels.sku'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('name')
                    ->label(__('forms.labels.product'))
                    ->searchable()
                    ->limit(40),
                TextColumn::make('category.name')
                    ->label(__('forms.labels.category'))
                    ->badge()
                    ->color('primary'),
                TextColumn::make('pivot.external_code')
                    ->label(__('forms.labels.client_code'))
                    ->placeholder('—'),
                TextColumn::make('pivot.external_name')
                    ->label(__('forms.labels.client_product_name'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pivot.unit_price')
                    ->label(__('forms.labels.selling_price'))
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state, 4) : '—')
                    ->prefix('$ ')
                    ->alignEnd(),
                TextColumn::make('pivot.custom_price')
                    ->label(__('forms.labels.ci_price'))
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state, 4) : '—')
                    ->prefix('$ ')
                    ->alignEnd(),
                TextColumn::make('pivot.currency_code')
                    ->label(__('forms.labels.currency'))
                    ->placeholder('—'),
                IconColumn::make('pivot.is_preferred')
                    ->label(__('forms.labels.primary'))
                    ->boolean()
                    ->alignCenter(),
            ])
            ->headerActions([
                ImportProductsFromExcelAction::make('client', fn () => $this->getOwnerRecord())
                    ->visible(fn () => auth()->user()?->can('edit-companies')),
                ImportProductsFromExcelAction::makeDownloadTemplate('client')
                    ->visible(fn () => auth()->user()?->can('edit-companies')),
                AttachAction::make()
                    ->label(__('forms.labels.add_product'))
                    ->visible(fn () => auth()->user()?->can('edit-companies'))
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['sku', 'name'])
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        FileUpload::make('avatar_path')
                            ->label(__('forms.labels.product_photo'))
                            ->image()
                            ->disk('public')
                            ->directory(fn () => 'company-product-avatars/' . $this->getOwnerRecord()->id)
                            ->imageResizeMode('cover')
                            ->imageCropAspectRatio('1:1')
                            ->imageResizeTargetWidth('400')
                            ->imageResizeTargetHeight('400')
                            ->maxSize(5120),
                        TextInput::make('external_code')
                            ->label(__('forms.labels.client_code'))
                            ->maxLength(100),
                        TextInput::make('external_name')
                            ->label(__('forms.labels.client_product_name'))
                            ->maxLength(255),
                        Textarea::make('external_description')
                            ->label(__('forms.labels.client_product_description'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText(__('forms.helpers.will_appear_on_invoices')),
                        TextInput::make('unit_price')
                            ->label(__('forms.labels.selling_price'))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->prefix('$')
                            ->inputMode('decimal')
                            ->default(0),
                        TextInput::make('custom_price')
                            ->label(__('forms.labels.custom_price_ci_override'))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->prefix('$')
                            ->inputMode('decimal')
                            ->helperText(__('forms.helpers.if_set_ci_uses_this_price')),
                        Select::make('currency_code')
                            ->label(__('forms.labels.currency'))
                            ->options(fn () => Currency::pluck('code', 'code'))
                            ->searchable(),
                        Checkbox::make('is_preferred')
                            ->label(__('forms.labels.primary_client')),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['role'] = 'client';
                        $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);
                        $data['custom_price'] = filled($data['custom_price'] ?? null)
                            ? Money::toMinor($data['custom_price'])
                            : null;
                        $data['avatar_disk'] = 'public';
                        return $data;
                    }),
            ])
            ->recordActions([
                $this->getManageDocumentsAction(),
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-companies'))
                    ->mountUsing(function ($form, $record) {
                        $data = $record->pivot->toArray();
                        $data['unit_price'] = Money::toMajor($data['unit_price'] ?? 0);
                        $data['custom_price'] = filled($data['custom_price'] ?? null)
                            ? Money::toMajor($data['custom_price'])
                            : null;
                        $form->fill($data);
                    })
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);
                        $data['custom_price'] = filled($data['custom_price'] ?? null)
                            ? Money::toMinor($data['custom_price'])
                            : null;
                        $data['avatar_disk'] = 'public';
                        return $data;
                    }),
                DetachAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-companies')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    $this->getBulkPriceUpdateAction('selling_price', 'unit_price', 'Adjust Selling Price'),
                    $this->getBulkPriceUpdateAction('custom_price', 'custom_price', 'Adjust CI Price'),
                    DetachBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('edit-companies')),
                ]),
            ])
            ->emptyStateHeading('No products linked as client')
            ->emptyStateDescription('Link products to track selling prices and client-specific codes for this client.')
            ->emptyStateIcon('heroicon-o-cube');
    }

    protected function getManageDocumentsAction(): Action
    {
        return Action::make('documents')
            ->label(__('forms.labels.docs'))
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
                    ->label(__('forms.labels.category'))
                    ->options(DocumentCategory::class)
                    ->required()
                    ->searchable()
                    ->native(false),
                TextInput::make('title')
                    ->label(__('forms.labels.title'))
                    ->required()
                    ->maxLength(255),
                FileUpload::make('file_path')
                    ->label(__('forms.labels.file'))
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
                    ->helperText(__('forms.helpers.max_20mb_images_pdf_word_excel')),
                Textarea::make('notes')
                    ->label(__('forms.labels.notes'))
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

    private function getBulkPriceUpdateAction(string $name, string $column, string $label): BulkAction
    {
        return BulkAction::make($name)
            ->label($label)
            ->icon('heroicon-o-calculator')
            ->form([
                TextInput::make('formula')
                    ->label(__('forms.labels.formula'))
                    ->required()
                    ->placeholder(__('forms.placeholders.eg_110_or_5_or_250'))
                    ->helperText(__('forms.helpers.apply_to_current_value_110_10_105_5_5_add_5_250_subtract_250')),
            ])
            ->action(function (Collection $records, array $data) use ($column): void {
                $formula = trim($data['formula']);

                if (! preg_match('/^[\\*\\/\\+\\-]\\s*[\\d\\.]+$/', $formula)) {
                    Notification::make()
                        ->danger()
                        ->title(__('messages.invalid_formula'))
                        ->body(__('messages.formula_format_help'))
                        ->send();
                    return;
                }

                $operator = $formula[0];
                $operand = (float) trim(substr($formula, 1));
                $updated = 0;

                foreach ($records as $record) {
                    $currentMinor = $record->pivot->{$column} ?? 0;
                    $currentMajor = Money::toMajor($currentMinor);

                    $newMajor = match ($operator) {
                        '*' => $currentMajor * $operand,
                        '/' => $operand > 0 ? $currentMajor / $operand : $currentMajor,
                        '+' => $currentMajor + $operand,
                        '-' => $currentMajor - $operand,
                    };

                    $newMinor = Money::toMinor(max(0, $newMajor));

                    CompanyProduct::where('company_id', $record->pivot->company_id)
                        ->where('product_id', $record->pivot->product_id)
                        ->update([$column => $newMinor]);

                    $updated++;
                }

                Notification::make()
                    ->success()
                    ->title("Updated {$updated} records")
                    ->body("Applied formula: {$formula}")
                    ->send();
            })
            ->deselectRecordsAfterCompletion()
            ->requiresConfirmation()
            ->modalDescription('This will apply the formula to all selected records. This action cannot be undone.');
    }
}
