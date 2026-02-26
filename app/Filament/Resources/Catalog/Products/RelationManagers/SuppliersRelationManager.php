<?php

namespace App\Filament\Resources\Catalog\Products\RelationManagers;

use BackedEnum;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use App\Domain\Infrastructure\Support\Money;
use Filament\Tables\Table;

class SuppliersRelationManager extends RelationManager
{
    protected static string $relationship = 'suppliers';

    protected static ?string $title = 'Suppliers';

    protected static ?string $recordTitleAttribute = 'name';

    protected static BackedEnum|string|null $icon = 'heroicon-o-truck';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('external_code')
                    ->label(__('forms.labels.supplier_code'))
                    ->maxLength(100)
                    ->helperText("Supplier's internal code for this product."),
                TextInput::make('external_name')
                    ->label(__('forms.labels.supplier_name_for_product'))
                    ->maxLength(255)
                    ->helperText("How the supplier calls this product."),
                Textarea::make('external_description')
                    ->label(__('forms.labels.supplier_product_description'))
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText(__('forms.helpers.product_description_as_used_by_the_supplier_will_appear_on'))
                    ->columnSpanFull(),
                TextInput::make('unit_price')
                    ->label(__('forms.labels.unit_price'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->prefix('$')
                    ->inputMode('decimal'),
                Select::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->options(fn () => \App\Domain\Settings\Models\Currency::pluck('code', 'code'))
                    ->searchable(),
                TextInput::make('lead_time_days')
                    ->label(__('forms.labels.lead_time_days'))
                    ->numeric()
                    ->minValue(0),
                TextInput::make('moq')
                    ->label(__('forms.labels.moq'))
                    ->numeric()
                    ->minValue(1),
                Checkbox::make('is_preferred')
                    ->label(__('forms.labels.preferred_supplier')),
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
                    ->label(__('forms.labels.supplier'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('pivot.external_code')
                    ->label(__('forms.labels.supplier_code'))
                    ->placeholder('—'),
                TextColumn::make('pivot.unit_price')
                    ->label(__('forms.labels.unit_price'))
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state, 4) : '—')
                    ->prefix('$ ')
                    ->alignEnd(),
                TextColumn::make('pivot.currency_code')
                    ->label(__('forms.labels.currency')),
                TextColumn::make('pivot.moq')
                    ->label(__('forms.labels.moq'))
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('pivot.lead_time_days')
                    ->label(__('forms.labels.lead_time'))
                    ->suffix(' days'),
                TextColumn::make('pivot.is_preferred')
                    ->label(__('forms.labels.preferred'))
                    ->formatStateUsing(fn ($state) => $state ? '★ Preferred' : '')
                    ->badge()
                    ->color(fn ($state) => $state ? 'warning' : 'gray')
                    ->alignCenter(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label(__('forms.labels.add_supplier'))
                    ->visible(fn () => auth()->user()?->can('edit-products'))
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'legal_name'])
                    ->recordSelectOptionsQuery(
                        fn ($query) => $query->whereHas('companyRoles', fn ($q) => $q->where('role', \App\Domain\CRM\Enums\CompanyRole::SUPPLIER))
                    )
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        TextInput::make('external_code')
                            ->label(__('forms.labels.supplier_code'))
                            ->maxLength(100),
                        TextInput::make('external_name')
                            ->label(__('forms.labels.supplier_product_name'))
                            ->maxLength(255),
                        Textarea::make('external_description')
                            ->label(__('forms.labels.supplier_product_description'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText(__('forms.helpers.will_appear_on_invoices')),
                        TextInput::make('unit_price')
                            ->label(__('forms.labels.unit_price'))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->prefix('$')
                            ->inputMode('decimal')
                            ->default(0),
                        Select::make('currency_code')
                            ->label(__('forms.labels.currency'))
                            ->options(fn () => \App\Domain\Settings\Models\Currency::pluck('code', 'code'))
                            ->searchable(),
                        TextInput::make('moq')
                            ->label(__('forms.labels.moq'))
                            ->numeric()
                            ->minValue(1),
                        TextInput::make('lead_time_days')
                            ->label(__('forms.labels.lead_time_days'))
                            ->numeric()
                            ->minValue(0),
                        Checkbox::make('is_preferred')
                            ->label(__('forms.labels.preferred_supplier')),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['role'] = 'supplier';
                        $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);
                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-products'))
                    ->mountUsing(function ($form, $record) {
                        $data = $record->pivot->toArray();
                        $data['unit_price'] = Money::toMajor($data['unit_price'] ?? 0);
                        $form->fill($data);
                    })
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);
                        return $data;
                    }),
                DetachAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-products')),
            ])
            ->toolbarActions([
                DetachBulkAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-products')),
            ]);
    }
}
