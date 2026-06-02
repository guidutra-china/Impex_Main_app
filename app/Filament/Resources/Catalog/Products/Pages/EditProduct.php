<?php

namespace App\Filament\Resources\Catalog\Products\Pages;

use App\Domain\Catalog\Actions\ProductDeletionGuard;
use App\Domain\Catalog\Actions\SyncProductPrimaryImageAction;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Filament\Resources\Catalog\Products\Concerns\ManagesProductGallery;
use App\Filament\Resources\Catalog\Products\ProductResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class EditProduct extends EditRecord
{
    use ManagesProductGallery;

    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillGalleryImages($data);
    }

    protected function afterSave(): void
    {
        $this->syncProductGallery($this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [
            ReplicateAction::make()
                ->label('Clone Product')
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
                ->mutateRecordDataUsing(function (array $data): array {
                    $data['name'] = $data['name'].' (Copy)';
                    $data['status'] = ProductStatus::DRAFT->value;
                    $data['sku'] = null;

                    return $data;
                })
                ->beforeReplicaSaved(function (Model $replica): void {
                    $replica->sku = null;
                    $replica->status = ProductStatus::DRAFT;
                })
                ->after(function (Model $replica): void {
                    $original = $this->getRecord();

                    // Replicate specification
                    if ($original->specification) {
                        $specData = $original->specification->replicate(['id', 'product_id'])->toArray();
                        $replica->specification()->create($specData);
                    }

                    // Replicate packaging
                    if ($original->packaging) {
                        $packData = $original->packaging->replicate(['id', 'product_id'])->toArray();
                        $replica->packaging()->create($packData);
                    }

                    // Replicate costing
                    if ($original->costing) {
                        $costData = $original->costing->replicate(['id', 'product_id'])->toArray();
                        $replica->costing()->create($costData);
                    }

                    // Replicate gallery images (file paths are shared safely —
                    // the orphan guard keeps the file while any row references it).
                    foreach ($original->images as $image) {
                        $replica->images()->create([
                            'disk' => $image->disk,
                            'path' => $image->path,
                            'sort_order' => $image->sort_order,
                            'is_primary' => $image->is_primary,
                            'original_name' => $image->original_name,
                            'size' => $image->size,
                        ]);
                    }
                    app(SyncProductPrimaryImageAction::class)->execute($replica);

                    // Replicate tags
                    if ($original->tags->isNotEmpty()) {
                        $replica->tags()->sync($original->tags->pluck('id'));
                    }

                    // Replicate attribute values
                    foreach ($original->attributeValues as $attrValue) {
                        $replica->attributeValues()->create([
                            'category_attribute_id' => $attrValue->category_attribute_id,
                            'value' => $attrValue->value,
                        ]);
                    }

                    // Replicate company relationships (suppliers and clients)
                    foreach ($original->companies as $company) {
                        $pivotData = collect($company->pivot->toArray())
                            ->except(['product_id', 'company_id', 'created_at', 'updated_at'])
                            ->toArray();

                        $replica->companies()->attach($company->id, $pivotData);
                    }

                    Notification::make()
                        ->title('Product cloned successfully')
                        ->body("New product '{$replica->name}' created as DRAFT with SKU: {$replica->sku}")
                        ->success()
                        ->send();
                })
                ->successRedirectUrl(fn (Model $replica): string => ProductResource::getUrl('edit', ['record' => $replica]))
                ->successNotificationTitle('Product cloned — redirecting to edit page'),
            DeleteAction::make()
                ->before(function (Action $action) {
                    /** @var Product $product */
                    $product = $this->getRecord();
                    $blocking = app(ProductDeletionGuard::class)->check($product);

                    if ($blocking->isNotEmpty()) {
                        Notification::make()
                            ->danger()
                            ->title('Cannot delete product')
                            ->body('Referenced in active documents: '.$blocking->unique()->implode(', '))
                            ->persistent()
                            ->send();

                        $action->cancel();
                    }
                }),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    #[On('product-name-updated')]
    public function reloadName(): void
    {
        $this->refreshFormData(['name']);
    }

    protected function getRedirectUrl(): string
    {
        // Stay on the edit page after saving instead of redirecting to the list.
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
