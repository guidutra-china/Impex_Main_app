<?php

namespace App\Filament\Resources\Finance\CreditNotes\Pages;

use App\Domain\Financial\Actions\IssueCreditNoteAction;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\CreditNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\CreditNoteLineItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Resources\Finance\CreditNotes\CreditNoteResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewCreditNote extends ViewRecord
{
    protected static string $resource = CreditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->autoPopulateAction(),
            $this->issueAction(),
            $this->cancelAction(),
            EditAction::make()
                ->visible(fn () => $this->record->status === CreditNoteStatus::DRAFT),
            Action::make('backToList')
                ->label(__('forms.labels.back_to_list'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(CreditNoteResource::getUrl('index')),
        ];
    }

    protected function issueAction(): Action
    {
        return Action::make('issue')
            ->label(__('forms.labels.issue'))
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.issue_credit_note'))
            ->modalDescription(fn () => __('forms.labels.issue_credit_note_confirmation', [
                'reference' => $this->record->reference,
                'currency' => $this->record->currency_code,
                'amount' => Money::format($this->record->total_amount),
            ]))
            ->visible(fn () => $this->record->status === CreditNoteStatus::DRAFT
                && $this->record->lineItems()->count() > 0)
            ->action(function () {
                try {
                    app(IssueCreditNoteAction::class)->execute($this->record);
                    Notification::make()->title(__('messages.credit_note_issued'))->success()->send();
                    $this->refreshFormData(['status', 'issued_at']);
                } catch (\RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    protected function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('forms.labels.cancel'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.cancel_credit_note'))
            ->visible(fn () => in_array($this->record->status, [
                CreditNoteStatus::DRAFT,
                CreditNoteStatus::ISSUED,
            ]) && $this->record->consumed_amount === 0)
            ->action(function () {
                $this->record->update(['status' => CreditNoteStatus::CANCELLED]);
                Notification::make()->title(__('messages.credit_note_cancelled'))->warning()->send();
                $this->refreshFormData(['status']);
            });
    }

    /**
     * Pull unbilled supplier-billable costs (quality deductions, claims)
     * into this DRAFT credit note as line items. Only meaningful for
     * supplier credit notes — client credits are typed manually.
     */
    protected function autoPopulateAction(): Action
    {
        return Action::make('autoPopulate')
            ->label(__('forms.labels.auto_populate_from_costs'))
            ->icon('heroicon-o-sparkles')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.auto_populate_line_items'))
            ->modalDescription(__('forms.labels.auto_populate_description'))
            ->visible(fn () => $this->record->status === CreditNoteStatus::DRAFT
                && $this->record->party_type === PartyType::SUPPLIER)
            ->action(function () {
                $costs = AdditionalCost::query()
                    ->where('billable_to', BillableTo::SUPPLIER)
                    ->where('supplier_company_id', $this->record->company_id)
                    ->whereNot('status', AdditionalCostStatus::WAIVED->value)
                    ->whereDoesntHave('creditNoteLineItems')
                    ->with('costable')
                    ->get();

                if ($costs->isEmpty()) {
                    Notification::make()
                        ->title(__('messages.no_unbilled_costs'))
                        ->warning()
                        ->send();

                    return;
                }

                $added = 0;
                foreach ($costs as $cost) {
                    $costable = $cost->costable;
                    $piId = $costable instanceof ProformaInvoice ? $costable->id : null;
                    $shipmentId = $costable instanceof Shipment ? $costable->id : null;

                    $lineAmount = $cost->amount_in_document_currency ?: $cost->amount;
                    $lineCurrency = $cost->amount_in_document_currency
                        ? ($costable?->currency_code ?? $cost->currency_code)
                        : $cost->currency_code;

                    CreditNoteLineItem::create([
                        'credit_note_id' => $this->record->id,
                        'additional_cost_id' => $cost->id,
                        'proforma_invoice_id' => $piId,
                        'shipment_id' => $shipmentId,
                        'description' => $cost->cost_type->getLabel().' — '.($cost->description ?: ($costable?->reference ?? '')),
                        'amount' => $lineAmount,
                        'currency_code' => $lineCurrency,
                    ]);
                    $added++;
                }

                $this->record->recalculateTotal();

                Notification::make()
                    ->title(__('messages.costs_added_as_line_items', ['count' => $added]))
                    ->success()
                    ->send();

                $this->refreshFormData(['total_amount']);
            });
    }
}
