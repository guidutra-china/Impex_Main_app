<?php

namespace App\Filament\Resources\Catalog\Products\Pages;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Services\ProductNameGenerator;
use App\Filament\Resources\Catalog\Products\ProductResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function afterCreate(): void
    {
        $product = $this->record;

        if (! $product->category_id) {
            return;
        }

        $category = Category::find($product->category_id);

        if (! $category) {
            return;
        }

        $allAttributes = $category->getAllAttributes();

        if ($allAttributes->isEmpty()) {
            return;
        }

        $count = 0;

        foreach ($allAttributes as $attribute) {
            $product->attributeValues()->create([
                'category_attribute_id' => $attribute->id,
                'value' => $attribute->default_value ?? '',
            ]);
            $count++;
        }

        ProductNameGenerator::updateProductName($product);

        Notification::make()
            ->title(__('messages.attributes_added'))
            ->body("Added {$count} attributes from category hierarchy. Edit them in the Attributes tab.")
            ->success()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
