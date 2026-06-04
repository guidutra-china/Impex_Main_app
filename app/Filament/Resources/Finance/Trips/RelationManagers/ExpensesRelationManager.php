<?php

namespace App\Filament\Resources\Finance\Trips\RelationManagers;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Settings\Models\Currency;
use App\Domain\Travel\Enums\TravelExpenseCategory;
use App\Domain\Travel\Models\TripExpense;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    protected static BackedEnum|string|null $icon = 'heroicon-o-receipt-percent';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('navigation.models.trip_expenses');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('category')
                ->label(__('forms.labels.category'))
                ->options(TravelExpenseCategory::class)
                ->required()
                ->searchable(),

            DateTimePicker::make('expense_date')
                ->label(__('forms.labels.expense_date'))
                ->required()
                ->seconds(false)
                ->default(now()),

            TextInput::make('description')
                ->label(__('forms.labels.description'))
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
                ->default('CNY'),

            // Receipt photos map to the trip_expense_photos table. The field is
            // not a column on trip_expenses (dehydrated false); persistence is
            // handled by saveRelationshipsUsing after the expense is saved.
            FileUpload::make('receipts')
                ->label(__('forms.labels.receipt_attachment'))
                ->disk('public')
                ->directory('trip-receipts')
                ->image()
                ->multiple()
                ->reorderable()
                ->maxSize(10240)
                ->columnSpanFull()
                ->dehydrated(false)
                ->afterStateHydrated(function (FileUpload $component, ?Model $record) {
                    $component->state($record?->photos->pluck('path')->all() ?? []);
                })
                ->saveRelationshipsUsing(function (?Model $record, $state) {
                    if ($record instanceof TripExpense) {
                        self::syncReceipts($record, (array) $state);
                    }
                }),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('expense_date')
                    ->label(__('forms.labels.date'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('category')
                    ->label(__('forms.labels.category'))
                    ->badge(),

                TextColumn::make('description')
                    ->label(__('forms.labels.description'))
                    ->limit(40)
                    ->placeholder('—'),

                TextColumn::make('amount')
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state, $record) => Money::format($state).' '.$record->currency_code)
                    ->alignEnd(),

                TextColumn::make('photos_count')
                    ->label(__('forms.labels.receipt_attachment'))
                    ->counts('photos')
                    ->badge()
                    ->color('gray'),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label(__('forms.labels.category'))
                    ->options(TravelExpenseCategory::class)
                    ->multiple(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * Persist the receipt FileUpload paths as trip_expense_photos rows,
     * mirroring the company gallery sync pattern (dedup-safe, primary on first).
     *
     * @param  array<int, string>  $paths
     */
    protected static function syncReceipts(TripExpense $expense, array $paths): void
    {
        $paths = array_values(array_filter($paths, fn ($path) => filled($path)));

        foreach ($paths as $index => $path) {
            $expense->photos()->updateOrCreate(
                ['path' => $path],
                ['sort_order' => $index, 'disk' => 'public', 'is_primary' => $index === 0],
            );
        }

        $expense->photos()
            ->when($paths !== [], fn ($query) => $query->whereNotIn('path', $paths))
            ->get()
            ->each
            ->delete();
    }
}
