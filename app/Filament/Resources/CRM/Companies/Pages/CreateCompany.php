<?php

namespace App\Filament\Resources\CRM\Companies\Pages;

use App\Domain\CRM\Models\CompanyRoleAssignment;
use App\Filament\Resources\CRM\Companies\CompanyResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['roles']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $roles = $this->data['roles'] ?? [];

        if (empty($roles)) {
            return;
        }

        DB::transaction(function () use ($roles) {
            $now = now();

            $assignments = collect($roles)->map(fn ($role) => [
                'company_id' => $this->record->id,
                'role' => $role,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            CompanyRoleAssignment::insert($assignments);
        });
    }
}
