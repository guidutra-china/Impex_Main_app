<?php

namespace App\Filament\RelationManagers;

use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Financial\Actions\WaivePaymentScheduleItemAction;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\Enums\CalculationBase;
use BackedEnum;
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
                    ->label('#')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('label')
                    ->label('Description')
                    ->weight('bold')
                    ->description(fn ($record) => $record->is_credit ? 'CREDIT' : null)
                    ->color(fn ($record) => $record->is_credit ? 'success' : null),
                TextColumn::make('percentage')
                    ->label('%')
                    ->suffix('%')
                    ->alignCenter(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, $record) => $record->is_credit
                        ? '-' . Money::format($state)
                        : Money::format($state))
                    ->prefix('$ ')
                    ->alignEnd()
                    ->color(fn ($record) => $record->is_credit ? 'success' : null),
                TextColumn::make('paid_amount')
                    ->label('Paid')
                    ->getStateUsing(fn ($record) => $record->paid_amount)
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->prefix('$ ')
                    ->alignEnd()
                    ->color('success'),
                TextColumn::make('remaining_amount')
                    ->label('Remaining')
                    ->getStateUsing(fn ($record) => $record->remaining_amount)
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->prefix('$ ')
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
                TextColumn::make('due_condition')
                    ->label('Condition')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date('d/m/Y')
                    ->placeholder('TBD')
                    ->color(fn ($record) => $record->due_date?->isPast() && ! $record->status->isResolved() ? 'danger' : null),
                TextColumn::make('is_blocking')
                    ->label('Blocking')
                    ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')
                    ->badge()
                    ->color(fn ($state) => $state ? 'danger' : 'gray')
                    ->alignCenter(),
                TextColumn::make('status')
                    ->label('Status')
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
            ])
            ->emptyStateHeading('No payment schedule')
            ->emptyStateDescription('Generate a payment schedule from the payment terms.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }

    protected function generateScheduleAction(): Action
    {
        return Action::make('generateSchedule')
            ->label('Generate Schedule')
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

                Notification::make()
                    ->title($count . ' schedule items generated')
                    ->success()
                    ->send();
            });
    }

    protected function regenerateScheduleAction(): Action
    {
        return Action::make('regenerateSchedule')
            ->label('Regenerate')
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
            ->label('Set Due Date')
            ->icon('heroicon-o-calendar')
            ->color('info')
            ->form([
                DatePicker::make('due_date')
                    ->label('Due Date')
                    ->required(),
            ])
            ->visible(fn ($record) => ! $record->status->isResolved())
            ->action(function ($record, array $data) {
                $record->update(['due_date' => $data['due_date']]);

                Notification::make()->title('Due date updated')->success()->send();
            });
    }

    protected function waiveAction(): Action
    {
        return Action::make('waive')
            ->label('Waive')
            ->icon('heroicon-o-arrow-uturn-right')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Waive Payment')
            ->modalDescription(fn ($record) => 'This will waive the payment "' . $record->label . '" (' . Money::format($record->amount) . ' ' . $record->currency_code . '). The blocking condition will be removed.')
            ->form([
                Textarea::make('reason')
                    ->label('Reason for Waiving')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->visible(fn ($record) => ! $record->status->isResolved() && auth()->user()?->can('waive-payments'))
            ->action(function ($record, array $data) {
                app(WaivePaymentScheduleItemAction::class)->execute($record, $data['reason'] ?? null);

                Notification::make()->title('Payment waived')->success()->send();
            });
    }
}
