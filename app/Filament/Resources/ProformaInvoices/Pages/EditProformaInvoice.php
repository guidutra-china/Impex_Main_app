<?php

namespace App\Filament\Resources\ProformaInvoices\Pages;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Pdf\PdfGeneratorService;
use App\Domain\ProformaInvoices\Actions\CancelProformaInvoiceAction;
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
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

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
            ])
            ->action(function (array $data) {
                try {
                    $template = new CustomPricePdfTemplate(
                        model: $this->record,
                        hideCommission: $data['hide_commission'] ?? false,
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
                        app(TransitionStatusAction::class)->execute(
                            $this->record,
                            $newStatus,
                            $data['notes'] ?? null,
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

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
