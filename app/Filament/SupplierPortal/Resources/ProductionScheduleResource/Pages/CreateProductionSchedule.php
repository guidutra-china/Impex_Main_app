<?php

namespace App\Filament\SupplierPortal\Resources\ProductionScheduleResource\Pages;

use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Filament\SupplierPortal\Resources\ProductionScheduleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductionSchedule extends CreateRecord
{
    protected static string $resource = ProductionScheduleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['supplier_company_id'] = auth()->user()->company_id
            ?? $this->getTenant()?->id;
        $data['status'] = ProductionScheduleStatus::Draft->value;
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
