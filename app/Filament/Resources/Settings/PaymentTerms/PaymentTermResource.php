<?php

namespace App\Filament\Resources\Settings\PaymentTerms;

use App\Domain\Settings\Models\PaymentTerm;
use App\Filament\Resources\Settings\PaymentTerms\Pages\CreatePaymentTerm;
use App\Filament\Resources\Settings\PaymentTerms\Pages\EditPaymentTerm;
use App\Filament\Resources\Settings\PaymentTerms\Pages\ListPaymentTerms;
use App\Filament\Resources\Settings\PaymentTerms\Schemas\PaymentTermForm;
use App\Filament\Resources\Settings\PaymentTerms\Tables\PaymentTermsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class PaymentTermResource extends Resource
{
    protected static ?string $model = PaymentTerm::class;

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Payment Terms';

    public static function form(Schema $schema): Schema
    {
        return PaymentTermForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentTermsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentTerms::route('/'),
            'create' => CreatePaymentTerm::route('/create'),
            'edit' => EditPaymentTerm::route('/{record}/edit'),
        ];
    }
}
