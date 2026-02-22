<?php

namespace App\Filament\Resources\Inquiries\Pages;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use App\Filament\Resources\Inquiries\InquiryResource;
use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditInquiry extends EditRecord
{
    protected static string $resource = InquiryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createQuotation')
                ->label('Create Quotation')
                ->icon('heroicon-o-document-plus')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Create Quotation from Inquiry')
                ->modalDescription('This will create a new quotation linked to this inquiry, copying all items. The inquiry status will change to "Quoting".')
                ->visible(fn () => in_array($this->record->status, [
                    InquiryStatus::RECEIVED,
                    InquiryStatus::QUOTING,
                    InquiryStatus::QUOTED,
                ]))
                ->action(function () {
                    try {
                        $quotation = DB::transaction(function () {
                            $inquiry = Inquiry::lockForUpdate()->findOrFail($this->record->id);

                            $quotation = Quotation::create([
                                'inquiry_id' => $inquiry->id,
                                'company_id' => $inquiry->company_id,
                                'contact_id' => $inquiry->contact_id,
                                'status' => QuotationStatus::DRAFT,
                                'currency_code' => $inquiry->currency_code,
                                'notes' => $inquiry->notes,
                            ]);

                            foreach ($inquiry->items as $item) {
                                QuotationItem::create([
                                    'quotation_id' => $quotation->id,
                                    'product_id' => $item->product_id,
                                    'description' => $item->description,
                                    'quantity' => $item->quantity,
                                    'unit' => $item->unit,
                                    'unit_cost' => 0,
                                    'unit_price' => $item->target_price ?? 0,
                                    'sort_order' => $item->sort_order,
                                    'notes' => $item->specifications,
                                ]);
                            }

                            if ($inquiry->status === InquiryStatus::RECEIVED) {
                                app(TransitionStatusAction::class)->execute(
                                    $inquiry,
                                    InquiryStatus::QUOTING,
                                    'Quotation ' . $quotation->reference . ' created from inquiry.',
                                );
                            }

                            return $quotation;
                        });

                        Notification::make()
                            ->title('Quotation created: ' . $quotation->reference)
                            ->body('Items copied. Redirecting to quotation...')
                            ->success()
                            ->send();

                        return redirect(QuotationResource::getUrl('edit', ['record' => $quotation]));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Error creating quotation')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('transitionStatus')
                ->label('Change Status')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn () => ! empty($this->record->getAllowedNextStatuses()))
                ->form(function () {
                    $allowed = $this->record->getAllowedNextStatuses();
                    $options = collect($allowed)->mapWithKeys(function ($status) {
                        $enum = InquiryStatus::from($status);
                        return [$status => $enum->getLabel()];
                    })->toArray();

                    return [
                        \Filament\Forms\Components\Select::make('new_status')
                            ->label('New Status')
                            ->options($options)
                            ->required(),
                        \Filament\Forms\Components\Textarea::make('notes')
                            ->label('Transition Notes')
                            ->rows(2)
                            ->maxLength(1000),
                    ];
                })
                ->action(function (array $data) {
                    try {
                        app(TransitionStatusAction::class)->execute(
                            $this->record,
                            InquiryStatus::from($data['new_status']),
                            $data['notes'] ?? null,
                        );

                        Notification::make()
                            ->title('Status changed to ' . InquiryStatus::from($data['new_status'])->getLabel())
                            ->success()
                            ->send();

                        $this->refreshFormData(['status']);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Status transition failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
