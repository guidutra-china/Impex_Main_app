<?php

declare(strict_types=1);

namespace App\Domain\Inquiries\Actions;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\CRM\Actions\ResolveOrCreateCompanyAction;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Enums\DocumentSourceType;
use App\Domain\Inquiries\Enums\InquirySource;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Commits a resolved inquiry preview: creates a new Inquiry (finding/creating the
 * client) or appends items to an open existing one, and attaches the source file.
 * Unmatched items WITH a part number get a DRAFT product created (same behavior as
 * the manual add-item flow) so the extracted model code is never lost — items
 * without any code keep product_id null. Deterministic, transactional,
 * permission-gated — triggered by the user's explicit confirmation, never by the model.
 */
class ImportInquiryAction
{
    /**
     * @param  array<string,mixed>  $preview  output of InquiryTarget::formToConfirmPayload()
     * @param  array<string,string>  $images  item key (part_no ou "idx:N") => caminho absoluto temporário
     */
    public function __invoke(array $preview, User $user, string $filePath, array $images = []): Inquiry
    {
        $this->authorize($preview, $user);

        $storedPath = null;

        try {
            return DB::transaction(function () use ($preview, $user, $filePath, $images, &$storedPath) {
                $inquiry = ($preview['modo'] ?? 'nova') === 'existente'
                    ? $this->existingInquiry($preview)
                    : $this->createInquiry($preview, $user);

                $nextOrder = $inquiry->items()->exists()
                    ? ((int) $inquiry->items()->max('sort_order')) + 1
                    : 0;

                foreach (array_values($preview['itens']) as $index => $item) {
                    $product = isset($item['product_id']) && $item['product_id']
                        ? Product::find($item['product_id'])
                        : $this->createDraftProduct($item);

                    $this->attachProductPhoto($product, $this->photoItemKey($item, $index), $images);

                    InquiryItem::create([
                        'inquiry_id' => $inquiry->id,
                        'product_id' => $product?->id,
                        // `description` is varchar(255) — cap so extracted text never breaks the insert.
                        'description' => $this->cap($item['description'] ?? null, 255),
                        'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                        'unit' => $this->cap(($item['unit'] ?? null) ?: 'pcs', 20),
                        'target_price' => $item['target_price_minor'] ?? null,
                        'specifications' => $item['specifications'] ?? null,
                        'notes' => $item['notes'] ?? null,
                        'sort_order' => $nextOrder + $index,
                    ]);
                }

                $this->attachSourceFile($inquiry, $filePath, $user, $storedPath);

                activity('ai-assistant')
                    ->causedBy($user)
                    ->performedOn($inquiry)
                    ->withProperties(['itens' => count($preview['itens']), 'modo' => $preview['modo'] ?? 'nova'])
                    ->log('inquiry_imported');

                return $inquiry;
            });
        } catch (\Throwable $e) {
            // Storage writes are not transactional: the rollback undid the Inquiry and
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
        if (($preview['modo'] ?? 'nova') === 'existente') {
            if (! $user->can('edit-inquiries')) {
                throw new AuthorizationException(__('assistant.perm_inquiries_edit'));
            }

            return;
        }

        if (! $user->can('create-inquiries')) {
            throw new AuthorizationException(__('assistant.perm_inquiries_create'));
        }

        if (($preview['cliente']['status'] ?? null) === 'novo' && ! $user->can('create-companies')) {
            throw new AuthorizationException(__('assistant.perm_companies'));
        }
    }

    /**
     * @param  array<string,mixed>  $preview
     */
    private function existingInquiry(array $preview): Inquiry
    {
        if (($preview['inquiry_id'] ?? null) === null) {
            throw new \InvalidArgumentException(__('assistant.select_inquiry'));
        }

        $inquiry = Inquiry::findOrFail((int) $preview['inquiry_id']);

        if (! in_array($inquiry->status, [InquiryStatus::RECEIVED, InquiryStatus::QUOTING], true)) {
            throw new \InvalidArgumentException(__('assistant.inquiry_not_open'));
        }

        return $inquiry;
    }

    /**
     * @param  array<string,mixed>  $preview
     */
    private function createInquiry(array $preview, User $user): Inquiry
    {
        $client = $this->resolveClient($preview['cliente'] ?? []);

        return Inquiry::create([
            'company_id' => $client->id,
            'status' => InquiryStatus::RECEIVED,
            'source' => InquirySource::OTHER,
            'currency_code' => $this->cap(strtoupper((string) ($preview['cabecalho']['currency_code'] ?? 'USD')), 10),
            'deadline' => $preview['cabecalho']['deadline'] ?? null,
            'notes' => $preview['cabecalho']['notes'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    /**
     * @param  array<string,mixed>  $cliente
     */
    private function resolveClient(array $cliente): Company
    {
        if (! empty($cliente['company_id'])) {
            $company = Company::findOrFail($cliente['company_id']);
        } else {
            $name = trim((string) ($cliente['nome'] ?? ''));
            if ($name === '') {
                throw new \InvalidArgumentException(__('assistant.client_required'));
            }
            // Reuse an existing company on name match instead of duplicating it.
            $company = app(ResolveOrCreateCompanyAction::class)
                ->execute($this->cap($name, 255))
                ->company;
        }

        if (! $company->companyRoles()->where('role', CompanyRole::CLIENT->value)->exists()) {
            $company->companyRoles()->create(['role' => CompanyRole::CLIENT->value]);
        }

        return $company;
    }

    /**
     * Item extraído com código de modelo mas sem match no catálogo: cria um
     * produto DRAFT (mesmo comportamento do fluxo manual de adicionar item).
     * Antes o part_no era usado só no match e descartado — os itens ficavam
     * órfãos de produto e o código se perdia (casos INQ-2026-00063/00071).
     *
     * @param  array<string,mixed>  $item
     */
    private function createDraftProduct(array $item): ?Product
    {
        $partNo = trim((string) ($item['part_no'] ?? ''));

        if ($partNo === '') {
            return null;
        }

        // Revalida contra o catálogo (o preview pode estar defasado, e o mesmo
        // código pode repetir dentro do próprio import) antes de criar.
        $existing = Product::where('reference_code', $partNo)
            ->orWhere('model_number', $partNo)
            ->orWhere('sku', $partNo)
            ->first();

        if ($existing) {
            return $existing;
        }

        return Product::create([
            'name' => $this->cap(filled($item['description'] ?? null) ? (string) $item['description'] : $partNo, 255),
            'model_number' => $this->cap($partNo, 255),
            'status' => ProductStatus::DRAFT,
        ]);
    }

    private function photoItemKey(array $item, int $index): string
    {
        $partNo = trim((string) ($item['part_no'] ?? ''));

        return $partNo !== '' ? $partNo : 'idx:'.$index;
    }

    /**
     * Anexa a foto extraída do documento ao produto (criado ou já existente sem
     * fotos) — mesma mecânica do import de cotação de fornecedor.
     *
     * @param  array<string,string>  $images
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
            $stored = "product-images/{$product->id}/".\Illuminate\Support\Str::uuid()->toString().'.'.$ext;
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

    /** Cap a string to a column max length (null-safe). */
    private function cap(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }

    /**
     * @param  ?string  $storedPath  set to the stored file path right after the copy,
     *                               so the caller can clean it up on rollback
     */
    private function attachSourceFile(Inquiry $inquiry, string $filePath, User $user, ?string &$storedPath): void
    {
        $disk = 'local';
        $name = basename($filePath);
        $stored = "inquiries/{$inquiry->id}/{$name}";
        Storage::disk($disk)->put($stored, (string) file_get_contents($filePath));
        $storedPath = $stored;

        $inquiry->documents()->create([
            'type' => 'inquiry_source',
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
