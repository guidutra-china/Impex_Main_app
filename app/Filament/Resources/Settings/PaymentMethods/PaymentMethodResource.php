<?php

namespace App\Filament\Resources\Settings\PaymentMethods;

use App\Domain\Settings\Models\PaymentMethod;
use App\Filament\Resources\Settings\PaymentMethods\Pages\CreatePaymentMethod;
use App\Filament\Resources\Settings\PaymentMethods\Pages\EditPaymentMethod;
use App\Filament\Resources\Settings\PaymentMethods\Pages\ListPaymentMethods;
use App\Filament\Resources\Settings\PaymentMethods\Schemas\PaymentMethodForm;
use App\Filament\Resources\Settings\PaymentMethods\Tables\PaymentMethodsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-settings') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentMethodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentMethodsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentMethods::route('/'),
            'create' => CreatePaymentMethod::route('/create'),
            'edit' => EditPaymentMethod::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.payment_methods');
    }
}
