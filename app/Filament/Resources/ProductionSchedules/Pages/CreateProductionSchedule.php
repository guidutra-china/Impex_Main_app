<?php

namespace App\Filament\Resources\ProductionSchedules\Pages;

use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Resources\ProductionSchedules\ProductionScheduleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductionSchedule extends CreateRecord
{
    protected static string $resource = ProductionScheduleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        $pi = ProformaInvoice::find($data['proforma_invoice_id']);
        if ($pi) {
            $data['supplier_company_id'] = $pi->company_id;
        }

        return $data;
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
