<?php

namespace App\Filament\Resources\Finance\DebitNotes\Pages;

use App\Domain\Financial\Actions\IssueDebitNoteAction;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Resources\Finance\DebitNotes\DebitNoteResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewDebitNote extends ViewRecord
{
    protected static string $resource = DebitNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->autoPopulateAction(),
            $this->issueAction(),
            $this->cancelAction(),
            EditAction::make()
                ->visible(fn () => $this->record->status === DebitNoteStatus::DRAFT),
            Action::make('backToList')
                ->label(__('forms.labels.back_to_list'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(DebitNoteResource::getUrl('index')),
        ];
    }

    protected function issueAction(): Action
    {
        return Action::make('issue')
            ->label(__('forms.labels.issue'))
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.issue_debit_note'))
            ->modalDescription(fn () => __('forms.labels.issue_debit_note_confirmation', [
                'reference' => $this->record->reference,
                'currency' => $this->record->currency_code,
                'amount' => Money::format($this->record->total_amount),
            ]))
            ->visible(fn () => $this->record->status === DebitNoteStatus::DRAFT
                && $this->record->lineItems()->count() > 0)
            ->action(function () {
                try {
                    app(IssueDebitNoteAction::class)->execute($this->record);
                    Notification::make()->title(__('messages.debit_note_issued'))->success()->send();
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
            ->modalHeading(__('forms.labels.cancel_debit_note'))
            ->visible(fn () => in_array($this->record->status, [
                DebitNoteStatus::DRAFT,
                DebitNoteStatus::ISSUED,
            ]))
            ->action(function () {
                $this->record->update(['status' => DebitNoteStatus::CANCELLED]);
                Notification::make()->title(__('messages.debit_note_cancelled'))->warning()->send();
                $this->refreshFormData(['status']);
            });
    }

    protected function autoPopulateAction(): Action
    {
        return Action::make('autoPopulate')
            ->label(__('forms.labels.auto_populate_from_costs'))
            ->icon('heroicon-o-sparkles')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.auto_populate_line_items'))
            ->modalDescription(__('forms.labels.auto_populate_description'))
            ->visible(fn () => $this->record->status === DebitNoteStatus::DRAFT)
            ->action(function () {
                $companyId = $this->record->company_id;

                $piIds = ProformaInvoice::where('company_id', $companyId)->pluck('id');
                $shipmentIds = Shipment::where('company_id', $companyId)->pluck('id');

                $costs = AdditionalCost::query()
                    ->where('billable_to', BillableTo::CLIENT)
                    ->whereNot('status', AdditionalCostStatus::WAIVED->value)
                    ->whereDoesntHave('debitNoteLineItems')
                    ->where(function ($q) use ($piIds, $shipmentIds) {
                        $q->where(function ($q2) use ($piIds) {
                            $q2->where('costable_type', ProformaInvoice::class)
                                ->whereIn('costable_id', $piIds);
                        });
                        if ($shipmentIds->isNotEmpty()) {
                            $q->orWhere(function ($q2) use ($shipmentIds) {
                                $q2->where('costable_type', Shipment::class)
                                    ->whereIn('costable_id', $shipmentIds);
                            });
                        }
                    })
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

                    // Use document currency amount when available (converted to PI currency)
                    $lineAmount = $cost->amount_in_document_currency ?: $cost->amount;
                    $lineCurrency = $cost->amount_in_document_currency
                        ? ($costable?->currency_code ?? $cost->currency_code)
                        : $cost->currency_code;

                    DebitNoteLineItem::create([
                        'debit_note_id' => $this->record->id,
                        'additional_cost_id' => $cost->id,
                        'proforma_invoice_id' => $piId,
                        'shipment_id' => $shipmentId,
                        'description' => $cost->cost_type->getLabel() . ' — ' . ($cost->description ?: ($costable?->reference ?? '')),
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
