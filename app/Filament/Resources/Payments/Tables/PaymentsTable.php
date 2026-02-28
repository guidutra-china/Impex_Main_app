<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Domain\Financial\Actions\ApprovePaymentAction;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Infrastructure\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('payment_date')
                    ->label(__('forms.labels.date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('direction')
                    ->label(__('forms.labels.direction'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('company.name')
                    ->label(__('forms.labels.company'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency')),
                TextColumn::make('allocated_total')
                    ->label(__('forms.labels.allocated'))
                    ->getStateUsing(fn ($record) => $record->allocated_total)
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd()
                    ->color('success'),
                TextColumn::make('unallocated_amount')
                    ->label(__('forms.labels.unallocated'))
                    ->getStateUsing(fn ($record) => $record->unallocated_amount)
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('paymentMethod.name')
                    ->label(__('forms.labels.method'))
                    ->placeholder('—'),
                TextColumn::make('reference')
                    ->label(__('forms.labels.reference'))
                    ->placeholder('—')
                    ->limit(20)
                    ->tooltip(fn ($record) => $record->reference)
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label(__('forms.labels.created_by'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('approvedByUser.name')
                    ->label(__('forms.labels.approved_by'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('payment_date', 'desc')
            ->filters([
                SelectFilter::make('direction')
                    ->options(PaymentDirection::class),
                SelectFilter::make('status')
                    ->options(PaymentStatus::class),
                SelectFilter::make('company_id')
                    ->label(__('forms.labels.company'))
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('approve')
                        ->label(__('forms.labels.approve'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Approve Payment')
                        ->modalDescription(fn ($record) => 'Approve payment of '
                            . Money::format($record->amount) . ' '
                            . $record->currency_code . '?')
                        ->visible(fn ($record) => $record->status === PaymentStatus::PENDING_APPROVAL)
                        ->action(function ($record) {
                            app(ApprovePaymentAction::class)->approve($record);
                            Notification::make()->title(__('messages.payment_approved'))->success()->send();
                        }),
                    Action::make('reject')
                        ->label(__('forms.labels.reject'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Reject Payment')
                        ->form([
                            Textarea::make('reason')
                                ->label(__('forms.labels.rejection_reason'))
                                ->rows(2)
                                ->required(),
                        ])
                        ->visible(fn ($record) => $record->status === PaymentStatus::PENDING_APPROVAL)
                        ->action(function ($record, array $data) {
                            app(ApprovePaymentAction::class)->reject($record, $data['reason']);
                            Notification::make()->title(__('messages.payment_rejected'))->danger()->send();
                        }),
                    Action::make('cancel_payment')
                        ->label(__('forms.labels.cancel_payment'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Cancel Payment')
                        ->form([
                            Textarea::make('reason')
                                ->label(__('forms.labels.cancellation_reason'))
                                ->rows(2),
                        ])
                        ->visible(fn ($record) => in_array($record->status, [
                            PaymentStatus::PENDING_APPROVAL,
                            PaymentStatus::APPROVED,
                            PaymentStatus::REJECTED,
                        ]))
                        ->action(function ($record, array $data) {
                            app(ApprovePaymentAction::class)->cancel($record, $data['reason'] ?? null);
                            Notification::make()->title('Payment cancelled')->warning()->send();
                        }),
                ])
                    ->label(__('forms.labels.change_status'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->size('sm')
                    ->visible(fn ($record) => $record->status !== PaymentStatus::CANCELLED),
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
