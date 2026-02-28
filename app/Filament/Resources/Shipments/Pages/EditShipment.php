<?php

namespace App\Filament\Resources\Shipments\Pages;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Pdf\Templates\CommercialInvoicePdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\PackingListPdfTemplate;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Resources\Shipments\ShipmentResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditShipment extends EditRecord
{
    protected static string $resource = ShipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                GeneratePdfAction::make(
                    templateClass: PackingListPdfTemplate::class,
                    label: 'Generate Packing List',
                )->name('generatePackingListPdf'),
                GeneratePdfAction::download(
                    documentType: 'packing_list_pdf',
                    label: 'Download Packing List',
                )->name('downloadPackingListPdf'),
                GeneratePdfAction::preview(
                    templateClass: PackingListPdfTemplate::class,
                    label: 'Preview Packing List',
                )->name('previewPackingListPdf'),
            ])
                ->label(__('forms.labels.packing_list_pdf'))
                ->icon('heroicon-o-clipboard-document-list')
                ->color('info'),

            ActionGroup::make([
                GeneratePdfAction::make(
                    templateClass: CommercialInvoicePdfTemplate::class,
                    label: 'Generate Commercial Invoice',
                )->name('generateCommercialInvoicePdf'),
                GeneratePdfAction::download(
                    documentType: 'commercial_invoice_pdf',
                    label: 'Download Commercial Invoice',
                )->name('downloadCommercialInvoicePdf'),
                GeneratePdfAction::preview(
                    templateClass: CommercialInvoicePdfTemplate::class,
                    label: 'Preview Commercial Invoice',
                )->name('previewCommercialInvoicePdf'),
            ])
                ->label(__('forms.labels.commercial_invoice_pdf'))
                ->icon('heroicon-o-document-currency-dollar')
                ->color('success'),

            $this->transitionStatusAction(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
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
