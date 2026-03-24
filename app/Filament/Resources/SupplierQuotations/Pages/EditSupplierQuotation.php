<?php

namespace App\Filament\Resources\SupplierQuotations\Pages;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Pdf\Templates\RfqPdfTemplate;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\Infrastructure\Excel\Templates\RfqExcelTemplate;
use App\Filament\Actions\GenerateExcelAction;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use App\Filament\Resources\SupplierQuotations\SupplierQuotationResource;
use Filament\Forms\Components\Toggle;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSupplierQuotation extends EditRecord
{
    protected static string $resource = SupplierQuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),

            Action::make('transitionStatus')
                ->label(__('forms.labels.change_status'))
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => ! empty($this->record->getAllowedNextStatuses()))
                ->form(function () {
                    $allowed = $this->record->getAllowedNextStatuses();
                    $options = collect($allowed)->mapWithKeys(function ($status) {
                        $enum = SupplierQuotationStatus::from($status);

                        return [$status => $enum->getLabel()];
                    })->toArray();

                    return [
                        \Filament\Forms\Components\Select::make('new_status')
                            ->label(__('forms.labels.new_status'))
                            ->options($options)
                            ->required(),
                        \Filament\Forms\Components\Textarea::make('notes')
                            ->label(__('forms.labels.transition_notes'))
                            ->rows(2)
                            ->maxLength(1000),
                    ];
                })
                ->action(function (array $data) {
                    try {
                        app(TransitionStatusAction::class)->execute(
                            $this->record,
                            SupplierQuotationStatus::from($data['new_status']),
                            $data['notes'] ?? null,
                        );

                        Notification::make()
                            ->title(__('messages.status_changed_to') . ' ' . SupplierQuotationStatus::from($data['new_status'])->getLabel())
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
                }),

            GeneratePdfAction::make(
                templateClass: RfqPdfTemplate::class,
                label: 'Generate RFQ',
                icon: 'heroicon-o-document-arrow-down',
                formSchema: [
                    Toggle::make('show_target_price')
                        ->label('Include Target Price')
                        ->helperText('Show the client\'s target price in the RFQ document')
                        ->default(false),
                ],
            ),
            GeneratePdfAction::download(
                documentType: 'rfq_pdf',
                label: 'Download RFQ',
            ),
            GeneratePdfAction::preview(
                templateClass: RfqPdfTemplate::class,
                label: 'Preview RFQ',
                formSchema: [
                    Toggle::make('show_target_price')
                        ->label('Include Target Price')
                        ->helperText('Show the client\'s target price in the RFQ document')
                        ->default(false),
                ],
            ),
            GenerateExcelAction::download(
                templateClass: RfqExcelTemplate::class,
                label: 'Download RFQ Excel',
                formSchema: [
                    Toggle::make('show_target_price')
                        ->label('Include Target Price')
                        ->default(false),
                ],
            ),
            SendDocumentByEmailAction::make(
                documentType: 'rfq_pdf',
                label: 'Send RFQ by Email',
            ),

            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
