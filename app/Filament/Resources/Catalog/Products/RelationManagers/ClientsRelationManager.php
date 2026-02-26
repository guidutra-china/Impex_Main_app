<?php

namespace App\Filament\Resources\Catalog\Products\RelationManagers;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Infrastructure\Support\Money;
use BackedEnum;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ClientsRelationManager extends RelationManager
{
    protected static string $relationship = 'clients';

    protected static ?string $title = 'Clients';

    protected static ?string $recordTitleAttribute = 'name';

    protected static BackedEnum|string|null $icon = 'heroicon-o-user-group';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('external_code')
                    ->label(__('forms.labels.client_code'))
                    ->maxLength(100)
                    ->helperText("Client's internal code for this product."),
                TextInput::make('external_name')
                    ->label(__('forms.labels.client_name_for_product'))
                    ->maxLength(255)
                    ->helperText("How the client calls this product."),
                Textarea::make('external_description')
                    ->label(__('forms.labels.client_product_description'))
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText(__('forms.helpers.product_description_as_used_by_the_client_will_appear_on'))
                    ->columnSpanFull(),
                TextInput::make('unit_price')
                    ->label(__('forms.labels.selling_price'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->prefix('$')
                    ->inputMode('decimal'),
                TextInput::make('custom_price')
                    ->label(__('forms.labels.custom_price_ci_override'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->prefix('$')
                    ->inputMode('decimal')
                    ->helperText(__('forms.helpers.if_set_commercial_invoice_uses_this_instead_of_pi_price')),
                Select::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->options(fn () => \App\Domain\Settings\Models\Currency::pluck('code', 'code'))
                    ->searchable(),
                Checkbox::make('is_preferred')
                    ->label(__('forms.labels.primary_client')),
                Textarea::make('notes')
                    ->label(__('forms.labels.notes'))
                    ->rows(2)
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('forms.labels.client'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('pivot.external_code')
                    ->label(__('forms.labels.client_code'))
                    ->placeholder('—'),
                TextColumn::make('pivot.external_name')
                    ->label(__('forms.labels.client_product_name'))
                    ->placeholder('—'),
                TextColumn::make('pivot.unit_price')
                    ->label(__('forms.labels.selling_price'))
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state, 4) : '—')
                    ->prefix('$ ')
                    ->alignEnd(),
                TextColumn::make('pivot.custom_price')
                    ->label(__('forms.labels.ci_price'))
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state, 4) : '—')
                    ->prefix('$ ')
                    ->alignEnd(),
                TextColumn::make('pivot.currency_code')
                    ->label(__('forms.labels.currency')),
                TextColumn::make('pivot.is_preferred')
                    ->label(__('forms.labels.primary'))
                    ->formatStateUsing(fn ($state) => $state ? '★ Primary' : '')
                    ->badge()
                    ->color(fn ($state) => $state ? 'warning' : 'gray')
                    ->alignCenter(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label(__('forms.labels.add_client'))
                    ->visible(fn () => auth()->user()?->can('edit-products'))
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'legal_name'])
                    ->recordSelectOptionsQuery(
                        fn ($query) => $query->whereHas('companyRoles', fn ($q) => $q->where('role', \App\Domain\CRM\Enums\CompanyRole::CLIENT))
                    )
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        TextInput::make('external_code')
                            ->label(__('forms.labels.client_code'))
                            ->maxLength(100),
                        TextInput::make('external_name')
                            ->label(__('forms.labels.client_product_name'))
                            ->maxLength(255),
                        Textarea::make('external_description')
                            ->label(__('forms.labels.client_product_description'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText(__('forms.helpers.will_appear_on_invoices')),
                        TextInput::make('unit_price')
                            ->label(__('forms.labels.selling_price'))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->prefix('$')
                            ->inputMode('decimal')
                            ->default(0),
                        TextInput::make('custom_price')
                            ->label(__('forms.labels.custom_price_ci_override'))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->prefix('$')
                            ->inputMode('decimal')
                            ->helperText(__('forms.helpers.if_set_ci_uses_this_price')),
                        Select::make('currency_code')
                            ->label(__('forms.labels.currency'))
                            ->options(fn () => \App\Domain\Settings\Models\Currency::pluck('code', 'code'))
                            ->searchable(),
                        Checkbox::make('is_preferred')
                            ->label(__('forms.labels.primary_client')),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['role'] = 'client';
                        $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);
                        $data['custom_price'] = filled($data['custom_price'] ?? null)
                            ? Money::toMinor($data['custom_price'])
                            : null;
                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-products'))
                    ->mountUsing(function ($form, $record) {
                        $data = $record->pivot->toArray();
                        $data['unit_price'] = Money::toMajor($data['unit_price'] ?? 0);
                        $data['custom_price'] = filled($data['custom_price'] ?? null)
                            ? Money::toMajor($data['custom_price'])
                            : null;
                        $form->fill($data);
                    })
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);
                        $data['custom_price'] = filled($data['custom_price'] ?? null)
                            ? Money::toMinor($data['custom_price'])
                            : null;
                        return $data;
                    }),
                DetachAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-products')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    $this->getBulkPriceUpdateAction('selling_price', 'unit_price', 'Adjust Selling Price'),
                    $this->getBulkPriceUpdateAction('custom_price', 'custom_price', 'Adjust CI Price'),
                    DetachBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('edit-products')),
                ]),
            ]);
    }

    private function getBulkPriceUpdateAction(string $name, string $column, string $label): BulkAction
    {
        return BulkAction::make($name)
            ->label($label)
            ->icon('heroicon-o-calculator')
            ->form([
                TextInput::make('formula')
                    ->label(__('forms.labels.formula'))
                    ->required()
                    ->placeholder(__('forms.placeholders.eg_110_or_5_or_250'))
                    ->helperText(__('forms.helpers.apply_to_current_value_110_10_105_5_5_add_5_250_subtract_250')),
            ])
            ->action(function (Collection $records, array $data) use ($column): void {
                $formula = trim($data['formula']);

                if (! preg_match('/^[\*\/\+\-]\s*[\d\.]+$/', $formula)) {
                    Notification::make()
                        ->danger()
                        ->title(__('messages.invalid_formula'))
                        ->body(__('messages.formula_format_help'))
                        ->send();
                    return;
                }

                $operator = $formula[0];
                $operand = (float) trim(substr($formula, 1));
                $updated = 0;

                foreach ($records as $record) {
                    $currentMinor = $record->pivot->{$column} ?? 0;
                    $currentMajor = Money::toMajor($currentMinor);

                    $newMajor = match ($operator) {
                        '*' => $currentMajor * $operand,
                        '/' => $operand > 0 ? $currentMajor / $operand : $currentMajor,
                        '+' => $currentMajor + $operand,
                        '-' => $currentMajor - $operand,
                    };

                    $newMinor = Money::toMinor(max(0, $newMajor));

                    CompanyProduct::where('company_id', $record->pivot->company_id)
                        ->where('product_id', $record->pivot->product_id)
                        ->update([$column => $newMinor]);

                    $updated++;
                }

                Notification::make()
                    ->success()
                    ->title("Updated {$updated} records")
                    ->body("Applied formula: {$formula}")
                    ->send();
            })
            ->deselectRecordsAfterCompletion()
            ->requiresConfirmation()
            ->modalDescription('This will apply the formula to all selected records. This action cannot be undone.');
    }
}
