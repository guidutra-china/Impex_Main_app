<?php

namespace App\Filament\Resources\CRM\Companies\Pages;

use App\Domain\CRM\Models\CompanyRoleAssignment;
use App\Filament\Resources\CRM\Companies\CompanyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }

    protected function afterCreate(): void
    {
        $roles = $this->data['roles'] ?? [];

        foreach ($roles as $role) {
            CompanyRoleAssignment::create([
                'company_id' => $this->record->id,
                'role' => $role,
            ]);
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['roles']);

        return $data;
    }
}
