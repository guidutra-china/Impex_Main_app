<?php

namespace App\Filament\Resources\Quotations\Concerns;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Pdf\Templates\QuotationPdfTemplate;
use App\Domain\Inquiries\Actions\AdvanceInquiryToQuotedAction;
use App\Domain\ProformaInvoices\Actions\SyncProformaInvoiceFromQuotationAction;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Concrete slot implementations for QuotationResource pages.
 * Used by both ViewQuotation and EditQuotation to guarantee header parity.
 */
trait QuotationHeaderActions
{
    protected function documentsActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            GeneratePdfAction::make(
                templateClass: QuotationPdfTemplate::class,
                label: 'Generate PDF',
                formSchema: [
                    Checkbox::make('with_images')
                        ->label('Include product photos')
                        ->default(true)
                        ->helperText('If checked, each line item will display the product photo and the filename will include "PIC".'),
                ],
            ),
            GeneratePdfAction::download(
                documentType: 'quotation_pdf',
                label: 'Download PDF',
            ),
            GeneratePdfAction::preview(
                templateClass: QuotationPdfTemplate::class,
                label: 'Preview PDF',
                formSchema: [
                    Checkbox::make('with_images')
                        ->label('Include product photos')
                        ->default(true)
                        ->live()
                        ->helperText('Preview the PDF with product photos in each line item.'),
                ],
            ),
            SendDocumentByEmailAction::make(
                documentType: 'quotation_pdf',
                settingsKey: 'email_default_message_quotation',
                label: 'Send by Email',
            ),
        ])
            ->label(__('forms.labels.documents'))
            ->icon('heroicon-o-document-text')
            ->color('info')
            ->button();
    }

    protected function workflowActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            $this->convertToProformaInvoiceAction(),
        ])
            ->label(__('forms.labels.workflow'))
            ->icon('heroicon-o-arrows-right-left')
            ->color('primary')
            ->button();
    }

    protected function statusActionGroup(): ?Action
    {
        $action = $this->transitionStatusAction();

        return empty($this->record->getAllowedNextStatuses()) ? null : $action;
    }

    protected function versionActionGroup(): ?Action
    {
        return $this->createVersionAction();
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
                    $enum = QuotationStatus::from($status);

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
                        QuotationStatus::from($data['new_status']),
                        $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title(__('messages.status_changed_to').' '.QuotationStatus::from($data['new_status'])->getLabel())
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

    protected function createVersionAction(): Action
    {
        return Action::make('createVersion')
            ->label(__('forms.labels.save_version'))
            ->tooltip(__('forms.helpers.save_version_explainer'))
            ->icon('heroicon-o-clock')
            ->color('info')
            ->button()
            ->requiresConfirmation()
            ->modalHeading(__('forms.modals.save_version_heading'))
            ->modalDescription(__('forms.modals.save_version_description'))
            ->form([
                Textarea::make('change_notes')
                    ->label(__('forms.labels.change_notes'))
                    ->placeholder(__('forms.placeholders.describe_what_changed_in_this_version'))
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data) {
                try {
                    /** @var Quotation $quotation */
                    $quotation = $this->record;
                    $savedVersion = $quotation->saveVersion(
                        $data['change_notes'] ?? null,
                        auth()->id(),
                    );

                    Notification::make()
                        ->title(__('messages.version_saved', ['version' => $savedVersion]))
                        ->body(__('messages.snapshot_created', ['version' => $savedVersion + 1]))
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.version_save_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }

                if (method_exists($this, 'refreshFormData')) {
                    $this->refreshFormData(['version']);
                }
            });
    }

    protected function convertToProformaInvoiceAction(): Action
    {
        $linkedPi = app(SyncProformaInvoiceFromQuotationAction::class)->findLinkedPi($this->record);
        $hasDraftPi = $linkedPi !== null && $linkedPi->status === ProformaInvoiceStatus::DRAFT;
        $isBlocked = $linkedPi !== null && ! $hasDraftPi;

        return Action::make('convertToProformaInvoice')
            ->label($hasDraftPi
                ? __('forms.labels.update_pi', ['reference' => $linkedPi->reference])
                : __('forms.labels.convert_to_pi'))
            ->icon($hasDraftPi ? 'heroicon-o-arrow-path' : 'heroicon-o-document-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading($hasDraftPi
                ? __('forms.labels.update_pi', ['reference' => $linkedPi->reference])
                : __('forms.labels.convert_to_pi'))
            ->modalDescription($hasDraftPi
                ? __('forms.helpers.regenerate_quotation_pi_description')
                : __('forms.helpers.convert_quotation_to_pi_description'))
            ->modalSubmitActionLabel($hasDraftPi
                ? __('forms.labels.update_proforma_invoice')
                : __('forms.labels.create_proforma_invoice'))
            ->visible(fn () => auth()->user()?->can('create-proforma-invoices')
                && $this->record->items()->count() > 0)
            ->disabled($isBlocked)
            ->tooltip($isBlocked
                ? __('messages.pi_regeneration_blocked', ['reference' => $linkedPi->reference])
                : null)
            ->action(function () {
                try {
                    $quotation = $this->record;

                    ['pi' => $pi, 'created' => $created] = app(SyncProformaInvoiceFromQuotationAction::class)
                        ->execute($quotation);

                    if ($created) {
                        app(AdvanceInquiryToQuotedAction::class)->execute($pi->inquiry);
                    }

                    Notification::make()
                        ->title(($created ? __('messages.pi_created') : __('messages.pi_updated')).': '.$pi->reference)
                        ->success()
                        ->send();

                    return redirect(ProformaInvoiceResource::getUrl('edit', ['record' => $pi]));
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title(__('messages.error_creating_pi'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
