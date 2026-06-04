<?php

namespace App\Filament\Resources\Finance\Trips\Pages;

use App\Domain\Travel\Actions\ApproveTripAction;
use App\Domain\Travel\DataTransferObjects\TripBillingData;
use App\Domain\Travel\Enums\TripStatus;
use App\Domain\Travel\Support\TripExpenseReport;
use App\Filament\Resources\Finance\Trips\Support\TripFxForm;
use App\Filament\Resources\Finance\Trips\TripResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewTrip extends ViewRecord
{
    protected static string $resource = TripResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->approveAction(),
            $this->rejectAction(),
            $this->reportAction(),
            // Approved trips can still be edited — doing so reopens the trip
            // (back to awaiting approval) and the billing is refreshed.
            EditAction::make(),
            Action::make('backToList')
                ->label(__('forms.labels.back_to_list'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(TripResource::getUrl('index')),
        ];
    }

    protected function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('forms.labels.approve'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.approve'))
            ->modalDescription(fn () => $this->record->is_internal
                ? null
                : __('forms.helpers.choose_billing_currency'))
            ->form(fn () => $this->record->is_internal
                ? []
                : TripFxForm::schema($this->record, 'billing_currency', __('forms.labels.billing_currency')))
            ->visible(fn () => $this->isPending()
                && auth()->user()?->can('approve-trips'))
            ->action(function (array $data) {
                $billing = $this->record->is_internal
                    ? null
                    : new TripBillingData($data['billing_currency'], TripFxForm::resolveRates($data));

                app(ApproveTripAction::class)->approve($this->record, $billing);

                Notification::make()->title(__('messages.trip_approved'))->success()->send();

                $this->refreshFormData(['status', 'approved_by', 'approved_at']);
            });
    }

    protected function rejectAction(): Action
    {
        return Action::make('reject')
            ->label(__('forms.labels.reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('forms.labels.reject'))
            ->form([
                Textarea::make('reason')
                    ->label(__('forms.labels.rejection_reason'))
                    ->rows(2)
                    ->required(),
            ])
            ->visible(fn () => $this->isPending()
                && auth()->user()?->can('approve-trips'))
            ->action(function (array $data) {
                app(ApproveTripAction::class)->reject($this->record, $data['reason']);

                Notification::make()->title(__('messages.trip_rejected'))->danger()->send();

                $this->refreshFormData(['status', 'approved_by', 'approved_at', 'rejected_reason']);
            });
    }

    protected function reportAction(): Action
    {
        return Action::make('report')
            ->label(__('forms.labels.expense_report'))
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(fn () => ! $this->record->is_internal && $this->record->expenses()->exists())
            ->form(fn () => array_merge([
                Select::make('locale')
                    ->label(__('forms.labels.report_language'))
                    ->options(['pt_BR' => 'Português', 'en' => 'English', 'zh_CN' => '中文'])
                    ->default(in_array(app()->getLocale(), ['pt_BR', 'en', 'zh_CN'], true) ? app()->getLocale() : 'pt_BR')
                    ->required(),
            ], TripFxForm::schema($this->record, 'target_currency', __('forms.labels.report_currency'))))
            ->action(function (array $data) {
                $billing = new TripBillingData($data['target_currency'], TripFxForm::resolveRates($data));

                return TripExpenseReport::stream($this->record, $billing, $data['locale'] ?? null);
            });
    }

    protected function isPending(): bool
    {
        return in_array($this->record->status, [TripStatus::DRAFT, TripStatus::SUBMITTED], true);
    }
}
