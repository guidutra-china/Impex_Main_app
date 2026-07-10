<?php

declare(strict_types=1);

namespace App\Domain\AI\Import\Targets;

use App\Domain\AI\Import\ResolveSupplierQuotationDraft;
use App\Domain\AI\Import\SupplierQuotationDraftSchema;
use App\Domain\Catalog\Models\Category;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\SupplierQuotations\Actions\ImportSupplierQuotationAction;
use App\Domain\SupplierQuotations\Actions\ImportSupplierQuotationWithInquiryAction;
use App\Models\User;

class SupplierQuotationTarget implements ImportTarget
{
    /** @var list<string>|null Memoized active category names (avoids repeat queries per request). */
    private ?array $categoryNames = null;

    public function key(): string
    {
        return 'supplier_quotation';
    }

    public function label(): string
    {
        return __('assistant.target_supplier_quotation');
    }

    public function classifierHint(): string
    {
        return 'Cotação/orçamento RECEBIDO DE UM FORNECEDOR: lista de produtos com preços '
            .'oferecidos pelo fornecedor, geralmente com MOQ, lead time, incoterm, validade, '
            .'dados bancários ou condições de pagamento do fornecedor.';
    }

    public function extractionToolName(): string
    {
        return 'registrar_cotacao';
    }

    public function extractionSchema(): array
    {
        return SupplierQuotationDraftSchema::schema($this->categoryNames());
    }

    public function extractionUserPrompt(): string
    {
        return 'Extraia a cotação deste fornecedor do documento a seguir. '
            .'Use a ferramenta registrar_cotacao. Não invente dados ausentes — omita campos que não constam.';
    }

    public function extractionSystemPrompt(): string
    {
        $categoryNames = $this->categoryNames();

        return 'Você extrai cotações de fornecedores de planilhas e PDFs para uma trading company. '
            .'Valores na moeda do documento, como números decimais. Datas em YYYY-MM-DD. '
            .'Para CADA item capture quantity, unit_price (preço unitário como aparece) E line_total '
            .'(o valor TOTAL da linha, a coluna "Amount"/"Total"), quando existir — o line_total é o valor '
            .'confiável quando o preço unitário está por kg ou não multiplica direto pela quantidade. '
            .'Capture o total geral do documento em documento_total. '
            .'Linhas que NÃO são produtos (taxas, frete, customization fee, descontos) vão em "extras" '
            .'com descricao e valor (use valor NEGATIVO para descontos). Não as inclua como itens. '
            .($categoryNames !== []
                ? 'Para cada item, atribua "categoria" escolhendo APENAS uma das categorias existentes fornecidas; '
                    .'se nenhuma for claramente adequada, omita a categoria (não invente).'
                : '')
            .' Capture também os dados de contato do fornecedor quando constarem no documento '
            .'(legal_name, tax_number, phone, email, website e endereço). '
            .'Em documentos PDF, preencha "page" em CADA item com o número da página onde a linha aparece '
            .'(1 = primeira página) — isso é usado para associar as fotos aos itens. '
            .'Quando uma linha NÃO tiver descrição textual, descreva o produto objetivamente a partir da '
            .'FOTO da linha (tipo de produto, material, cor — ex: "Puxador triangular de aço com pegada '
            .'emborrachada") e marque descricao_inferida=true. Nunca deixe description vazio e nunca '
            .'copie a descrição de outra linha. '
            .'Quando uma linha do documento agrupar VARIAÇÕES do mesmo produto (pesos/tamanhos com '
            .'quantidade e preço próprios), mantenha a descrição base no item e liste cada variação em '
            .'"variantes" com rotulo (ex: "8kg"), quantity e unit_price/line_total próprios — não as '
            .'repita como itens separados.';
    }

    public function editToolName(): string
    {
        return 'atualizar_cotacao';
    }

    public function editSystemPrompt(): string
    {
        return <<<'PROMPT'
        You edit an already-extracted supplier quotation for a Brazil–China trading company,
        based on the user's instruction. The data is being reviewed before import.

        Rules:
        - Return the FULL updated quotation (keep unchanged fields as-is). You may add, remove or
          merge items and change supplier fields when asked.
        - Keep prices in the document currency as decimal numbers; line_total is the line amount.
        - If a category is provided, choose only from the existing category list; otherwise omit it.
        - If the user only asks a QUESTION about the quotation, answer it in `reply` and return the
          draft unchanged.
        - Write `reply` as a short message in :language. Never invent data.
        - Supplier contact fields (phone, email, address, etc.) may be edited when asked.
        PROMPT;
    }

    public function resolve(array $draft): array
    {
        return app(ResolveSupplierQuotationDraft::class)->resolve($draft);
    }

    public function supportsImages(): bool
    {
        return true;
    }

    public function authorize(User $user): bool
    {
        return $user->can('create-supplier-quotations');
    }

