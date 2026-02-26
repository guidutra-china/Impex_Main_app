<?php

namespace App\Filament\Resources\Settings\Roles;

use App\Filament\Resources\Settings\Roles\Pages\EditRole;
use App\Filament\Resources\Settings\Roles\Pages\ListRoles;
use App\Filament\Resources\Settings\Roles\Schemas\RoleForm;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;
use UnitEnum;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Roles & Permissions';

    protected static ?string $modelLabel = 'Role';

    protected static ?string $pluralModelLabel = 'Roles';

    protected static ?string $slug = 'roles';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-roles') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
