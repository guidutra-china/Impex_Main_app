<?php

namespace App\Filament\Resources\Finance\DebitNotes\Pages;

use App\Domain\Financial\Actions\GenerateDebitNoteFromCostsAction;
use App\Domain\Financial\Actions\IssueDebitNoteAction;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Infrastructure\Support\Money;
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
                app(\App\Domain\Financial\Actions\SyncDebitNoteScheduleAction::class)
                    ->execute($this->record->refresh());
                Notification::make()->title(__('messages.debit_note_cancelled'))->warning()->send();
                $this->refreshFormData(['status']);
            });
    }

    /**
     * Delegates cost selection AND line-item construction to
     * GenerateDebitNoteFromCostsAction — the same rule (client-billable,
     * excludes DISCOUNT, abs() amounts) that generates a DN from scratch
     * elsewhere. Keeping a single source of truth here prevents this page
     * from silently drifting from that rule (it previously duplicated the
     * query and lacked the DISCOUNT exclusion).
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
            ->visible(fn () => $this->record->status === DebitNoteStatus::DRAFT
                && $this->record->party_type === \App\Domain\Financial\Enums\PartyType::CLIENT)
            ->action(function () {
                $action = app(GenerateDebitNoteFromCostsAction::class);
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
