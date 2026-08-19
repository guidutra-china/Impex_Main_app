<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use Illuminate\Support\Str;

class CreateDraftProductForSupplierAction
{
    public function __construct(
        private readonly GenerateProductSkuAction $skuGenerator = new GenerateProductSkuAction,
    ) {}

    /**
     * Cria um produto rascunho a partir da descrição do fornecedor e garante o
     * pivot de fornecedor. `model_number` NÃO recebe o código do fornecedor: ele
     * é o 2º nível da identidade do CLIENTE e apareceria na fatura dele.
     */
    public function execute(
        string $description,
        Company $supplier,
        ?string $externalCode = null,
        ?int $categoryId = null,
    ): Product {
        $product = Product::create([
            'name' => Str::limit($description, 250, ''),
            'sku' => $categoryId
                ? $this->skuGenerator->execute($categoryId)
                : $this->skuGenerator->generateDraftSku(),
            'reference_code' => $externalCode,
            'category_id' => $categoryId,
            'status' => ProductStatus::DRAFT,
        ]);

        $this->linkSupplier($product, $supplier, $externalCode);

        return $product;
    }

    /**
     * Garante o pivot role=supplier sem sobrescrever um código já gravado. A
     * mesma empresa pode ter também uma linha de cliente para este produto,
     * que não pode ser tocada.
     */
    public function linkSupplier(Product $product, Company $supplier, ?string $externalCode = null): void
    {
        $existing = $product->suppliers()->where('companies.id', $supplier->id)->first();

        if (! $existing) {
            $product->companies()->attach($supplier->id, [
                'role' => 'supplier',
                'external_code' => $externalCode,
            ]);

            return;
        }

        if (filled($externalCode) && blank($existing->pivot->external_code)) {
            $existing->pivot->update(['external_code' => $externalCode]);
        }
    }
}
