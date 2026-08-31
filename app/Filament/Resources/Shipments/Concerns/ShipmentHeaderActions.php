<?php

namespace App\Filament\Resources\Shipments\Concerns;

use App\Domain\Catalog\DataTransferObjects\NamingPreference;
use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Pdf\PdfGeneratorService;
use App\Domain\Infrastructure\Pdf\PdfRenderer;
use App\Domain\Infrastructure\Pdf\Templates\CommercialInvoicePdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\CustomPricePdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\PackingListPdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\ShipmentFinancialStatementPdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\ShipmentProformaInvoicePdfTemplate;
use App\Domain\Infrastructure\Services\DocumentService;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Reports\CommercialInvoiceExcelExporter;
use App\Domain\Logistics\Reports\PackingListExcelExporter;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use App\Filament\Concerns\HasDocumentNamingOptions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Concrete slot implementations for ShipmentResource pages.
 * Used by both ViewShipment and EditShipment for header parity.
 *
 * Shipment is a special case: it generates TWO document types (Packing List
 * and Commercial Invoice). The documents slot wraps both as nested groups
 * within a single outer ActionGroup labelled "Documents".
 */
trait ShipmentHeaderActions
{
    use HasDocumentNamingOptions;

    protected function documentsActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            ActionGroup::make([
                GeneratePdfAction::make(
                    templateClass: PackingListPdfTemplate::class,
                    label: 'Generate PDF',
                    formSchema: $this->packingListOptions(),
                )->name('generatePackingListPdf'),
                GeneratePdfAction::download(
                    documentType: 'packing_list_pdf',
                    label: 'Download PDF',
                )->name('downloadPackingListPdf'),
                GeneratePdfAction::preview(
                    templateClass: PackingListPdfTemplate::class,
                    label: 'Preview PDF',
                    formSchema: $this->packingListOptions(),
                )->name('previewPackingListPdf'),
                SendDocumentByEmailAction::make(
                    documentType: 'packing_list_pdf',
                    settingsKey: 'email_default_message_packing_list',
                    label: 'Send by Email',
                )->name('sendPackingListByEmail'),
                $this->packingListExcelAction(),
            ])
                ->label(__('forms.labels.packing_list'))
                ->icon('heroicon-o-clipboard-document-list')
                ->color('info'),

            ActionGroup::make([
                $this->commercialInvoiceGenerateAction(),
                GeneratePdfAction::download(
                    documentType: 'commercial_invoice_pdf',
                    label: 'Download PDF',
                )->name('downloadCommercialInvoicePdf'),
                GeneratePdfAction::preview(
                    templateClass: CommercialInvoicePdfTemplate::class,
                    label: 'Preview PDF',
                    formSchema: $this->commercialInvoiceOptions(),
                    beforeGenerate: fn ($record, array $data) => $this->handleSaveCustomPrices($record, $data),
                )->name('previewCommercialInvoicePdf'),
                SendDocumentByEmailAction::make(
                    documentType: 'commercial_invoice_pdf',
                    settingsKey: 'email_default_message_commercial_invoice',
                    label: 'Send by Email',
                )->name('sendCommercialInvoiceByEmail'),
                $this->commercialInvoiceExcelAction(),
            ])
                ->label(__('forms.labels.commercial_invoice'))
                ->icon('heroicon-o-document-currency-dollar')
                ->color('success'),

            ActionGroup::make([
                $this->shipmentProformaInvoiceGenerateAction(),
                GeneratePdfAction::download(
                    documentType: 'shipment_proforma_invoice_pdf',
                    label: 'Download PDF',
                )->name('downloadShipmentProformaInvoicePdf'),
                GeneratePdfAction::preview(
                    templateClass: ShipmentProformaInvoicePdfTemplate::class,
                    label: 'Preview PDF',
                    formSchema: $this->commercialInvoiceOptions(),
                    beforeGenerate: fn ($record, array $data) => $this->handleSaveCustomPrices($record, $data),
                )->name('previewShipmentProformaInvoicePdf'),
                SendDocumentByEmailAction::make(
                    documentType: 'shipment_proforma_invoice_pdf',
                    settingsKey: 'email_default_message_proforma_invoice',
                    label: 'Send by Email',
                )->name('sendShipmentProformaInvoiceByEmail'),
            ])
                ->label(__('forms.labels.proforma_invoice'))
                ->icon('heroicon-o-document-currency-dollar')
                ->color('warning'),

