<?php

namespace App\Filament\Resources\Shipments\Pages;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Pdf\PdfGeneratorService;
use App\Domain\Infrastructure\Pdf\PdfRenderer;
use App\Domain\Infrastructure\Pdf\Templates\CommercialInvoicePdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\CustomPricePdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\PackingListPdfTemplate;
use App\Domain\Infrastructure\Services\DocumentService;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use App\Filament\Resources\Shipments\ShipmentResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;

class EditShipment extends EditRecord
{
    protected static string $resource = ShipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                GeneratePdfAction::make(
                    templateClass: PackingListPdfTemplate::class,
                    label: 'Generate PDF',
                )->name('generatePackingListPdf'),
                GeneratePdfAction::download(
                    documentType: 'packing_list_pdf',
                    label: 'Download PDF',
                )->name('downloadPackingListPdf'),
                GeneratePdfAction::preview(
                    templateClass: PackingListPdfTemplate::class,
                    label: 'Preview PDF',
                )->name('previewPackingListPdf'),
                SendDocumentByEmailAction::make(
                    documentType: 'packing_list_pdf',
                    label: 'Send by Email',
                )->name('sendPackingListByEmail'),
            ])
                ->label(__('forms.labels.packing_list'))
                ->icon('heroicon-o-clipboard-document-list')
                ->color('info')
                ->button(),

            ActionGroup::make([
                $this->commercialInvoiceGenerateAction(),
                GeneratePdfAction::download(
                    documentType: 'commercial_invoice_pdf',
                    label: 'Download PDF',
                )->name('downloadCommercialInvoicePdf'),
                $this->commercialInvoicePreviewAction(),
                SendDocumentByEmailAction::make(
                    documentType: 'commercial_invoice_pdf',
                    label: 'Send by Email',
                )->name('sendCommercialInvoiceByEmail'),
            ])
                ->label(__('forms.labels.commercial_invoice'))
                ->icon('heroicon-o-document-currency-dollar')
                ->color('success')
                ->button(),

            $this->transitionStatusAction(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected static function commercialInvoiceOptions(): array
    {
        return [
            Toggle::make('include_freight')
                ->label(__('forms.labels.include_freight'))
                ->default(false),
            Checkbox::make('use_formula')
                ->label(__('forms.labels.apply_price_formula'))
                ->live()
                ->helperText(__('forms.helpers.apply_formula_to_recalculate_prices')),
            TextInput::make('price_formula')
                ->label(__('forms.labels.formula'))
                ->placeholder('e.g. *0.70, *1.15, +10, -5')
                ->visible(fn (Get $get) => $get('use_formula'))
                ->requiredIf('use_formula', true)
                ->regex('/^[*\/+\-]\s*[0-9]*\.?[0-9]+$/')
                ->helperText(__('forms.helpers.formula_operators')),
            Checkbox::make('save_as_custom_price')
                ->label(__('forms.labels.save_as_custom_price'))
                ->visible(fn (Get $get) => $get('use_formula'))
                ->helperText(__('forms.helpers.save_formula_prices_to_custom_price')),
        ];
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
            ->form(self::commercialInvoiceOptions())
            ->action(function (array $data) {
                try {
                    $record = $this->getRecord();
                    $this->handleSaveCustomPrices($record, $data);

                    $template = new CommercialInvoicePdfTemplate($record, 'en', $data);
                    $service = new PdfGeneratorService(
                        new PdfRenderer(),
                        new DocumentService(),
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

    protected function commercialInvoicePreviewAction(): Action
    {
        return Action::make('previewCommercialInvoicePdf')
            ->label('Preview PDF')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->visible(fn () => auth()->user()?->can('generate-documents'))
            ->form(self::commercialInvoiceOptions())
            ->action(function (array $data) {
                try {
                    $record = $this->getRecord();
                    $this->handleSaveCustomPrices($record, $data);

                    $template = new CommercialInvoicePdfTemplate($record, 'en', $data);
                    $service = new PdfGeneratorService(
                        new PdfRenderer(),
                        new DocumentService(),
                    );

                    $content = $service->preview($template);

                    return response()->streamDownload(
                        function () use ($content) {
                            echo $content;
                        },
                        $template->getFilename(),
                        [
                            'Content-Type' => 'application/pdf',
                            'Content-Disposition' => 'inline; filename="' . $template->getFilename() . '"',
                        ],
                    );
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title('Preview Failed')
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
                        ->title(__('messages.status_changed_to') . ' ' . ShipmentStatus::from($data['new_status'])->getLabel())
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.status_transition_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
