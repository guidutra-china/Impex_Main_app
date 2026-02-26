<?php

namespace App\Domain\Finance\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ExpenseCategory: string implements HasLabel, HasColor, HasIcon
{
    case RENT = 'rent';
    case SALARY = 'salary';
    case SOFTWARE = 'software';
    case UTILITIES = 'utilities';
    case OFFICE_SUPPLIES = 'office_supplies';
    case MARKETING = 'marketing';
    case LEGAL = 'legal';
    case ACCOUNTING = 'accounting';
    case TELECOM = 'telecom';
    case TRAVEL = 'travel';
    case MEALS = 'meals';
    case INSURANCE = 'insurance';
    case TAXES_FEES = 'taxes_fees';
    case MAINTENANCE = 'maintenance';
    case BANK_FEES = 'bank_fees';
    case OTHER = 'other';

    public function getLabel(): ?string
    {
        return __('enums.expense_category.' . $this->value);
    }

    public function getEnglishLabel(): string
    {
        return match ($this) {
            self::RENT => 'Rent',
            self::SALARY => 'Salary & Wages',
            self::SOFTWARE => 'Software & Subscriptions',
            self::UTILITIES => 'Utilities',
            self::OFFICE_SUPPLIES => 'Office Supplies',
            self::MARKETING => 'Marketing & Advertising',
            self::LEGAL => 'Legal Services',
            self::ACCOUNTING => 'Accounting Services',
            self::TELECOM => 'Telecom & Internet',
            self::TRAVEL => 'Travel',
            self::MEALS => 'Meals & Entertainment',
            self::INSURANCE => 'Insurance',
            self::TAXES_FEES => 'Taxes & Government Fees',
            self::MAINTENANCE => 'Maintenance & Repairs',
            self::BANK_FEES => 'Bank Fees & Charges',
            self::OTHER => 'Other',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::RENT => 'primary',
            self::SALARY => 'danger',
            self::SOFTWARE => 'info',
            self::UTILITIES => 'warning',
            self::OFFICE_SUPPLIES => 'gray',
            self::MARKETING => 'success',
            self::LEGAL => 'primary',
            self::ACCOUNTING => 'primary',
            self::TELECOM => 'info',
            self::TRAVEL => 'warning',
            self::MEALS => 'gray',
            self::INSURANCE => 'danger',
            self::TAXES_FEES => 'danger',
            self::MAINTENANCE => 'warning',
            self::BANK_FEES => 'gray',
            self::OTHER => 'gray',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::RENT => 'heroicon-o-building-office',
            self::SALARY => 'heroicon-o-user-group',
            self::SOFTWARE => 'heroicon-o-cpu-chip',
            self::UTILITIES => 'heroicon-o-bolt',
            self::OFFICE_SUPPLIES => 'heroicon-o-clipboard-document-list',
            self::MARKETING => 'heroicon-o-megaphone',
            self::LEGAL => 'heroicon-o-scale',
            self::ACCOUNTING => 'heroicon-o-calculator',
            self::TELECOM => 'heroicon-o-phone',
            self::TRAVEL => 'heroicon-o-paper-airplane',
            self::MEALS => 'heroicon-o-cake',
            self::INSURANCE => 'heroicon-o-shield-check',
            self::TAXES_FEES => 'heroicon-o-receipt-percent',
            self::MAINTENANCE => 'heroicon-o-wrench-screwdriver',
            self::BANK_FEES => 'heroicon-o-banknotes',
            self::OTHER => 'heroicon-o-ellipsis-horizontal-circle',
        };
    }
}