            ActionGroup::make([
                GeneratePdfAction::make(
                    templateClass: ShipmentFinancialStatementPdfTemplate::class,
                    label: 'Generate PDF',
                )->name('generateFinancialStatementPdf'),
                GeneratePdfAction::download(
                    documentType: 'shipment_financial_statement_pdf',
                    label: 'Download PDF',
                )->name('downloadFinancialStatementPdf'),
                GeneratePdfAction::preview(
                    templateClass: ShipmentFinancialStatementPdfTemplate::class,
                    label: 'Preview PDF',
                )->name('previewFinancialStatementPdf'),
                SendDocumentByEmailAction::make(
                    documentType: 'shipment_financial_statement_pdf',
                    settingsKey: 'email_default_message_financial_statement',
                    label: 'Send by Email',
                )->name('sendFinancialStatementByEmail'),
            ])
                ->label(__('forms.labels.financial_statement'))
                ->icon('heroicon-o-banknotes')
                ->color('gray'),
        ])
            ->label(__('forms.labels.documents'))
            ->icon('heroicon-o-document-text')
            ->color('info')
            ->button();
    }

    protected function statusActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            $this->transitionStatusAction(),
        ])
            ->label(__('forms.labels.status'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->button();
    }

    protected function transitionStatusAction(): Action
    {
        return Action::make('transitionStatus')
            ->label(__('forms.labels.change_status'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn () => ! empty($this->record->getAllowedNextStatuses()))
            ->form(function () {
                $allowed = $this->record->getAllowedNextStatuses();
                $options = collect($allowed)->mapWithKeys(function ($status) {
                    $enum = ShipmentStatus::from($status);

                    return [$status => $enum->getLabel()];
                })->toArray();

                return [
                    Select::make('new_status')
                        ->label(__('forms.labels.new_status'))
                        ->options($options)
                        ->required(),
                    Textarea::make('notes')
                        ->label(__('forms.labels.transition_notes'))
                        ->rows(2)
                        ->maxLength(1000),
                ];
            })
            ->action(function (array $data) {
                try {
                    app(TransitionStatusAction::class)->execute(
                        $this->record,
                        ShipmentStatus::from($data['new_status']),
                        $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title(__('messages.status_changed_to').' '.ShipmentStatus::from($data['new_status'])->getLabel())
                        ->success()
                        ->send();

                    if (method_exists($this, 'refreshFormData')) {
                        $this->refreshFormData(['status']);
                    }
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.status_transition_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function commercialInvoiceOptions(): array
    {
        return [
            Toggle::make('include_freight')
                ->label(__('forms.labels.include_freight'))
                ->default(false)
                ->live(),
            Toggle::make('use_custom_prices')
                ->label(__('forms.labels.use_client_custom_prices'))
                ->default(true)
                ->live()
                ->helperText(__('forms.helpers.use_client_custom_prices')),
            Select::make('manufacturer_ids')
                ->label('Manufacturer(s)')
                ->multiple()
                ->options(fn () => $this->getManufacturerOptionsForShipment())
                ->default(fn () => $this->getDefaultManufacturerIds())
                ->helperText('Select the manufacturers to display on the document')
                ->live(),
            Checkbox::make('use_formula')
                ->label(__('forms.labels.apply_price_formula'))
                ->visible(fn (Get $get) => ! $get('use_custom_prices'))
                ->live()
                ->helperText(__('forms.helpers.apply_formula_to_recalculate_prices')),
            TextInput::make('price_formula')
                ->label(__('forms.labels.formula'))
                ->placeholder('e.g. *0.70, *1.15, +10, -5')
                ->visible(fn (Get $get) => ! $get('use_custom_prices') && $get('use_formula'))
                ->requiredIf('use_formula', true)
                ->regex('/^[*\/+\-]\s*[0-9]*\.?[0-9]+$/')
                ->helperText(__('forms.helpers.formula_operators'))
                ->live(onBlur: true),
            Checkbox::make('save_as_custom_price')
                ->label(__('forms.labels.save_as_custom_price'))
                ->visible(fn (Get $get) => ! $get('use_custom_prices') && $get('use_formula'))
                ->helperText(__('forms.helpers.save_formula_prices_to_custom_price')),
            $this->namingPreferenceSection(),
        ];
    }

    /**
     * Agrupados à parte dos seis controles acima (que já lotam o modal)
     * porque respondem a uma pergunta diferente — não "o que mostrar" mas
     * "de onde tirar o nome". A seção em si (os quatro controles) é
     * compartilhada com PO e RFQ via HasDocumentNamingOptions; só a
     * derivação dos defaults é específica do Shipment.
     *
     * Os defaults vêm de namingPreferenceDefaults(), nunca das colunas cruas
     * da empresa: uma filial com as colunas em branco herda da matriz, e ler
     * a coluna diretamente mostraria "Contraparte" no modal enquanto o
     * documento sairia com a nomenclatura do sistema.
     */
    protected function namingPreferenceSection(): Section
    {
        return $this->documentNamingSection($this->namingPreferenceDefaults());
    }

    /**
     * Packing List (generate, preview e Excel) não tinha NENHUM formSchema —
     * as três ações eram surdas ao toggle mesmo depois do resolver honrar a
     * preferência (05dbd053). Mesmos defaults do Commercial Invoice: mesmo
     * shipment, mesmo getDocumentClient()/company.
     */
    protected function packingListOptions(): array
    {
        return [
            $this->namingPreferenceSection(),
        ];
    }

    /**
     * Mesma precedência do CommercialInvoicePdfTemplate: endereço do
     * documento (filial, senão matriz) resolvido por getDocumentClient(),
     * matriz como fallback de herança para fromCompany(). getRecord() pode
     * ser null enquanto o schema do modal ainda está sendo montado;
     * fromCompany(null, null) já devolve o default histórico nesse caso.
     */
    protected function namingPreferenceDefaults(): NamingPreference
    {
        $record = $this->getRecord();

        return NamingPreference::fromCompany(
            $record?->getDocumentClient(),
            $record?->company,
        );
    }

    protected function getManufacturerOptionsForShipment(): array
    {
        $record = $this->getRecord();
        $record->loadMissing('items.proformaInvoiceItem.product.companies.companyRoles');

        $companyIds = $record->items
            ->map(fn ($item) => $item->proformaInvoiceItem?->product)
            ->filter()
            ->flatMap(fn ($product) => $product->companies
                ->filter(fn ($company) => $company->pivot->role === 'supplier' || $company->pivot->role === 'manufacturer')
            )
            ->pluck('id')
            ->unique()
            ->toArray();

        return Company::query()
            ->where(function ($query) use ($companyIds) {
                $query->whereIn('id', $companyIds)
                    ->orWhereHas('companyRoles', fn ($q) => $q->whereIn('role', [
                        CompanyRole::SUPPLIER,
                        CompanyRole::MANUFACTURER,
                    ]));
            })
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    protected function getDefaultManufacturerIds(): array
    {
        $record = $this->getRecord();
        $record->loadMissing('items.proformaInvoiceItem.product.companies');

        return $record->items
            ->map(fn ($item) => $item->proformaInvoiceItem?->product)
            ->filter()
            ->flatMap(fn ($product) => $product->companies
                ->filter(fn ($company) => $company->pivot->role === 'supplier' || $company->pivot->role === 'manufacturer')
            )
            ->pluck('id')
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Commercial Invoice em Excel. Usa o mesmo formulário de opções do PDF
     * (custom prices, fórmula, frete) para que os dois formatos saiam com os
     * mesmos valores; o arquivo é baixado direto, sem versionar como Document.
     */
    protected function commercialInvoiceExcelAction(): Action
    {
        return Action::make('exportCommercialInvoiceExcel')
            ->label(__('forms.labels.export_excel'))
            ->icon('heroicon-o-table-cells')
            ->color('success')
            ->visible(fn () => auth()->user()?->can('generate-documents'))
            ->modalHeading(__('forms.labels.export_excel').' — '.__('forms.labels.commercial_invoice'))
            ->modalSubmitActionLabel(__('forms.labels.export_excel'))
            ->form($this->commercialInvoiceOptions())
            ->action(function (array $data) {
                $record = $this->getRecord();
                $this->handleSaveCustomPrices($record, $data);

                $path = (new CommercialInvoiceExcelExporter)->export($record, $data);

                return response()->download($path)->deleteFileAfterSend();
            });
    }

    protected function packingListExcelAction(): Action
    {
        return Action::make('exportPackingListExcel')
            ->label(__('forms.labels.export_excel'))
            ->icon('heroicon-o-table-cells')
            ->color('info')
            ->visible(fn () => auth()->user()?->can('generate-documents'))
            ->modalHeading(__('forms.labels.export_excel').' — '.__('forms.labels.packing_list'))
            ->modalSubmitActionLabel(__('forms.labels.export_excel'))
            ->form($this->packingListOptions())
            ->action(function (array $data) {
                $path = (new PackingListExcelExporter)->export($this->getRecord(), $data);

                return response()->download($path)->deleteFileAfterSend();
            });
    }

    protected function commercialInvoiceGenerateAction(): Action
    {
        return Action::make('generateCommercialInvoicePdf')
            ->label('Generate PDF')
            ->icon('heroicon-o-document-arrow-down')
            ->color('success')
            ->visible(fn () => auth()->user()?->can('generate-documents'))
            ->requiresConfirmation()
            ->modalHeading('Generate Commercial Invoice PDF')
            ->modalDescription('This will generate a new PDF version. If a previous version exists, it will be archived.')
            ->modalSubmitActionLabel('Generate')
            ->form($this->commercialInvoiceOptions())
            ->action(function (array $data) {
                try {
                    $record = $this->getRecord();
                    $this->handleSaveCustomPrices($record, $data);

                    $template = new CommercialInvoicePdfTemplate($record, 'en', $data);
                    $service = new PdfGeneratorService(
                        new PdfRenderer,
                        new DocumentService,
                    );

                    $document = $service->generate($template);

                    Notification::make()
                        ->title('PDF Generated')
                        ->body("Version {$document->version} created: {$document->name}")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title('PDF Generation Failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function shipmentProformaInvoiceGenerateAction(): Action
    {
        return Action::make('generateShipmentProformaInvoicePdf')
            ->label('Generate PDF')
            ->icon('heroicon-o-document-arrow-down')
            ->color('warning')
            ->visible(fn () => auth()->user()?->can('generate-documents'))
            ->requiresConfirmation()
            ->modalHeading('Generate Proforma Invoice PDF')
            ->modalDescription('This will generate a new PDF version. If a previous version exists, it will be archived.')
            ->modalSubmitActionLabel('Generate')
            ->form($this->commercialInvoiceOptions())
            ->action(function (array $data) {
                try {
                    $record = $this->getRecord();
                    $this->handleSaveCustomPrices($record, $data);

                    $template = new ShipmentProformaInvoicePdfTemplate($record, 'en', $data);
                    $service = new PdfGeneratorService(
                        new PdfRenderer,
                        new DocumentService,
                    );

                    $document = $service->generate($template);

                    Notification::make()
                        ->title('PDF Generated')
                        ->body("Version {$document->version} created: {$document->name}")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title('PDF Generation Failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function handleSaveCustomPrices($record, array $data): void
    {
        $useFormula = $data['use_formula'] ?? false;
        $formula = $useFormula ? ($data['price_formula'] ?? null) : null;
        $saveAsCustom = $data['save_as_custom_price'] ?? false;

        if (! $saveAsCustom || ! $formula) {
            return;
        }

        $record->loadMissing('items.proformaInvoiceItem.product');
        $clientId = $record->company_id;

        if (! $clientId) {
            return;
        }

        foreach ($record->items as $item) {
            $productId = $item->proformaInvoiceItem?->product_id;

            if (! $productId) {
                continue;
            }

            $calculatedPrice = CustomPricePdfTemplate::applyFormula($item->unit_price, $formula);

            CompanyProduct::updateOrCreate(
                [
                    'product_id' => $productId,
                    'company_id' => $clientId,
                    'role' => 'client',
                ],
                [
                    'custom_price' => $calculatedPrice,
                ],
            );
        }
    }
}
