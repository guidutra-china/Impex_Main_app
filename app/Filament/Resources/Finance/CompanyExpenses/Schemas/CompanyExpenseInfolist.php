<?php

namespace App\Filament\Resources\Finance\CompanyExpenses\Schemas;

use App\Domain\Infrastructure\Support\Money;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyExpenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('forms.sections.expense_details'))->columns(3)->columnSpanFull()->schema([
                TextEntry::make('category')
                    ->label(__('forms.labels.category'))
                    ->badge(),

                TextEntry::make('expense_date')
                    ->label(__('forms.labels.expense_date'))
                    ->date('d/m/Y'),

                TextEntry::make('description')
                    ->label(__('forms.labels.description')),

                TextEntry::make('amount')
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state) => Money::format($state)),

                TextEntry::make('currency_code')
                    ->label(__('forms.labels.currency')),

                TextEntry::make('paymentMethod.name')
                    ->label(__('forms.labels.payment_method'))
                    ->placeholder('—'),

                TextEntry::make('bankAccount.bank_name')
                    ->label(__('forms.labels.bank_account'))
                    ->placeholder('—'),

                TextEntry::make('reference')
                    ->label(__('forms.labels.reference'))
                    ->placeholder('—'),

                TextEntry::make('creator.name')
                    ->label(__('forms.labels.created_by'))
                    ->placeholder('—'),
            ]),

            Section::make(__('forms.sections.recurrence'))->columns(3)->columnSpanFull()->schema([
                IconEntry::make('is_recurring')
                    ->label(__('forms.labels.recurring_expense'))
                    ->boolean(),

                TextEntry::make('recurring_day')
                    ->label(__('forms.labels.recurring_day'))
                    ->placeholder('—')
                    ->suffix(fn ($state) => $state ? __('forms.labels.of_the_month') : ''),
            ]),

            Section::make(__('forms.sections.additional_info'))->columnSpanFull()->schema([
                TextEntry::make('notes')
                    ->label(__('forms.labels.notes'))
                    ->placeholder('—'),
            ]),
        ]);
    }
}
