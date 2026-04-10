<?php

namespace App\Filament\Resources\Finance\CompanyExpenses\Tables;

use App\Domain\Finance\Actions\ApproveCompanyExpenseAction;
use App\Domain\Finance\Enums\ExpenseApprovalStatus;
use App\Domain\Finance\Enums\ExpenseCategory;
use App\Domain\Infrastructure\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CompanyExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn () => \App\Domain\Finance\Models\CompanyExpense::query()->excludeRecurringTemplates())
            ->columns([
                TextColumn::make('expense_date')
                    ->label(__('forms.labels.date'))
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('category')
                    ->label(__('forms.labels.category'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('description')
                    ->label(__('forms.labels.description'))
                    ->searchable()
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->description),

                TextColumn::make('amount')
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()
                        ->label(__('forms.labels.total'))
                        ->formatStateUsing(fn ($state) => Money::format((int) $state))),

                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency')),

                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge()
                    ->sortable(),

                IconColumn::make('is_recurring')
                    ->label(__('forms.labels.recurring'))
                    ->boolean()
                    ->trueIcon('heroicon-o-arrow-path')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('info')
                    ->falseColor('gray'),

                TextColumn::make('paymentMethod.name')
                    ->label(__('forms.labels.method'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reference')
                    ->label(__('forms.labels.reference'))
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('creator.name')
                    ->label(__('forms.labels.created_by'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('expense_date', 'desc')
            ->groups([
                Group::make('category')
                    ->label(__('forms.labels.category'))
                    ->collapsible(),
                Group::make('expense_date')
                    ->label(__('forms.labels.month'))
                    ->date('F Y')
                    ->collapsible(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('forms.labels.status'))
                    ->options(ExpenseApprovalStatus::class),

                SelectFilter::make('category')
                    ->label(__('forms.labels.category'))
                    ->options(ExpenseCategory::class)
                    ->multiple(),

                Filter::make('current_month')
                    ->label(__('forms.labels.current_month'))
                    ->query(fn (Builder $query) => $query
                        ->whereYear('expense_date', now()->year)
                        ->whereMonth('expense_date', now()->month))
                    ->toggle()
                    ->default(),

                SelectFilter::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->options(fn () => \App\Domain\Settings\Models\Currency::pluck('code', 'code')),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('approve')
                        ->label(__('forms.labels.approve'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn ($record) => $record->status === ExpenseApprovalStatus::PENDING_APPROVAL
                            && auth()->user()?->can('approve-company-expenses'))
                        ->action(function ($record) {
                            app(ApproveCompanyExpenseAction::class)->approve($record);
                            Notification::make()->title(__('messages.expense_approved'))->success()->send();
                        }),
                    Action::make('reject')
                        ->label(__('forms.labels.reject'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->form([
                            Textarea::make('reason')
                                ->label(__('forms.labels.rejection_reason'))
                                ->rows(2)
                                ->required(),
                        ])
                        ->visible(fn ($record) => $record->status === ExpenseApprovalStatus::PENDING_APPROVAL
                            && auth()->user()?->can('approve-company-expenses'))
                        ->action(function ($record, array $data) {
                            app(ApproveCompanyExpenseAction::class)->reject($record, $data['reason']);
                            Notification::make()->title(__('messages.expense_rejected'))->danger()->send();
                        }),
                ])
                    ->label(__('forms.labels.change_status'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->size('sm')
                    ->visible(fn ($record) => $record->status === ExpenseApprovalStatus::PENDING_APPROVAL),
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
