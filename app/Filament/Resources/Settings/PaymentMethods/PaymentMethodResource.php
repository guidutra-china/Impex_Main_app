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

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Payment Methods';

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
}
