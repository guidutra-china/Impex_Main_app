<?php

namespace App\Filament\Resources\Finance\CreditNotes\Pages;

use App\Domain\Financial\Actions\GenerateCreditNoteFromCostsAction;
use App\Domain\Financial\Actions\IssueCreditNoteAction;
use App\Domain\Financial\Enums\CreditNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Infrastructure\Support\Money;
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
                app(\App\Domain\Financial\Actions\SyncCreditNoteScheduleAction::class)
                    ->execute($this->record->refresh());
                Notification::make()->title(__('messages.credit_note_cancelled'))->warning()->send();
                $this->refreshFormData(['status']);
            });
    }

    /**
     * Pull unbilled supplier-billable costs (quality deductions, claims,
     * supplier discounts) into this DRAFT credit note as line items. Only
     * meaningful for supplier credit notes — client credits are typed
     * manually.
     *
     * Delegates cost selection AND line-item construction to
     * GenerateCreditNoteFromCostsAction — same rule (supplier-billable,
     * INCLUDES discount, always abs() amount) used to generate a CN from
     * scratch elsewhere. Single source of truth prevents this page from
     * drifting from that rule (it previously duplicated the query and
     * lacked the abs()).
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
                $action = app(GenerateCreditNoteFromCostsAction::class);
                $costs = $action->getUnbilledCosts($this->record->company, null, null);

                if ($costs->isEmpty()) {
                    Notification::make()
                        ->title(__('messages.no_unbilled_costs'))
                        ->warning()
                        ->send();

                    return;
                }

                foreach ($costs as $cost) {
                    $action->createLineItem($this->record, $cost);
                }

                $this->record->recalculateTotal();

                Notification::make()
                    ->title(__('messages.costs_added_as_line_items', ['count' => $costs->count()]))
                    ->success()
                    ->send();

                $this->refreshFormData(['total_amount']);
            });
    }
}
