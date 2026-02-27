<?php

namespace App\Filament\Resources\Settings\Roles\Pages;

use App\Filament\Resources\Settings\Roles\RoleResource;
use App\Filament\Resources\Settings\Roles\Schemas\RoleForm;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Permission;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    public function getTitle(): string
    {
        return 'Edit Role — ' . ucfirst($this->record->name);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $rolePermissionIds = $this->record->permissions->pluck('id')->toArray();
        $groups = RoleForm::getGroups();

        foreach ($groups as $group) {
            $groupPermissionIds = Permission::where('guard_name', 'web')
                ->whereIn('name', $group['permissions'])
                ->pluck('id')
                ->toArray();

            $data['permissions_' . $group['key']] = array_values(
                array_intersect($rolePermissionIds, $groupPermissionIds)
            );
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $groups = RoleForm::getGroups();
        $allSelectedIds = [];

        foreach ($groups as $group) {
            $key = 'permissions_' . $group['key'];
            if (isset($data[$key]) && is_array($data[$key])) {
                $allSelectedIds = array_merge($allSelectedIds, $data[$key]);
            }
            unset($data[$key]);
        }

        $this->record->syncPermissions(
            Permission::whereIn('id', $allSelectedIds)->get()
        );

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterSave(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
