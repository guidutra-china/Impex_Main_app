<?php

declare(strict_types=1);

namespace App\Domain\Inquiries\Actions;

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
 * Products are only linked, never created — unmatched items keep product_id null,
 * mirroring the deterministic Excel import. Deterministic, transactional,
 * permission-gated — triggered by the user's explicit confirmation, never by the model.
 */
class ImportInquiryAction
{
    /**
     * @param  array<string,mixed>  $preview  output of InquiryTarget::formToConfirmPayload()
     */
    public function __invoke(array $preview, User $user, string $filePath): Inquiry
    {
        $this->authorize($preview, $user);

        $storedPath = null;

        try {
            return DB::transaction(function () use ($preview, $user, $filePath, &$storedPath) {
                $inquiry = ($preview['modo'] ?? 'nova') === 'existente'
                    ? $this->existingInquiry($preview)
                    : $this->createInquiry($preview, $user);

                $nextOrder = $inquiry->items()->exists()
                    ? ((int) $inquiry->items()->max('sort_order')) + 1
                    : 0;

                foreach (array_values($preview['itens']) as $index => $item) {
                    InquiryItem::create([
                        'inquiry_id' => $inquiry->id,
                        'product_id' => $item['product_id'] ?? null,
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
            $company = Company::create(['name' => $this->cap($name, 255)]);
        }

        if (! $company->companyRoles()->where('role', CompanyRole::CLIENT->value)->exists()) {
            $company->companyRoles()->create(['role' => CompanyRole::CLIENT->value]);
        }

        return $company;
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
