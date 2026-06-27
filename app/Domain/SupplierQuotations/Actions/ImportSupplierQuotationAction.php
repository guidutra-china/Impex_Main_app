<?php

declare(strict_types=1);

namespace App\Domain\SupplierQuotations\Actions;

use App\Domain\Catalog\Actions\GenerateProductSkuAction;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Enums\DocumentSourceType;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Commits a resolved supplier-quotation preview: finds/creates the supplier, finds/
 * creates+links products, creates the SupplierQuotation + items, and attaches the
 * source file. Deterministic, transactional, permission-gated — triggered by the
 * user's explicit confirmation, never by the model.
 */
class ImportSupplierQuotationAction
{
    public function __construct(private readonly GenerateProductSkuAction $skuGenerator = new GenerateProductSkuAction) {}

    /**
     * @param  array<string,mixed>  $preview  output of ResolveSupplierQuotationDraft
     */
    public function __invoke(array $preview, User $user, string $filePath): SupplierQuotation
    {
        $this->authorize($preview, $user);

        return DB::transaction(function () use ($preview, $user, $filePath) {
            $company = $this->resolveSupplier($preview['fornecedor']);

            $sq = SupplierQuotation::create([
                'company_id' => $company->id,
                'status' => SupplierQuotationStatus::RECEIVED,
                'currency_code' => $preview['cabecalho']['currency_code'] ?? 'USD',
                'supplier_reference' => $preview['cabecalho']['supplier_reference'] ?? null,
                'incoterm' => $preview['cabecalho']['incoterm'] ?? null,
                'lead_time_days' => $preview['cabecalho']['lead_time_days'] ?? null,
                'moq' => $preview['cabecalho']['moq'] ?? null,
                'valid_until' => $preview['cabecalho']['valid_until'] ?? null,
                'notes' => $preview['cabecalho']['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $createdByRef = [];

            foreach (array_values($preview['itens']) as $index => $item) {
                $product = $this->resolveProduct($item, $company, $createdByRef);

                SupplierQuotationItem::create([
                    'supplier_quotation_id' => $sq->id,
                    'product_id' => $product?->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    // `unit` is NOT NULL; the extractor may omit it, so fall back to a neutral default.
                    'unit' => ($item['unit'] ?? null) ?: 'pcs',
                    'unit_cost' => $item['unit_cost_minor'],
                    'specifications' => $item['specifications'] ?? null,
                    'moq' => $item['moq'] ?? null,
                    'lead_time_days' => $item['lead_time_days'] ?? null,
                    'notes' => $item['notes'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            $this->attachSourceFile($sq, $filePath, $user);

            activity('ai-assistant')
                ->causedBy($user)
                ->performedOn($sq)
                ->withProperties(['itens' => count($preview['itens'])])
                ->log('supplier_quotation_imported');

            return $sq;
        });
    }

    /**
     * @param  array<string,mixed>  $preview
     */
    private function authorize(array $preview, User $user): void
    {
        if (! $user->can('create-supplier-quotations')) {
            throw new AuthorizationException(__('assistant.perm_supplier_quotations'));
        }

        if (($preview['fornecedor']['status'] ?? null) === 'novo' && ! $user->can('create-companies')) {
            throw new AuthorizationException(__('assistant.perm_companies'));
        }

        $hasNewProduct = collect($preview['itens'] ?? [])->contains(fn ($i) => ($i['status'] ?? null) === 'novo');
        if ($hasNewProduct && ! $user->can('create-products')) {
            throw new AuthorizationException(__('assistant.perm_products'));
        }
    }

    /**
     * @param  array<string,mixed>  $fornecedor
     */
    private function resolveSupplier(array $fornecedor): Company
    {
        if (! empty($fornecedor['company_id'])) {
            return Company::findOrFail($fornecedor['company_id']);
        }

        return Company::create(['name' => $fornecedor['nome']]);
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string,Product>  $createdByRef
     */
    private function resolveProduct(array $item, Company $company, array &$createdByRef): ?Product
    {
        if (! empty($item['product_id'])) {
            $product = Product::find($item['product_id']);
            $this->linkSupplier($product, $company);

            return $product;
        }

        $partNo = $item['part_no'] ?? null;

        if ($partNo !== null && isset($createdByRef[$partNo])) {
            return $createdByRef[$partNo];
        }

        $categoryId = $item['category_id'] ?? null;

        $product = Product::create([
            'name' => Str::limit((string) $item['description'], 250, ''),
            // Prefixed SKU when the product lands in a matched category; draft SKU otherwise.
            'sku' => $categoryId
                ? $this->skuGenerator->execute((int) $categoryId)
                : $this->skuGenerator->generateDraftSku(),
            'reference_code' => $partNo,
            'model_number' => $partNo,
            'category_id' => $categoryId,
            'status' => 'draft',
        ]);

        $this->linkSupplier($product, $company);

        if ($partNo !== null) {
            $createdByRef[$partNo] = $product;
        }

        return $product;
    }

    private function linkSupplier(?Product $product, Company $company): void
    {
        if ($product === null) {
            return;
        }

        $alreadyLinked = $product->suppliers()->where('companies.id', $company->id)->exists();

        if (! $alreadyLinked) {
            $product->companies()->attach($company->id, ['role' => 'supplier']);
        }
    }

    private function attachSourceFile(SupplierQuotation $sq, string $filePath, User $user): void
    {
        $disk = 'local';
        $name = basename($filePath);
        $stored = "supplier-quotations/{$sq->id}/{$name}";
        Storage::disk($disk)->put($stored, (string) file_get_contents($filePath));

        $sq->documents()->create([
            'type' => 'supplier_quotation_source',
            'name' => $name,
            'disk' => $disk,
            'path' => $stored,
            'version' => 1,
            'source' => DocumentSourceType::UPLOADED,
            'mime_type' => Storage::disk($disk)->mimeType($stored),
            'size' => Storage::disk($disk)->size($stored),
            'checksum' => hash_file('sha256', Storage::disk($disk)->path($stored)),
            'created_by' => $user->id,
        ]);
    }
}
