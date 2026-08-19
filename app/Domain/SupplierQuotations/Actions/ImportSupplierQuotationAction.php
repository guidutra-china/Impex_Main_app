<?php

declare(strict_types=1);

namespace App\Domain\SupplierQuotations\Actions;

use App\Domain\AI\Import\UpsertProductImportAliasAction;
use App\Domain\Catalog\Actions\CreateDraftProductForSupplierAction;
use App\Domain\Catalog\Actions\GenerateProductSkuAction;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\CRM\Actions\ResolveOrCreateCompanyAction;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Enums\DocumentSourceType;
use App\Domain\Inquiries\Models\Inquiry;
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
    public function __construct(
        private readonly GenerateProductSkuAction $skuGenerator = new GenerateProductSkuAction,
        private readonly UpsertProductImportAliasAction $aliasUpserter = new UpsertProductImportAliasAction,
        private readonly CreateDraftProductForSupplierAction $productCreator = new CreateDraftProductForSupplierAction,
    ) {}

    /**
     * @param  array<string,mixed>  $preview  output of ResolveSupplierQuotationDraft
     * @param  ?int  $inquiryId  vincula a SQ criada a uma inquiry (entrada do
     *                           assistant a partir de uma Inquiry)
     */
    public function __invoke(array $preview, User $user, string $filePath, array $images = [], ?int $inquiryId = null): SupplierQuotation
    {
        $this->authorize($preview, $user);

        // Valida fora da transação: id inválido deve falhar antes de qualquer escrita.
        $inquiry = $inquiryId !== null ? Inquiry::findOrFail($inquiryId) : null;

        $storedPath = null;

        try {
            return DB::transaction(function () use ($preview, $user, $filePath, $images, $inquiry, &$storedPath) {
                $company = $this->resolveSupplier($preview['fornecedor'], $preview['fornecedor_dados'] ?? []);

                $sq = SupplierQuotation::create([
                    'inquiry_id' => $inquiry?->id,
                    'description' => $inquiry?->description,
                    'company_id' => $company->id,
                    'status' => SupplierQuotationStatus::RECEIVED,
                    'currency_code' => $this->cap($preview['cabecalho']['currency_code'] ?? 'USD', 10),
                    'supplier_reference' => $this->cap($preview['cabecalho']['supplier_reference'] ?? null, 100),
                    'incoterm' => $this->cap($preview['cabecalho']['incoterm'] ?? null, 20),
                    'lead_time_days' => $preview['cabecalho']['lead_time_days'] ?? null,
                    'moq' => $preview['cabecalho']['moq'] ?? null,
                    'valid_until' => $preview['cabecalho']['valid_until'] ?? null,
                    'notes' => $preview['cabecalho']['notes'] ?? null,
                    'created_by' => $user->id,
                ]);

                $createdByRef = [];

                foreach (array_values($preview['itens']) as $index => $item) {
                    $product = $this->resolveProduct($item, $company, $createdByRef);

                    $this->attachProductPhoto($product, $this->photoItemKey($item, $index), $images);

                    SupplierQuotationItem::create([
                        'supplier_quotation_id' => $sq->id,
                        'product_id' => $product?->id,
                        'description' => $this->cap($item['description'], 500),
                        'quantity' => $item['quantity'],
                        // `unit` is NOT NULL; the extractor may omit it, so fall back to a neutral default.
                        'unit' => $this->cap(($item['unit'] ?? null) ?: 'pcs', 20),
                        'unit_cost' => $item['unit_cost_minor'],
                        'specifications' => $item['specifications'] ?? null,
                        'moq' => $item['moq'] ?? null,
                        'lead_time_days' => $item['lead_time_days'] ?? null,
                        'notes' => $item['notes'] ?? null,
                        'sort_order' => $index,
                    ]);

                    if ($product !== null) {
                        $this->aliasUpserter->execute($company->id, $product->id, (string) $item['description'], 'import_confirm', $user->id);
                    }
                }

                $this->attachSourceFile($sq, $filePath, $user, $storedPath);

                activity('ai-assistant')
                    ->causedBy($user)
                    ->performedOn($sq)
                    ->withProperties(['itens' => count($preview['itens'])])
                    ->log('supplier_quotation_imported');

                return $sq;
            });
        } catch (\Throwable $e) {
            // Storage writes are not transactional: the rollback undid the quotation and
            // its Document row, so delete the copied source file too.
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $e;
        }
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
     * @param  array<string,mixed>  $dados  contact fields (fornecedor_dados)
     */
    private function resolveSupplier(array $fornecedor, array $dados): Company
    {
        $dados = $this->sanitizeContact($dados);

        $contactFields = [
            'legal_name', 'tax_number', 'phone', 'email', 'website',
            'address_street', 'address_number', 'address_complement',
            'address_city', 'address_state', 'address_zip', 'address_country',
        ];

        if (! empty($fornecedor['company_id'])) {
            $company = Company::findOrFail($fornecedor['company_id']);

            foreach ($contactFields as $field) {
                if (blank($company->{$field}) && filled($dados[$field] ?? null)) {
                    $company->{$field} = $dados[$field];
                }
            }
            $company->save();
        } else {
            $attributes = [];
            foreach ($contactFields as $field) {
                if (filled($dados[$field] ?? null)) {
                    $attributes[$field] = $dados[$field];
                }
            }
            // Reuse an existing company on tax_number/name match instead of
            // duplicating it; blank fields are filled from the extracted data.
            $company = app(ResolveOrCreateCompanyAction::class)
                ->execute((string) $fornecedor['nome'], $attributes)
                ->company;
        }

        $this->ensureSupplierRole($company);

        return $company;
    }

    /**
     * Cap contact fields to their DB column limits and normalize the country to an
     * ISO 3166-1 alpha-2 code, so over-long extracted values never break the insert
     * (the `companies` columns are mostly varchar(255); `address_zip` is 20 and
     * `address_country` is 2).
     *
     * @param  array<string,mixed>  $dados
     * @return array<string,mixed>
     */
    private function sanitizeContact(array $dados): array
    {
        $maxLengths = [
            'legal_name' => 255, 'tax_number' => 255, 'phone' => 255, 'email' => 255,
            'website' => 255, 'address_street' => 255, 'address_number' => 255,
            'address_complement' => 255, 'address_city' => 255, 'address_state' => 255,
            'address_zip' => 20,
        ];

        foreach ($maxLengths as $field => $max) {
            if (filled($dados[$field] ?? null)) {
                $dados[$field] = mb_substr((string) $dados[$field], 0, $max);
            }
        }

        if (filled($dados['address_country'] ?? null)) {
            $dados['address_country'] = $this->toCountryCode((string) $dados['address_country']);
        }

        return $dados;
    }

    /**
     * Normalize a country to ISO 3166-1 alpha-2. Returns null (field dropped) when
     * it can't be resolved, to avoid storing garbage in the 2-char column.
     */
    private function toCountryCode(string $value): ?string
    {
        $value = trim($value);

        if (strlen($value) === 2) {
            return strtoupper($value);
        }

        $map = [
            'china' => 'CN', 'brazil' => 'BR', 'brasil' => 'BR', 'united states' => 'US',
            'united states of america' => 'US', 'usa' => 'US', 'india' => 'IN',
            'vietnam' => 'VN', 'viet nam' => 'VN', 'germany' => 'DE', 'italy' => 'IT',
            'taiwan' => 'TW', 'hong kong' => 'HK', 'south korea' => 'KR', 'korea' => 'KR',
            'japan' => 'JP', 'thailand' => 'TH', 'turkey' => 'TR', 'turkiye' => 'TR',
            'spain' => 'ES', 'france' => 'FR', 'united kingdom' => 'GB', 'uk' => 'GB',
            'mexico' => 'MX', 'canada' => 'CA', 'indonesia' => 'ID', 'malaysia' => 'MY',
            'poland' => 'PL', 'portugal' => 'PT',
        ];

        return $map[mb_strtolower($value)] ?? null;
    }

    /** Cap a string to a column max length (null-safe). */
    private function cap(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }

    private function ensureSupplierRole(Company $company): void
    {
        $exists = $company->companyRoles()->where('role', CompanyRole::SUPPLIER->value)->exists();
        if (! $exists) {
            $company->companyRoles()->create(['role' => CompanyRole::SUPPLIER->value]);
        }
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string,Product>  $createdByRef
     */
    private function resolveProduct(array $item, Company $company, array &$createdByRef): ?Product
    {
        $partNo = $item['part_no'] ?? null;

        if (! empty($item['product_id'])) {
            $product = Product::find($item['product_id']);
            $this->linkSupplier($product, $company, $partNo);

            return $product;
        }

        if ($partNo !== null && isset($createdByRef[$partNo])) {
            return $createdByRef[$partNo];
        }

        // reference_code é UNIQUE: sem esta busca, um segundo fornecedor
        // cotando a mesma peça estoura o índice. Reaproveitar o produto é o
        // resultado esperado — cada fornecedor recebe seu próprio pivot.
        if ($partNo !== null) {
            $existing = Product::where('reference_code', $partNo)->first();

            if ($existing) {
                $this->linkSupplier($existing, $company, $partNo);
                $createdByRef[$partNo] = $existing;

                return $existing;
            }
        }

        $categoryId = $item['category_id'] ?? null;

        $product = $this->productCreator->execute(
            description: (string) $item['description'],
            supplier: $company,
            externalCode: $partNo,
            categoryId: $categoryId !== null ? (int) $categoryId : null,
        );

        if ($partNo !== null) {
            $createdByRef[$partNo] = $product;
        }

        return $product;
    }

    /**
     * Stable key for matching a photo to an item: the part number when present,
     * otherwise the positional index — mirrors the page's mapping logic.
     *
     * @param  array<string,mixed>  $item
     */
    private function photoItemKey(array $item, int $index): string
    {
        $partNo = trim((string) ($item['part_no'] ?? ''));

        return $partNo !== '' ? $partNo : 'idx:'.$index;
    }

    /**
     * @param  array<string,string>  $images  item key => absolute temp path
     */
    private function attachProductPhoto(?Product $product, string $key, array $images): void
    {
        if ($product === null || ! isset($images[$key])) {
            return;
        }

        if ($product->images()->exists()) {
            return; // já tem foto
        }

        try {
            $source = $images[$key];
            if (! is_file($source)) {
                return;
            }
            $ext = pathinfo($source, PATHINFO_EXTENSION) ?: 'png';
            $stored = "product-images/{$product->id}/".Str::uuid()->toString().'.'.$ext;
            Storage::disk('public')->put($stored, (string) file_get_contents($source));

            ProductImage::create([
                'product_id' => $product->id,
                'disk' => 'public',
                'path' => $stored,
                'sort_order' => 0,
                'is_primary' => true,
                'original_name' => basename($source),
                'size' => Storage::disk('public')->size($stored),
            ]);

            // The product avatar/list still reads `products.avatar` (public disk);
            // point it at the primary image so the photo shows there too.
            if (blank($product->avatar)) {
                $product->update(['avatar' => $stored]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Vincula o fornecedor gravando o part number DELE no pivot — é assim que
     * o PO e o RFQ passam a identificar a peça como o fornecedor a conhece.
     * Delega para CreateDraftProductForSupplierAction, que já garante o
     * pivot role=supplier sem sobrescrever um código já gravado.
     */
    private function linkSupplier(?Product $product, Company $company, ?string $partNo = null): void
    {
        if ($product === null) {
            return;
        }

        $this->productCreator->linkSupplier($product, $company, $partNo);
    }

    /**
     * @param  ?string  $storedPath  set to the stored file path right after the copy,
     *                               so the caller can clean it up on rollback
     */
    private function attachSourceFile(SupplierQuotation $sq, string $filePath, User $user, ?string &$storedPath): void
    {
        $disk = 'local';
        $name = basename($filePath);
        $stored = "supplier-quotations/{$sq->id}/{$name}";
        Storage::disk($disk)->put($stored, (string) file_get_contents($filePath));
        $storedPath = $stored;

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
