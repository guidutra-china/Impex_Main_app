<?php

namespace App\Filament\Resources\SupplierQuotations\Pages;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use App\Filament\Resources\SupplierQuotations\SupplierQuotationResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditSupplierQuotation extends EditRecord
{
    protected static string $resource = SupplierQuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),

            Action::make('importInquiryItems')
                ->label('Import Inquiry Items')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('info')
                ->visible(fn () => $this->record->inquiry_id && $this->record->items()->count() === 0)
                ->requiresConfirmation()
                ->modalHeading('Import Items from Inquiry')
                ->modalDescription('This will copy all items from the linked inquiry into this supplier quotation. Existing items will not be affected.')
                ->action(function () {
                    try {
                        $inquiry = $this->record->inquiry;
                        if (! $inquiry) {
                            throw new \RuntimeException('No inquiry linked.');
                        }

                        $count = 0;
                        DB::transaction(function () use ($inquiry, &$count) {
                            foreach ($inquiry->items as $item) {
                                SupplierQuotationItem::create([
                                    'supplier_quotation_id' => $this->record->id,
                                    'inquiry_item_id' => $item->id,
                                    'product_id' => $item->product_id,
                                    'description' => $item->description,
                                    'quantity' => $item->quantity,
                                    'unit' => $item->unit,
                                    'unit_cost' => 0,
                                    'specifications' => $item->specifications,
                                    'notes' => $item->notes,
                                    'sort_order' => $item->sort_order,
                                ]);
                                $count++;
                            }
                        });

                        Notification::make()
                            ->title("{$count} items imported from inquiry")
                            ->body($inquiry->reference)
                            ->success()
                            ->send();

                        $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->record]));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Import failed')
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
                        $enum = SupplierQuotationStatus::from($status);

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
                            SupplierQuotationStatus::from($data['new_status']),
                            $data['notes'] ?? null,
                        );

                        Notification::make()
                            ->title('Status changed to ' . SupplierQuotationStatus::from($data['new_status'])->getLabel())
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
