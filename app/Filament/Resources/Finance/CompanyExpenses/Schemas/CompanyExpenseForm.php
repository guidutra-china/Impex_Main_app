<?php

namespace App\Filament\Resources\Finance\CompanyExpenses\Schemas;

use App\Domain\Finance\Enums\ExpenseCategory;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\PaymentMethod;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CompanyExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('forms.sections.expense_details'))->columns(2)->columnSpanFull()->schema([
                Select::make('category')
                    ->label(__('forms.labels.category'))
                    ->options(ExpenseCategory::class)
                    ->required()
                    ->searchable(),

                DatePicker::make('expense_date')
                    ->label(__('forms.labels.expense_date'))
                    ->required()
                    ->default(now())
                    ->maxDate(now()),

                TextInput::make('description')
                    ->label(__('forms.labels.description'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('amount')
                    ->label(__('forms.labels.amount'))
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0.01)
                    ->required()
                    ->dehydrateStateUsing(fn ($state) => Money::toMinor((float) $state))
                    ->formatStateUsing(fn ($state) => $state ? Money::toMajor($state) : null),

                Select::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->options(fn () => Currency::pluck('code', 'code'))
                    ->required()
                    ->default(fn () => Currency::base()?->code ?? 'USD'),

                Select::make('payment_method_id')
                    ->label(__('forms.labels.payment_method'))
                    ->options(fn () => PaymentMethod::where('is_active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->nullable(),

                Select::make('bank_account_id')
                    ->label(__('forms.labels.bank_account'))
                    ->options(fn () => BankAccount::where('status', 'active')
                        ->get()
                        ->mapWithKeys(fn ($account) => [
                            $account->id => $account->bank_name . ' - ' . $account->account_number,
                        ]))
                    ->searchable()
                    ->nullable(),

                TextInput::make('reference')
                    ->label(__('forms.labels.reference'))
                    ->maxLength(255)
                    ->placeholder(__('forms.placeholders.receipt_or_invoice_number')),
            ]),

            Section::make(__('forms.sections.recurrence'))->columns(2)->columnSpanFull()->schema([
                Checkbox::make('is_recurring')
                    ->label(__('forms.labels.recurring_expense'))
                    ->live()
                    ->helperText(__('forms.helpers.mark_as_recurring')),

                TextInput::make('recurring_day')
                    ->label(__('forms.labels.recurring_day'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(31)
                    ->visible(fn (Get $get) => $get('is_recurring'))
                    ->helperText(__('forms.helpers.day_of_month')),
            ]),

            Section::make(__('forms.sections.additional_info'))->columnSpanFull()->schema([
                FileUpload::make('attachment_path')
                    ->label(__('forms.labels.receipt_attachment'))
                    ->directory('expense-receipts')
                    ->acceptedFileTypes(['application/pdf', 'image/*'])
                    ->maxSize(5120),

                Textarea::make('notes')
                    ->label(__('forms.labels.notes'))
                    ->rows(3)
                    ->maxLength(1000),
            ]),
        ]);
    }
}
