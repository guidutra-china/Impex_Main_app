<?php

namespace App\Filament\RelationManagers;

use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Financial\Actions\WaivePaymentScheduleItemAction;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\Enums\CalculationBase;
use BackedEnum;
use Illuminate\Support\HtmlString;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PaymentScheduleRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentScheduleItems';

    protected static ?string $title = 'Payment Schedule';

    protected static BackedEnum|string|null $icon = 'heroicon-o-calendar-days';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label(__('forms.labels.hash'))
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('label')
                    ->label(__('forms.labels.description'))
                    ->formatStateUsing(function ($state, $record) {
                        $label = preg_replace('/\s*\x{2014}\s*\[.*\]\s*$/u', '', $state ?? '');
                        $label = e($label);

                        $html = '<span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-800 dark:bg-white/10 dark:text-gray-200">' . $label . '</span>';

                        $record->loadMissing('shipment');
                        if ($record->shipment) {
                            $ref = e($record->shipment->bl_number ?: $record->shipment->reference);
                            $html .= ' <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-[0.65rem] font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30">' . $ref . '</span>';
                        }

                        if ($record->is_credit) {
                            $html .= ' <span class="inline-flex items-center rounded-md bg-green-50 px-1.5 py-0.5 text-[0.6rem] font-semibold text-green-700 uppercase dark:bg-green-400/10 dark:text-green-400">Credit</span>';
                        }

                        return new HtmlString($html);
                    }),
                TextColumn::make('percentage')
                    ->label(__('forms.labels.percent'))
                    ->suffix('%')
                    ->alignCenter(),
                TextColumn::make('amount')
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state, $record) => $record->is_credit
                        ? '-' . Money::format($state)
                        : Money::format($state))
                    ->prefix('$ ')
                    ->alignEnd()
                    ->color(fn ($record) => $record->is_credit ? 'success' : null),
                TextColumn::make('paid_amount')
                    ->label(__('forms.labels.paid'))
                    ->getStateUsing(fn ($record) => $record->paid_amount)
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->prefix('$ ')
                    ->alignEnd()
                    ->color('success'),
                TextColumn::make('remaining_amount')
                    ->label(__('forms.labels.remaining'))
                    ->getStateUsing(fn ($record) => $record->remaining_amount)
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->prefix('$ ')
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                TextColumn::make('due_condition')
                    ->label(__('forms.labels.condition'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('due_date')
                    ->label(__('forms.labels.due_date'))
                    ->date('d/m/Y')
                    ->placeholder(__('forms.placeholders.tbd'))
                    ->color(fn ($record) => $record->due_date?->isPast() && ! $record->status->isResolved() ? 'danger' : null),
                TextColumn::make('is_blocking')
                    ->label(__('forms.labels.blocking'))
                    ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')
                    ->badge()
                    ->color(fn ($state) => $state ? 'danger' : 'gray')
                    ->alignCenter(),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge()
                    ->description(fn ($record) => $record->is_credit && $record->is_credit_applied ? 'Credit Applied' : null),
            ])
            ->headerActions([
                $this->generateScheduleAction(),
                $this->regenerateScheduleAction(),
            ])
            ->recordActions([
                $this->setDueDateAction(),
                $this->waiveAction(),
                $this->restoreWaivedAction(),
                $this->deleteScheduleItemAction(),
            ])
            ->emptyStateHeading('No payment schedule')
            ->emptyStateDescription('Generate a payment schedule from the payment terms.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }

    protected function generateScheduleAction(): Action
    {
        return Action::make('generateSchedule')
            ->label(__('forms.labels.generate_schedule'))
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            ->visible(fn () => auth()->user()?->can('generate-payment-schedule'))
            ->requiresConfirmation()
            ->modalHeading('Generate Payment Schedule')
            ->modalDescription(function () {
                $record = $this->getOwnerRecord();
                $paymentTerm = $record->paymentTerm;

                if (! $paymentTerm) {
                    return 'No payment term is assigned to this document. Please assign a payment term first.';
                }

                if ($record->hasPaymentSchedule()) {
                    return 'A payment schedule already exists. Use "Regenerate" to update it.';
                }

                $stages = $paymentTerm->stages;
                $lines = $stages->map(fn ($s) => $s->percentage . '% — ' . ($s->calculation_base?->getLabel() ?? 'N/A') . ($s->days > 0 ? ' (+' . $s->days . ' days)' : ''));

                return 'This will generate a schedule based on "' . $paymentTerm->name . '":'
                    . "\n" . $lines->implode("\n")
                    . "\n\nTotal: " . Money::format($record->total) . ' ' . $record->currency_code;
            })
            ->action(function () {
                $record = $this->getOwnerRecord();

                if (! $record->payment_term_id) {
                    Notification::make()->title('No payment term assigned')->danger()->send();
                    return;
                }

                if ($record->hasPaymentSchedule()) {
                    Notification::make()->title('Schedule already exists')->warning()->send();
                    return;
                }

                $count = app(GeneratePaymentScheduleAction::class)->execute($record);

                if ($count === 0) {
                    Notification::make()
                        ->title(__('messages.no_schedule_items_created'))
                        ->body(__('messages.schedule_items_shipment_dependent'))
                        ->warning()
                        ->send();
                } else {
                    Notification::make()
                        ->title($count . ' schedule items generated')
                        ->success()
                        ->send();
                }
            });
    }

    protected function regenerateScheduleAction(): Action
    {
        return Action::make('regenerateSchedule')
            ->label(__('forms.labels.regenerate'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Regenerate Payment Schedule')
            ->modalDescription('This will delete unpaid/unwaived schedule items without payments and recreate them from the current payment terms and total. Paid and waived items will be preserved.')
            ->visible(fn () => $this->getOwnerRecord()->hasPaymentSchedule() && auth()->user()?->can('generate-payment-schedule'))
            ->action(function () {
                $record = $this->getOwnerRecord();
                $count = app(GeneratePaymentScheduleAction::class)->regenerate($record);

                Notification::make()
                    ->title($count . ' schedule items regenerated')
                    ->success()
                    ->send();
            });
    }

    protected function setDueDateAction(): Action
    {
        return Action::make('setDueDate')
            ->label(__('forms.labels.set_due_date'))
            ->icon('heroicon-o-calendar')
            ->color('info')
            ->form([
                DatePicker::make('due_date')
                    ->label(__('forms.labels.due_date'))
                    ->required(),
            ])
            ->visible(fn ($record) => ! $record->status->isResolved()
                && auth()->user()?->can('edit-payments'))
            ->action(function ($record, array $data) {
                $record->update(['due_date' => $data['due_date']]);

                Notification::make()->title('Due date updated')->success()->send();
            });
    }

    protected function waiveAction(): Action
    {
        return Action::make('waive')
            ->label(__('forms.labels.waive'))
            ->icon('heroicon-o-arrow-uturn-right')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Waive Payment')
            ->modalDescription(fn ($record) => 'This will waive the payment "' . $record->label . '" (' . Money::format($record->amount) . ' ' . $record->currency_code . '). The blocking condition will be removed.')
            ->form([
                Textarea::make('reason')
                    ->label(__('forms.labels.reason_for_waiving'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->visible(fn ($record) => ! $record->status->isResolved() && auth()->user()?->can('waive-payments'))
            ->action(function ($record, array $data) {
                app(WaivePaymentScheduleItemAction::class)->execute($record, $data['reason'] ?? null);

                Notification::make()->title('Payment waived')->success()->send();
            });
    }

    protected function restoreWaivedAction(): Action
    {
        return Action::make('restoreWaived')
            ->label(__('forms.labels.restore'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.restore_payment'))
            ->modalDescription(fn ($record) => 'This will restore the waived payment "' . $record->label . '" back to pending status.')
            ->visible(fn ($record) => $record->status === PaymentScheduleStatus::WAIVED && auth()->user()?->can('waive-payments'))
            ->action(function ($record) {
                $record->update([
                    'status' => PaymentScheduleStatus::PENDING,
                    'waived_by' => null,
                    'waived_at' => null,
                ]);

                Notification::make()->title(__('messages.payment_restored'))->success()->send();
            });
    }

    protected function deleteScheduleItemAction(): Action
    {
        return Action::make('deleteItem')
            ->label(__('forms.labels.delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.delete'))
            ->modalDescription('This will permanently delete this schedule item.')
            ->visible(fn ($record) => ! $record->allocations()->exists()
                && ! in_array($record->status, [PaymentScheduleStatus::PAID])
                && auth()->user()?->can('generate-payment-schedule'))
            ->action(function ($record) {
                $record->delete();

                Notification::make()->title('Schedule item deleted')->success()->send();
            });
    }

}
