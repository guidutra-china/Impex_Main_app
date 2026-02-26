<?php

namespace App\Filament\Resources\Finance\CompanyExpenses\Tables;

use App\Domain\Finance\Enums\ExpenseCategory;
use App\Domain\Infrastructure\Support\Money;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
                SelectFilter::make('category')
                    ->label(__('forms.labels.category'))
                    ->options(ExpenseCategory::class)
                    ->multiple(),

                Filter::make('is_recurring')
                    ->label(__('forms.labels.recurring_only'))
                    ->query(fn (Builder $query) => $query->where('is_recurring', true))
                    ->toggle(),

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
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
