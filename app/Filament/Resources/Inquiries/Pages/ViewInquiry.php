<?php

namespace App\Filament\Resources\Inquiries\Pages;

use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Filament\Resources\Inquiries\InquiryResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInquiry extends ViewRecord
{
    protected static string $resource = InquiryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->compareSupplierQuotationsAction(),
            EditAction::make(),
        ];
    }

    protected function compareSupplierQuotationsAction(): Action
    {
        return Action::make('compareSupplierQuotations')
            ->label(__('forms.labels.compare_supplier_quotations'))
            ->icon('heroicon-o-scale')
            ->color('info')
            ->visible(fn () => $this->record
                ->supplierQuotations()
                ->whereIn('status', [
                    SupplierQuotationStatus::RECEIVED,
                    SupplierQuotationStatus::UNDER_ANALYSIS,
                    SupplierQuotationStatus::SELECTED,
                ])
                ->exists()
            )
            ->url(fn () => InquiryResource::getUrl('compare-sq', ['record' => $this->record]));
    }
}
