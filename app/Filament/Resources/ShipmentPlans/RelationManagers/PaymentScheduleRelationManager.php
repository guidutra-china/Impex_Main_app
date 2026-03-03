<?php

namespace App\Filament\Resources\ShipmentPlans\RelationManagers;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Infrastructure\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PaymentScheduleRelationManager extends RelationManager
{
    protected static string $relationship = 'linkedPaymentScheduleItems';

    protected static ?string $title = 'Payment Schedule';

    protected static BackedEnum|string|null $icon = 'heroicon-o-banknotes';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('label')
                    ->label(__('forms.labels.description'))
                    ->weight('bold'),
                TextColumn::make('payable.reference')
                    ->label(__('forms.labels.proforma_invoice'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('percentage')
                    ->label(__('forms.labels.percent'))
                    ->suffix('%')
                    ->alignCenter(),
                TextColumn::make('amount')
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->prefix('$ ')
                    ->alignEnd(),
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
                    ->badge(),
            ])
            ->recordActions([
                $this->setDueDateAction(),
            ])
            ->emptyStateHeading('No payment schedule')
            ->emptyStateDescription('Confirm the shipment plan to generate payment schedule items.')
            ->emptyStateIcon('heroicon-o-banknotes');
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
}
