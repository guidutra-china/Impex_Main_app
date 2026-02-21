<?php

namespace App\Filament\Resources\CRM\Companies\Pages;

use App\Domain\CRM\Models\CompanyRoleAssignment;
use App\Filament\Resources\CRM\Companies\CompanyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['roles'] = $this->record->companyRoles
            ->pluck('role')
            ->map(fn ($role) => $role->value)
            ->toArray();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['roles']);

        return $data;
    }

    protected function afterSave(): void
    {
        $roles = $this->data['roles'] ?? [];

        $this->record->companyRoles()->delete();

        foreach ($roles as $role) {
            CompanyRoleAssignment::create([
                'company_id' => $this->record->id,
                'role' => $role,
            ]);
        }
    }
}
