<?php

namespace App\Filament\Resources\SupplierQuotations\RelationManagers;

use Anthropic\Core\Exceptions\AnthropicException;
use App\Domain\AI\Import\DocumentExtractor;
use App\Domain\AI\Import\DraftExtractor;
use App\Domain\AI\Import\Exceptions\ExtractionFailedException;
use App\Domain\AI\Import\Exceptions\UnsupportedDocumentException;
use App\Domain\AI\Import\Targets\SupplierQuotationResponseTarget;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\SupplierQuotations\Actions\ApplySupplierQuotationResponseAction;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use App\Filament\Actions\PasteItemsFromSpreadsheetAction;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Quotation Items';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-queue-list';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(4)
            ->components([
                Select::make('product_id')
                    ->label(__('forms.labels.product'))
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search) {
                        return Product::query()
                            ->where(function ($query) use ($search) {
                                $query->where('name', 'like', "%{$search}%")
                                    ->orWhere('sku', 'like', "%{$search}%")
                                    ->orWhere('model_number', 'like', "%{$search}%");
                            })
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(function ($p) {
                                $prefix = $p->status === ProductStatus::DRAFT ? '[DRAFT] ' : '';

                                return [$p->id => $prefix.$p->sku.' — '.$p->name];
                            });
                    })
                    ->getOptionLabelUsing(function ($value) {
                        $product = Product::find($value);
                        if (! $product) {
                            return null;
                        }
                        $prefix = $product->status === ProductStatus::DRAFT ? '[DRAFT] ' : '';

                        return $prefix.$product->sku.' — '.$product->name;
                    })
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state) {
                        if ($state) {
                            $product = Product::find($state);
                            if ($product) {
                                $set('description', $product->name);

                                $supplierQuotation = $this->getOwnerRecord();
                                $pivot = $product->companies()
                                    ->where('company_id', $supplierQuotation->company_id)
                                    ->where('role', 'supplier')
                                    ->first();

                                if ($pivot && $pivot->pivot->unit_price) {
                                    $majorValue = Money::toMajor($pivot->pivot->unit_price);
                                    $set('unit_cost', number_format($majorValue, 4, '.', ''));
                                    static::recalculateTotal($set, fn ($key) => match ($key) {
                                        'quantity' => 1,
                                        'unit_cost' => number_format($majorValue, 4, '.', ''),
                                        default => null,
                                    });
                                }
                            }
                        }
                    })
                    ->helperText(__('forms.helpers.search_by_name_or_sku_price_autofills_from_supplier_catalog'))
                    ->columnSpanFull(),
                TextInput::make('description')
                    ->label(__('forms.labels.description'))
                    ->maxLength(500)
                    ->columnSpanFull(),
                TextInput::make('quantity')
                    ->label(__('forms.labels.quantity'))
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, Get $get) {
                        static::recalculateTotal($set, $get);
                    }),
                TextInput::make('unit')
                    ->label(__('forms.labels.unit'))
                    ->default('pcs')
                    ->maxLength(20)
                    ->required(),
                TextInput::make('unit_cost')
                    ->label(__('forms.labels.unit_cost'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->prefix('$')
                    ->required()
                    ->default(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, Get $get) {
                        static::recalculateTotal($set, $get);
                    })
                    ->formatStateUsing(fn ($state) => $state ? number_format(Money::toMajor($state), 4, '.', '') : '0.0000')
                    ->dehydrateStateUsing(fn ($state) => Money::toMinor($state)),
                TextInput::make('total_cost')
                    ->label(__('forms.labels.total_cost'))
                    ->numeric()
                    ->prefix('$')
                    ->disabled()
                    ->dehydrated()
                    ->formatStateUsing(fn ($state) => $state ? number_format(Money::toMajor($state), 4, '.', '') : '0.0000')
                    ->dehydrateStateUsing(fn ($state) => Money::toMinor($state)),
                TextInput::make('moq')
                    ->label(__('forms.labels.item_moq'))
                    ->numeric()
                    ->minValue(0),
                TextInput::make('lead_time_days')
                    ->label(__('forms.labels.item_lead_time_days'))
                    ->numeric()
                    ->minValue(0),
                Textarea::make('specifications')
                    ->label(__('forms.labels.specifications'))
                    ->rows(2)
                    ->maxLength(2000)
                    ->columnSpan(2),
                Textarea::make('notes')
                    ->label(__('forms.labels.notes'))
                    ->rows(2)
                    ->maxLength(1000)
                    ->columnSpan(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\ImageColumn::make('product.avatar')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->size(40)
                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?background=e2e8f0&color=94a3b8&name=P&size=40'),
                TextColumn::make('product.sku')
                    ->label(__('forms.labels.sku'))
                    ->placeholder('—')
                    ->searchable()
                    ->badge()
                    ->color(fn ($record) => $record->product?->status === ProductStatus::DRAFT ? 'warning' : 'gray'),
                TextColumn::make('displayName')
                    ->label(__('forms.labels.item'))
                    ->searchable(['description'])
                    ->limit(40),

                // --- Inline editable columns ---
                TextInputColumn::make('quantity')
                    ->label(__('forms.labels.qty'))
                    ->type('number')
                    ->inputMode('numeric')
                    ->step('1')
                    ->rules(['required', 'integer', 'min:1'])
                    ->updateStateUsing(function ($record, $state) {
                        $record->quantity = (int) $state;
                        $record->save(); // model's saving() hook recalculates total_cost

                        return $state;
                    })
                    ->alignCenter(),
                TextInputColumn::make('unit')
                    ->label(__('forms.labels.unit'))
                    ->rules(['required', 'max:20'])
                    ->alignCenter(),
                TextInputColumn::make('unit_cost')
                    ->label(__('forms.labels.unit_cost'))
                    ->type('number')
                    ->inputMode('decimal')
                    ->step('0.0001')
                    ->prefix('$')
                    ->rules(['required', 'numeric', 'min:0'])
                    // Display: convert minor units (DB) → major units for the input field.
                    ->getStateUsing(fn ($record) => number_format(Money::toMajor($record->unit_cost ?? 0), 4, '.', ''))
                    // Save: convert major units (user input) → minor units (DB).
                    // updateStateUsing REPLACES Filament's default save entirely,
                    // so the raw string never touches $record->setAttribute().
                    ->updateStateUsing(function ($record, $state) {
                        $floatValue = (float) str_replace(',', '', (string) $state);
                        $record->unit_cost = Money::toMinor($floatValue);
                        $record->save(); // model's saving() hook recalculates total_cost

                        return number_format($floatValue, 4, '.', ''); // return display value
                    })
                    ->alignEnd(),
                TextColumn::make('total_cost')
                    ->label(__('forms.labels.total'))
                    ->formatStateUsing(fn ($state) => $state ? '$ '.Money::format($state) : '—')
                    ->alignEnd()
                    ->weight('bold'),
                TextInputColumn::make('moq')
                    ->label(__('forms.labels.moq'))
                    ->type('number')
                    ->inputMode('numeric')
                    ->step('1')
                    ->rules(['nullable', 'integer', 'min:0'])
                    ->alignCenter(),
                TextInputColumn::make('lead_time_days')
                    ->label(__('forms.labels.lead_time'))
                    ->type('number')
                    ->inputMode('numeric')
                    ->step('1')
                    ->rules(['nullable', 'integer', 'min:0'])
                    ->suffix(' d')
                    ->alignCenter(),
                TextInputColumn::make('notes')
                    ->label(__('forms.labels.notes'))
                    ->rules(['nullable', 'max:1000']),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-supplier-quotations')),
                Action::make('importInquiryItems')
                    ->label(__('forms.labels.import_inquiry_items'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->visible(fn () => $this->getOwnerRecord()->inquiry_id
                        && $this->getOwnerRecord()->items()->count() === 0
                        && auth()->user()?->can('edit-supplier-quotations'))
                    ->form([
                        \Filament\Forms\Components\Toggle::make('include_target_price')
                            ->label('Include Target Price')
                            ->helperText('Pre-fill unit cost with the target price from the inquiry (if available)')
                            // Desligado: o alvo é do cliente, não custo do fornecedor
                            // (mesma regra do Request Supplier Quotation).
                            ->default(false),
                    ])
                    ->modalHeading('Import Items from Inquiry')
                    ->modalDescription('This will copy all items from the linked inquiry into this supplier quotation.')
                    ->action(function (array $data) {
                        try {
                            $record = $this->getOwnerRecord();
                            $inquiry = $record->inquiry;
                            if (! $inquiry) {
                                throw new \RuntimeException('No inquiry linked.');
                            }

                            $includeTargetPrice = $data['include_target_price'] ?? false;
                            $count = 0;
                            DB::transaction(function () use ($record, $inquiry, $includeTargetPrice, &$count) {
                                foreach ($inquiry->items as $item) {
                                    $unitCost = ($includeTargetPrice && $item->target_price) ? $item->target_price : 0;

                                    SupplierQuotationItem::create([
                                        'supplier_quotation_id' => $record->id,
                                        'inquiry_item_id' => $item->id,
                                        'product_id' => $item->product_id,
                                        'description' => $item->description,
                                        'quantity' => $item->quantity,
                                        'unit' => $item->unit,
                                        'unit_cost' => $unitCost,
                                        'specifications' => $item->specifications,
                                        'notes' => $item->notes,
                                        'sort_order' => $item->sort_order,
                                    ]);
                                    $count++;
                                }
                            });

                            Notification::make()
                                ->title("{$count} items imported from inquiry")
                                ->body($inquiry->reference)
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('messages.import_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                PasteItemsFromSpreadsheetAction::forSupplierQuotationItems(),
                $this->importSupplierResponseAction(),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-supplier-quotations')),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-supplier-quotations')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('edit-supplier-quotations')),
                ]),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->emptyStateHeading('No items')
            ->emptyStateDescription('Add items manually or use "Import Inquiry Items" button above to copy items from the linked inquiry.');
    }

    protected static function recalculateTotal(Set $set, Get|callable $get): void
    {
        $quantity = (int) ($get('quantity') ?? 0);
        $unitCost = (float) ($get('unit_cost') ?? 0);
        $total = $quantity * $unitCost;
        $set('total_cost', number_format($total, 4, '.', ''));
    }

    /**
     * Passo 1: sobe a resposta do fornecedor e extrai com IA casando com as
     * nossas linhas. Nada é gravado aqui — o resultado abre o passo 2 (revisão).
     */
    protected function importSupplierResponseAction(): Action
    {
        return Action::make('importSupplierResponse')
            ->label(__('forms.labels.import_supplier_response'))
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            ->visible(fn () => (auth()->user()?->can('edit-supplier-quotations') ?? false)
                && (auth()->user()?->can('view-assistant') ?? false)
                && in_array($this->getOwnerRecord()->status, ApplySupplierQuotationResponseAction::ALLOWED_STATUSES, true)
                && $this->getOwnerRecord()->items()->exists())
            ->modalHeading(__('forms.labels.import_supplier_response'))
            ->modalSubmitActionLabel(__('assistant.import_with_ai'))
            ->schema([
                FileUpload::make('file')
                    ->label(__('forms.labels.supplier_response_file'))
                    ->disk('local')
                    ->directory(fn () => 'ai-imports/'.uniqid('resp_'))
                    ->storeFileNamesIn('file_name')
                    ->acceptedFileTypes([
                        'application/pdf',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->maxSize(51200)
                    ->required()
                    ->helperText(__('forms.helpers.supplier_response_file')),
                Hidden::make('file_name'),
            ])
            ->action(function (array $data) {
                $relativePath = (string) $data['file'];

                try {
                    $target = new SupplierQuotationResponseTarget($this->getOwnerRecord());
                    $blocks = app(DocumentExtractor::class)->toContentBlocks(Storage::disk('local')->path($relativePath));
                    $preview = $target->resolve(app(DraftExtractor::class)->extract($target, $blocks));
                } catch (UnsupportedDocumentException|ExtractionFailedException $e) {
                    $this->failSupplierResponse($relativePath, $e->getMessage());

                    return;
                } catch (AnthropicException $e) {
                    report($e);
                    $this->failSupplierResponse($relativePath, __('assistant.connection_failed'));

                    return;
                } catch (\Throwable $e) {
                    report($e);
                    $this->failSupplierResponse($relativePath, __('assistant.process_error'));

                    return;
                }

                $this->replaceMountedAction('reviewSupplierResponse', [
                    'file' => $relativePath,
                    'file_name' => (string) ($data['file_name'] ?? basename($relativePath)),
                    'preview' => $preview,
                ]);
            });
    }

    /**
     * Passo 2: revisão editável das NOSSAS linhas com o que foi lido. Só aqui a
     * resposta é gravada (documento + preços + status).
     */
    public function reviewSupplierResponseAction(): Action
    {
        return Action::make('reviewSupplierResponse')
            ->modalHeading(__('forms.labels.review_supplier_response'))
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitActionLabel(__('forms.labels.apply_supplier_response'))
            ->fillForm(fn (array $arguments) => (new SupplierQuotationResponseTarget($this->getOwnerRecord()))
                ->buildForm($arguments['preview'], []))
            ->schema(fn (array $arguments) => [
                Placeholder::make('summary')
                    ->hiddenLabel()
                    ->content(fn () => $this->supplierResponseSummary($arguments['preview'])),
                Section::make()
                    ->description(__('forms.helpers.supplier_response_header'))
                    ->columns(5)
                    ->schema([
                        Hidden::make('cabecalho.currency_code'),
                        TextInput::make('cabecalho.supplier_reference')->label(__('forms.labels.supplier_ref')),
                        DatePicker::make('cabecalho.valid_until')->label(__('forms.labels.valid_until')),
                        TextInput::make('cabecalho.lead_time_days')->label(__('forms.labels.lead_time_days'))->numeric()->minValue(0),
                        TextInput::make('cabecalho.moq')->label(__('forms.labels.moq'))->numeric()->minValue(0),
                        TextInput::make('cabecalho.incoterm')->label(__('forms.labels.incoterm')),
                    ]),
                Repeater::make('linhas')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make(__('forms.labels.description'))->width('26%'),
                        TableColumn::make(__('forms.labels.quantity'))->width('11%'),
                        TableColumn::make(__('forms.labels.document_line'))->width('20%'),
                        TableColumn::make(__('forms.labels.unit_cost').' ('.$this->getOwnerRecord()->currency_code.')')->width('11%'),
                        TableColumn::make(__('forms.labels.moq'))->width('8%'),
                        TableColumn::make(__('forms.labels.lead_time_days'))->width('9%'),
                        TableColumn::make(__('forms.labels.notes'))->width('15%'),
                    ])
                    ->schema([
                        Hidden::make('sq_item_id'),
                        TextInput::make('description')->disabled()->dehydrated(false)
                            ->extraInputAttributes(fn (?string $state) => ['title' => $state]),
                        TextInput::make('quantity')->disabled()->dehydrated(false),
                        TextInput::make('source')->disabled()->dehydrated(false)->placeholder('—')
                            ->extraInputAttributes(fn (?string $state) => ['title' => $state]),
                        TextInput::make('unit_cost')->numeric()->minValue(0)->step('0.0001'),
                        TextInput::make('moq')->numeric()->minValue(0),
                        TextInput::make('lead_time_days')->numeric()->minValue(0),
                        TextInput::make('notes'),
                    ])
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false),
            ])
            ->action(function (array $data, array $arguments, Action $action) {
                $base = realpath(Storage::disk('local')->path('ai-imports'));
                $real = realpath(Storage::disk('local')->path((string) ($arguments['file'] ?? '')));
                if ($base === false || $real === false || ! str_starts_with($real, $base.DIRECTORY_SEPARATOR)) {
                    Notification::make()->title(__('messages.supplier_response_invalid_file'))->danger()->send();

                    return;
                }

                try {
                    $result = app(ApplySupplierQuotationResponseAction::class)(
                        $this->getOwnerRecord(),
                        $data['linhas'] ?? [],
                        $data['cabecalho'] ?? [],
                        auth()->user(),
                        $real,
                        (string) ($arguments['file_name'] ?? basename($real)),
                    );
                } catch (\InvalidArgumentException|\Illuminate\Auth\Access\AuthorizationException $e) {
                    Notification::make()->title($e->getMessage() ?: __('messages.import_failed'))->danger()->send();
                    $action->halt(); // mantém a revisão aberta para corrigir
                }

                Storage::disk('local')->deleteDirectory(dirname((string) $arguments['file']));

                Notification::make()
                    ->title(__('messages.supplier_response_applied', [
                        'count' => $result['updated'],
                        'reference' => $this->getOwnerRecord()->reference,
                    ]))
                    ->success()
                    ->send();

                // Status e cabeçalho mudaram: a página dona precisa refletir.
                $this->redirect($this->pageClass::getUrl(['record' => $this->getOwnerRecord()]));
            });
    }

    private function failSupplierResponse(string $relativePath, string $message): void
    {
        Storage::disk('local')->deleteDirectory(dirname($relativePath));

        Notification::make()
            ->title(__('messages.supplier_response_extraction_failed'))
            ->body($message)
            ->danger()
            ->send();
    }

    /** @param  array<string,mixed>  $preview */
    private function supplierResponseSummary(array $preview): HtmlString
    {
        $lines = [e(__('forms.helpers.supplier_response_summary', [
            'quoted' => $preview['resumo']['cotados'] ?? 0,
            'total' => count($preview['linhas'] ?? []),
        ]))];

        if ($preview['currency_mismatch'] ?? false) {
            $lines[] = '<span style="color: rgb(220 38 38); font-weight: 600">'.e(__('forms.helpers.supplier_response_currency_mismatch', [
                'document' => $preview['cabecalho']['currency_code'] ?? '?',
                'quotation' => $this->getOwnerRecord()->currency_code,
            ])).'</span>';
        }

        $unmatched = array_filter($preview['nao_casados'] ?? [], fn ($row) => filled($row['descricao'] ?? null));
        if ($unmatched !== []) {
            $lines[] = e(__('forms.helpers.supplier_response_unmatched')).'<ul style="list-style: disc; margin-left: 1.25rem">'
                .implode('', array_map(fn ($row) => '<li>'.e($row['descricao'])
                    .(filled($row['valor'] ?? null) ? ' — '.e((string) $row['valor']) : '').'</li>', $unmatched))
                .'</ul>';
        }

        return new HtmlString(implode('<br>', $lines));
    }
}
