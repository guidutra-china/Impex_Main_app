<?php

namespace App\Filament\Resources\SupplierQuotations\Concerns;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Excel\Templates\RfqExcelTemplate;
use App\Domain\Infrastructure\Pdf\Templates\RfqPdfTemplate;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Filament\Actions\GenerateExcelAction;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;

/**
 * Concrete slot implementations for SupplierQuotation pages (View + Edit).
 *
 * Both ViewSupplierQuotation and EditSupplierQuotation use this trait
 * (alongside HasOperationsHeaderActions) to render an identical header.
 */
trait SupplierQuotationHeaderActions
{
    protected function documentsActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            GeneratePdfAction::make(
                templateClass: RfqPdfTemplate::class,
                label: 'Generate RFQ',
                icon: 'heroicon-o-document-arrow-down',
                formSchema: [
                    Toggle::make('show_target_price')
                        ->label('Include Target Price')
                        ->helperText('Show the client\'s target price in the RFQ document')
                        ->default(false),
                ],
            ),
            GeneratePdfAction::download(
                documentType: 'rfq_pdf',
                label: 'Download RFQ',
            ),
            GeneratePdfAction::preview(
                templateClass: RfqPdfTemplate::class,
                label: 'Preview RFQ',
                formSchema: [
                    Toggle::make('show_target_price')
                        ->label('Include Target Price')
                        ->helperText('Show the client\'s target price in the RFQ document')
                        ->live()
                        ->default(false),
                ],
            ),
            GenerateExcelAction::make(
                templateClass: RfqExcelTemplate::class,
                label: 'Generate RFQ Excel',
                icon: 'heroicon-o-table-cells',
                formSchema: [
                    Toggle::make('show_target_price')
                        ->label('Include Target Price')
                        ->default(false),
                ],
            ),
            GenerateExcelAction::downloadStored(
                documentType: 'rfq_excel',
                label: 'Download RFQ Excel',
            ),
            SendDocumentByEmailAction::make(
                documentType: 'rfq_pdf',
                settingsKey: 'email_default_message_rfq',
                label: 'Send RFQ PDF',
            ),
            SendDocumentByEmailAction::make(
                documentType: 'rfq_excel',
                settingsKey: 'email_default_message_rfq',
                label: 'Send RFQ Excel',
                icon: 'heroicon-o-envelope',
            ),
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
            ->button()
            ->visible(fn () => ! empty($this->record->getAllowedNextStatuses()));
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
                    $enum = SupplierQuotationStatus::from($status);

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
                        SupplierQuotationStatus::from($data['new_status']),
                        $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title(__('messages.status_changed_to') . ' ' . SupplierQuotationStatus::from($data['new_status'])->getLabel())
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
