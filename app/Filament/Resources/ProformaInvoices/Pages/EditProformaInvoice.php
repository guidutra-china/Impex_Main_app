<?php

namespace App\Filament\Resources\ProformaInvoices\Pages;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Pdf\Templates\ProformaInvoicePdfTemplate;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProformaInvoice extends EditRecord
{
    protected static string $resource = ProformaInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GeneratePdfAction::make(
                templateClass: ProformaInvoicePdfTemplate::class,
                label: 'Generate PDF',
            ),
            GeneratePdfAction::download(
                documentType: 'proforma_invoice_pdf',
                label: 'Download PDF',
            ),
            $this->transitionStatusAction(),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
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
                    $enum = ProformaInvoiceStatus::from($status);
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
                        ProformaInvoiceStatus::from($data['new_status']),
                        $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title(__('messages.status_changed_to') . ' ' . ProformaInvoiceStatus::from($data['new_status'])->getLabel())
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.status_transition_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
