<?php

namespace App\Filament\Resources\ProformaInvoices\Pages;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Pdf\PdfGeneratorService;
use App\Domain\ProformaInvoices\Actions\CancelProformaInvoiceAction;
use App\Domain\ProformaInvoices\Actions\SyncClientProductPricesAction;
use App\Domain\Infrastructure\Pdf\PdfRenderer;
use App\Domain\Infrastructure\Pdf\Templates\CustomPricePdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\ProformaInvoicePdfTemplate;
use App\Domain\Infrastructure\Services\DocumentService;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Infrastructure\Support\Money;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;

class EditProformaInvoice extends EditRecord
{
    protected static string $resource = ProformaInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GeneratePdfAction::make(
                templateClass: ProformaInvoicePdfTemplate::class,
                label: 'Generate PDF',
            ),
            GeneratePdfAction::download(
                documentType: 'proforma_invoice_pdf',
                label: 'Download PDF',
            ),
            GeneratePdfAction::preview(
                templateClass: ProformaInvoicePdfTemplate::class,
                label: 'Preview PDF',
            ),
            SendDocumentByEmailAction::make(
                documentType: 'proforma_invoice_pdf',
                label: 'Send by Email',
            ),
            $this->customPricePdfAction(),
            $this->transitionStatusAction(),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    protected function customPricePdfAction(): Action
    {
        return Action::make('customPricePdf')
            ->label('Custom Price PDF')
            ->icon('heroicon-o-document-currency-dollar')
            ->color('gray')
            ->visible(fn () => auth()->user()?->can('generate-documents'))
            ->form([
                Checkbox::make('hide_commission')
                    ->label('Hide Service Fee')
                    ->helperText('If checked, the Service Fee line will not appear in the PDF.'),
                Checkbox::make('use_formula')
                    ->label('Apply price formula')
                    ->live()
                    ->helperText('Apply a formula to recalculate all unit prices (e.g. *0.70 for 30% discount).'),
                TextInput::make('price_formula')
                    ->label('Formula')
                    ->placeholder('e.g. *0.70, *1.15, +10, -5')
                    ->visible(fn (Get $get) => $get('use_formula'))
                    ->requiredIf('use_formula', true)
                    ->regex('/^[*\/+\-]\s*[0-9]*\.?[0-9]+$/')
                    ->helperText('Operators: * (multiply), / (divide), + (add), - (subtract). Value in major units for +/-.'),
                Checkbox::make('save_as_custom_price')
                    ->label('Save calculated prices as Custom Price')
                    ->visible(fn (Get $get) => $get('use_formula'))
                    ->helperText('If checked, the formula-calculated prices will be saved to each product\'s custom price for this client.'),
            ])
            ->action(function (array $data) {
                try {
                    $formula = ($data['use_formula'] ?? false) ? ($data['price_formula'] ?? null) : null;

                    if (($data['save_as_custom_price'] ?? false) && $formula) {
                        $this->saveFormulaAsCustomPrices($this->record, $formula);
                    }

                    $template = new CustomPricePdfTemplate(
                        model: $this->record,
                        hideCommission: $data['hide_commission'] ?? false,
                        priceFormula: $formula,
                    );
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
                        ->title('Custom Price PDF Failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
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
                    $enum = ProformaInvoiceStatus::from($status);
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
                    $newStatus = ProformaInvoiceStatus::from($data['new_status']);

                    if ($newStatus === ProformaInvoiceStatus::CANCELLED) {
                        app(CancelProformaInvoiceAction::class)->execute(
                            $this->record,
                            $data['notes'] ?? null,
                        );
                    } else {
                        $sideEffects = null;

                        if ($newStatus === ProformaInvoiceStatus::CONFIRMED) {
                            $sideEffects = function ($pi) {
                                app(SyncClientProductPricesAction::class)->execute($pi);
                            };
                        }

                        app(TransitionStatusAction::class)->execute(
                            $this->record,
                            $newStatus,
                            $data['notes'] ?? null,
                            sideEffects: $sideEffects,
                        );
                    }

                    Notification::make()
                        ->title(__('messages.status_changed_to') . ' ' . $newStatus->getLabel())
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

    protected function saveFormulaAsCustomPrices($pi, string $formula): void
    {
        $pi->loadMissing('items.product');
        $clientId = $pi->company_id;

        if (! $clientId) {
            return;
        }

        foreach ($pi->items as $item) {
            if (! $item->product_id) {
                continue;
            }

            $calculatedPrice = CustomPricePdfTemplate::applyFormula($item->unit_price, $formula);

            CompanyProduct::updateOrCreate(
                [
                    'product_id' => $item->product_id,
                    'company_id' => $clientId,
                    'role' => 'client',
                ],
                [
                    'custom_price' => $calculatedPrice,
                ],
            );
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
