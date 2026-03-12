<?php

namespace App\Filament\Resources\ProductionSchedules\Pages;

use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Resources\ProductionSchedules\ProductionScheduleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductionSchedule extends CreateRecord
{
    protected static string $resource = ProductionScheduleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        if (! empty($data['purchase_order_id'])) {
            $po = PurchaseOrder::find($data['purchase_order_id']);
            if ($po) {
                $data['supplier_company_id'] = $po->supplier_company_id;
            }
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }

    protected function getDefaultFormData(): array
    {
        $piId = request()->query('proforma_invoice_id');

        if ($piId) {
            return [
                'proforma_invoice_id' => (int) $piId,
            ];
        }

        return [];
    }
}
