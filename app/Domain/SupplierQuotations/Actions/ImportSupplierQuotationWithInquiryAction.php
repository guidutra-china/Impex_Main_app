<?php

declare(strict_types=1);

namespace App\Domain\SupplierQuotations\Actions;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Enums\InquirySource;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Importa uma cotação de fornecedor criando também a Inquiry do cliente, já
 * vinculadas: a SQ nasce com inquiry_id, cada SQ item ganha seu InquiryItem
 * espelho (mesmo product_id + inquiry_item_id preenchido). Esse par completo é o
 * pré-requisito para gerar Quotation/PI a partir da Inquiry — o cliente é escolhido
 * pelo usuário no review (nunca o "sold to" do documento). Composes the existing
 * ImportSupplierQuotationAction rather than duplicating it.
 */
class ImportSupplierQuotationWithInquiryAction
{
    public function __construct(private readonly ImportSupplierQuotationAction $importSupplierQuotation = new ImportSupplierQuotationAction) {}

    /**
     * @param  array<string,mixed>  $preview  output of SupplierQuotationTarget::formToConfirmPayload()
     * @param  array<string,string>  $images  item key (part_no ou "idx:N") => caminho absoluto temporário
     * @return array{sq:SupplierQuotation,inquiry:Inquiry}
     */
    public function __invoke(array $preview, User $user, string $filePath, array $images, int $clientCompanyId): array
    {
        if (! $user->can('create-inquiries')) {
            throw new AuthorizationException(__('assistant.perm_inquiries_create'));
        }

        // Valida fora da transação: cliente inválido deve falhar antes de qualquer escrita.
        $client = Company::findOrFail($clientCompanyId);

        $sq = null;

        try {
            return DB::transaction(function () use ($preview, $user, $filePath, $images, $client, &$sq) {
                if (! $client->companyRoles()->where('role', CompanyRole::CLIENT->value)->exists()) {
                    $client->companyRoles()->create(['role' => CompanyRole::CLIENT->value]);
                }

                $inquiry = Inquiry::create([
                    'company_id' => $client->id,
                    'status' => InquiryStatus::RECEIVED,
                    'source' => InquirySource::OTHER,
                    'currency_code' => mb_substr(strtoupper((string) ($preview['cabecalho']['currency_code'] ?? 'USD')), 0, 10),
                    'notes' => $preview['cabecalho']['notes'] ?? null,
                    'created_by' => $user->id,
                ]);

                // A action interna resolve fornecedor/produtos, cria SQ + itens e anexa o
                // arquivo fonte; a transação dela vira um savepoint dentro desta.
                $sq = ($this->importSupplierQuotation)($preview, $user, $filePath, $images, $inquiry->id);

                foreach ($sq->items()->orderBy('sort_order')->get() as $sqItem) {
                    $inquiryItem = InquiryItem::create([
                        'inquiry_id' => $inquiry->id,
                        'product_id' => $sqItem->product_id,
                        // `description` is varchar(255); SQ item descriptions are capped at 500.
                        'description' => $sqItem->description !== null ? mb_substr($sqItem->description, 0, 255) : null,
                        'quantity' => max(1, (int) $sqItem->quantity),
                        'unit' => $sqItem->unit ?: 'pcs',
                        // Custo do fornecedor ≠ preço-alvo do cliente: target_price fica vazio.
                        'target_price' => null,
                        'specifications' => $sqItem->specifications,
                        'notes' => $sqItem->notes,
                        'sort_order' => (int) $sqItem->sort_order,
                    ]);

                    $sqItem->update(['inquiry_item_id' => $inquiryItem->id]);
                }

                activity('ai-assistant')
                    ->causedBy($user)
                    ->performedOn($inquiry)
                    ->withProperties(['itens' => count($preview['itens']), 'supplier_quotation_id' => $sq->id])
                    ->log('inquiry_created_from_supplier_quotation_import');

                return ['sq' => $sq, 'inquiry' => $inquiry];
            });
        } catch (\Throwable $e) {
            // A action interna já limpa o arquivo quando ELA falha; se a falha for na
            // criação dos inquiry items, o savepoint dela já foi commitado e o arquivo
            // copiado sobrevive ao rollback externo — remova-o aqui.
            if ($sq !== null) {
                Storage::disk('local')->deleteDirectory("supplier-quotations/{$sq->id}");
            }

            throw $e;
        }
    }
}
