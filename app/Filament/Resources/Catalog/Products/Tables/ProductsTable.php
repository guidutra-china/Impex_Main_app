<?php

namespace App\Filament\Resources\Catalog\Products\Tables;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCosting;
use App\Domain\Catalog\Models\ProductPackaging;
use App\Domain\Catalog\Models\ProductSpecification;
use App\Filament\Resources\Catalog\Products\ProductResource;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->size(48)
                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?background=e2e8f0&color=94a3b8&name=P&size=48'),
                TextColumn::make('sku')
                    ->label(__('forms.labels.sku'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable()
                    ->size('sm')
                    ->color('gray'),
                TextColumn::make('name')
                    ->label(__('forms.labels.name'))
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->weight('bold')
                    ->description(fn ($record) => $record->commercial_name ?: $record->category?->name),
                TextColumn::make('category.name')
                    ->label(__('forms.labels.category'))
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('model_number')
                    ->label(__('forms.labels.model_number'))
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('product_family')
                    ->label(__('forms.labels.product_family'))
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge(),
                TextColumn::make('costing.base_price')
                    ->label(__('forms.labels.base_price'))
                    ->formatStateUsing(function ($state, $record) {
                        if (! $state) {
                            return '—';
                        }
                        $currency = $record->costing?->currency?->code ?? 'USD';
                        return $currency . ' ' . Money::format($state);
                    })
                    ->sortable()
                    ->alignEnd()
                    ->color(fn ($state) => $state ? null : 'gray'),
                TextColumn::make('suppliers_count')
                    ->label(__('forms.labels.suppliers'))
                    ->counts('suppliers')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'primary' : 'gray'),
                TextColumn::make('clients_count')
                    ->label(__('forms.labels.clients'))
                    ->counts('clients')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('variants_count')
                    ->label(__('forms.labels.variants'))
                    ->counts('variants')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'info' : 'gray'),
                TextColumn::make('parent.name')
                    ->label(__('forms.labels.variant_of'))
                    ->placeholder(__('forms.placeholders.base_product'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('hs_code')
                    ->label(__('forms.labels.hs_code'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('origin_country')
                    ->label(__('forms.labels.origin'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('moq')
                    ->label(__('forms.labels.moq'))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('forms.labels.updated'))
                    ->dateTime('M d, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ProductStatus::class),
                SelectFilter::make('category_id')
                    ->label(__('forms.labels.category'))
                    ->multiple()
                    ->options(
                        fn () => Category::query()
                            ->active()
                            ->with('parent')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Category $cat) => [$cat->id => $cat->full_path])
                            ->sort()
                            ->all()
                    )
                    ->searchable()
                    ->query(function ($query, array $data) {
                        if (! empty($data['values'])) {
                            $query->whereIn('category_id', $data['values']);
                        }
                    }),
                SelectFilter::make('has_suppliers')
                    ->label(__('forms.labels.has_suppliers'))
                    ->options([
                        'yes' => 'With Suppliers',
                        'no' => 'Without Suppliers',
                    ])
                    ->query(function ($query, array $data) {
                        if ($data['value'] === 'yes') {
                            $query->whereHas('suppliers');
                        } elseif ($data['value'] === 'no') {
                            $query->whereDoesntHave('suppliers');
                        }
                    }),
                TrashedFilter::make(),
            ])
            ->groups([
                Group::make('category.name')
                    ->label(__('forms.labels.category'))
                    ->collapsible(),
                Group::make('product_family')
                    ->label(__('forms.labels.product_family'))
                    ->collapsible(),
                Group::make('status')
                    ->label(__('forms.labels.status'))
                    ->collapsible(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ReplicateAction::make()
                    ->label('Clone')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->excludeAttributes([
                        'sku',
                        'avatar',
                        // Virtual withCount attributes injected by Filament's ->counts() on TextColumn.
                        // Eloquent's replicate() copies $attributes including these dynamic keys,
                        // which causes an SQL error since they are not real database columns.
                        'suppliers_count',
                        'clients_count',
                        'variants_count',
                    ])
                    ->beforeReplicaSaved(function (Model $replica): void {
                        $replica->sku = null;
                        $replica->name = $replica->name . ' (Copy)';
                        $replica->status = ProductStatus::DRAFT;
                    })
                    ->after(function (Model $replica): void {
                        $original = Product::with(['specification', 'packaging', 'costing', 'tags', 'attributeValues', 'companies'])
                            ->find($replica->getOriginal('id') ?? $replica->id);

                        // For table ReplicateAction, the original record is not directly available,
                        // so we find it by checking which product has the same name minus ' (Copy)'
                        $originalName = str_replace(' (Copy)', '', $replica->name);
                        $original = Product::with(['specification', 'packaging', 'costing', 'tags', 'attributeValues', 'companies'])
                            ->where('name', $originalName)
                            ->where('id', '!=', $replica->id)
                            ->latest()
                            ->first();

                        if (! $original) {
                            return;
                        }

                        if ($original->specification) {
                            $specData = $original->specification->replicate(['id', 'product_id'])->toArray();
                            $replica->specification()->create($specData);
                        }

                        if ($original->packaging) {
                            $packData = $original->packaging->replicate(['id', 'product_id'])->toArray();
                            $replica->packaging()->create($packData);
                        }

                        if ($original->costing) {
                            $costData = $original->costing->replicate(['id', 'product_id'])->toArray();
                            $replica->costing()->create($costData);
                        }

                        if ($original->tags->isNotEmpty()) {
                            $replica->tags()->sync($original->tags->pluck('id'));
                        }

                        foreach ($original->attributeValues as $attrValue) {
                            $replica->attributeValues()->create([
                                'category_attribute_id' => $attrValue->category_attribute_id,
                                'value' => $attrValue->value,
                            ]);
                        }

                        foreach ($original->companies as $company) {
                            $pivotData = collect($company->pivot->toArray())
                                ->except(['product_id', 'company_id', 'created_at', 'updated_at'])
                                ->toArray();
                            $replica->companies()->attach($company->id, $pivotData);
                        }

                        Notification::make()
                            ->title('Product cloned')
                            ->body("'{$replica->name}' created as DRAFT with SKU: {$replica->sku}")
                            ->success()
                            ->send();
                    })
                    ->successRedirectUrl(fn (Model $replica): string => ProductResource::getUrl('edit', ['record' => $replica]))
                    ->successNotificationTitle('Product cloned'),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::getBulkSetCategoryAction(),
                    static::getBulkSetStatusAction(),
                    static::getBulkSetFieldAction('hs_code', 'Set HS Code', 'heroicon-o-hashtag',
                        TextInput::make('value')->label('HS Code')->required()->maxLength(20)->placeholder('853950'),
                    ),
                    static::getBulkSetFieldAction('origin_country', 'Set Origin Country', 'heroicon-o-globe-alt',
                        TextInput::make('value')->label('Country Code')->required()->maxLength(2)->placeholder('CN'),
                    ),
                    static::getBulkSetFieldAction('moq', 'Set MOQ', 'heroicon-o-cube',
                        TextInput::make('value')->label('MOQ')->numeric()->required()->minValue(1),
                    ),
                    static::getBulkSetFieldAction('lead_time_days', 'Set Lead Time', 'heroicon-o-clock',
                        TextInput::make('value')->label('Lead Time (days)')->numeric()->required()->minValue(0),
                    ),
                    static::getBulkSetFieldAction('brand', 'Set Brand', 'heroicon-o-tag',
                        TextInput::make('value')->label('Brand')->required()->maxLength(255),
                    ),
                    static::getBulkSetFieldAction('product_family', 'Set Product Family', 'heroicon-o-rectangle-group',
                        TextInput::make('value')->label('Product Family')->required()->maxLength(255),
                    ),
                    static::getBulkAttachCompanyAction('client'),
                    static::getBulkAttachCompanyAction('supplier'),
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('updated_at', 'desc')
            ->striped()
            ->emptyStateHeading('No products')
            ->emptyStateDescription('Create your first product to start building your catalog.')
            ->emptyStateIcon('heroicon-o-cube');
    }

    private static function getBulkSetCategoryAction(): BulkAction
    {
        return BulkAction::make('set_category')
            ->label('Set Category')
            ->icon('heroicon-o-folder')
            ->form([
                Select::make('category_id')
                    ->label('Category')
                    ->options(
                        fn () => Category::query()
                            ->active()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Category $cat) => [$cat->id => $cat->full_path])
                    )
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $updated = $records->count();
                Product::whereIn('id', $records->pluck('id'))->update(['category_id' => $data['category_id']]);

                $category = Category::find($data['category_id']);
                Notification::make()
                    ->success()
                    ->title("Updated {$updated} products")
                    ->body("Category set to {$category?->name}")
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function getBulkSetStatusAction(): BulkAction
    {
        return BulkAction::make('set_status')
            ->label('Set Status')
            ->icon('heroicon-o-signal')
            ->form([
                Select::make('status')
                    ->label('Status')
                    ->options(ProductStatus::class)
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $updated = $records->count();
                Product::whereIn('id', $records->pluck('id'))->update(['status' => $data['status']]);

                Notification::make()
                    ->success()
                    ->title("Updated {$updated} products")
                    ->body("Status set to {$data['status']}")
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function getBulkAttachCompanyAction(string $role): BulkAction
    {
        $isClient = $role === 'client';
        $label = $isClient ? 'Attach Client' : 'Attach Supplier';
        $icon = $isClient ? 'heroicon-o-user-group' : 'heroicon-o-truck';
        $companyRole = $isClient ? CompanyRole::CLIENT : CompanyRole::SUPPLIER;

        return BulkAction::make('attach_' . $role)
            ->label($label)
            ->icon($icon)
            ->form([
                Select::make('company_id')
                    ->label($isClient ? 'Client' : 'Supplier')
                    ->options(
                        fn () => Company::whereHas('companyRoles', fn ($q) => $q->where('role', $companyRole))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                    )
                    ->searchable()
                    ->required(),
                TextInput::make('unit_price')
                    ->label($isClient ? 'Selling Price' : 'Unit Cost')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->prefix('$')
                    ->inputMode('decimal')
                    ->helperText('Leave empty to set individually later.'),
            ])
            ->action(function (Collection $records, array $data) use ($role, $label): void {
                $companyId = $data['company_id'];
                $unitPrice = ! empty($data['unit_price'])
                    ? Money::toMinor((float) $data['unit_price'])
                    : 0;

                $attached = 0;
                $skipped = 0;

                foreach ($records as $product) {
                    $exists = $product->companies()
                        ->where('companies.id', $companyId)
                        ->wherePivot('role', $role)
                        ->exists();

                    if ($exists) {
                        $skipped++;
                        continue;
                    }

                    $product->companies()->attach($companyId, [
                        'role' => $role,
                        'unit_price' => $unitPrice,
                    ]);
                    $attached++;
                }

                $company = Company::find($companyId);
                Notification::make()
                    ->success()
                    ->title("{$attached} products linked")
                    ->body("{$label}: {$company?->name}" . ($skipped > 0 ? " ({$skipped} already linked)" : ''))
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function getBulkSetFieldAction(string $column, string $label, string $icon, TextInput $field): BulkAction
    {
        return BulkAction::make('set_' . $column)
            ->label($label)
            ->icon($icon)
            ->form([$field])
            ->action(function (Collection $records, array $data) use ($column, $label): void {
                $updated = $records->count();
                Product::whereIn('id', $records->pluck('id'))->update([$column => $data['value']]);

                Notification::make()
                    ->success()
                    ->title("Updated {$updated} products")
                    ->body("{$label}: {$data['value']}")
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