    public function buildForm(array $preview, array $itemPhoto): array
    {
        $itens = [];
        foreach (array_values($preview['itens']) as $i => $it) {
            $itens[] = [
                'description' => $it['description'] ?? '',
                'quantity' => $it['quantity'] ?? 0,
                'unit' => $it['unit'] ?? 'pcs',
                'unit_price' => round(((int) ($it['unit_cost_minor'] ?? 0)) / Money::SCALE, 2),
                'category_id' => $it['category_id'] ?? null,
                'part_no' => $it['part_no'] ?? null,
                'status' => $it['status'] ?? 'novo',
                'product_id' => $it['product_id'] ?? null,
                'product_name' => $it['product_name'] ?? null,
                'description_inferred' => (bool) ($it['description_inferred'] ?? false),
                'specifications' => $it['specifications'] ?? null,
                'moq' => $it['moq'] ?? null,
                'lead_time_days' => $it['lead_time_days'] ?? null,
                'notes' => $it['notes'] ?? null,
                'photo_index' => $itemPhoto[$i] ?? null,
            ];
        }

        return [
            'fornecedor' => array_merge(
                [
                    'nome' => $preview['fornecedor']['nome'] ?? '',
                    'status' => $preview['fornecedor']['status'] ?? 'novo',
                    'company_id' => $preview['fornecedor']['company_id'] ?? null,
                ],
                $preview['fornecedor_dados'] ?? [],
            ),
            'cabecalho' => $preview['cabecalho'] ?? [],
            'itens' => $itens,
            // Fluxo combinado: além da SQ, criar a Inquiry do cliente já vinculada
            // (o cliente é escolhido pelo usuário no review, não vem do documento).
            'criar_inquiry' => false,
            'inquiry_company_id' => null,
        ];
    }

    public function formToConfirmPayload(array $form, array $imagePool): array
    {
        $contactKeys = ['legal_name', 'tax_number', 'phone', 'email', 'website', 'address_street', 'address_number', 'address_complement', 'address_city', 'address_state', 'address_zip', 'address_country'];

        $itens = [];
        $images = [];
        foreach (array_values($form['itens'] ?? []) as $i => $it) {
            $partNo = trim((string) ($it['part_no'] ?? ''));
            $key = $partNo !== '' ? $partNo : 'idx:'.$i;

            $itens[] = [
                // Status must be derived from the id: the form is client-editable, and the
                // Action's authorize() gates must always match what the resolvers will do
                // (create a product when product_id is absent).
                'status' => empty($it['product_id']) ? 'novo' : 'existente',
                'product_id' => $it['product_id'] ?? null,
                'part_no' => $partNo !== '' ? $partNo : null,
                'description' => (string) ($it['description'] ?? ''),
                'quantity' => (int) ($it['quantity'] ?? 0),
                'unit' => $it['unit'] ?? null,
                'unit_cost_minor' => Money::toMinor($it['unit_price'] ?? 0),
                'category_id' => $it['category_id'] ?? null,
                'specifications' => $it['specifications'] ?? null,
                'moq' => $it['moq'] ?? null,
                'lead_time_days' => $it['lead_time_days'] ?? null,
                'notes' => $it['notes'] ?? null,
            ];

            $idx = $it['photo_index'] ?? null;
            if ($idx !== null && isset($imagePool[$idx])) {
                $images[$key] = $imagePool[$idx]['path'];
            }
        }

        $f = $form['fornecedor'] ?? [];

        return [
            'preview' => [
                // Status derived from the id (not the client-supplied value) so the
                // authorize() gate for create-companies always matches the resolver.
                'fornecedor' => ['status' => empty($f['company_id']) ? 'novo' : 'existente', 'company_id' => $f['company_id'] ?? null, 'nome' => $f['nome'] ?? ''],
                'fornecedor_dados' => array_intersect_key($f, array_flip($contactKeys)),
                'cabecalho' => $form['cabecalho'] ?? [],
                'itens' => $itens,
                'criar_inquiry' => (bool) ($form['criar_inquiry'] ?? false),
                'inquiry_company_id' => filled($form['inquiry_company_id'] ?? null) ? (int) $form['inquiry_company_id'] : null,
            ],
            'images' => $images,
        ];
    }

    public function confirm(array $preview, User $user, string $filePath, array $images, array $context = []): array
    {
        $inquiryId = isset($context['inquiry_id']) ? (int) $context['inquiry_id'] : null;

        // Fluxo combinado: cria Inquiry + SQ vinculadas. Só quando não há inquiry de
        // contexto (entrada via Inquiry existente prevalece sobre o checkbox).
        if ($inquiryId === null && ($preview['criar_inquiry'] ?? false)) {
            if (empty($preview['inquiry_company_id'])) {
                throw new \InvalidArgumentException(__('assistant.inquiry_client_required'));
            }

            $result = app(ImportSupplierQuotationWithInquiryAction::class)($preview, $user, $filePath, $images, (int) $preview['inquiry_company_id']);

            return [
                'reference' => $result['sq']->reference.' + '.$result['inquiry']->reference,
                'count' => count($preview['itens']),
            ];
        }

        $sq = app(ImportSupplierQuotationAction::class)($preview, $user, $filePath, $images, $inquiryId ?: null);

        return ['reference' => (string) $sq->reference, 'count' => count($preview['itens'])];
    }

    /**
     * Active category names the extractor may assign items to (no inventing).
     * Memoized: extractionSystemPrompt() and extractionSchema() are both called
     * per request and would otherwise repeat the same query.
     *
     * @return list<string>
     */
    private function categoryNames(): array
    {
        return $this->categoryNames ??= Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
