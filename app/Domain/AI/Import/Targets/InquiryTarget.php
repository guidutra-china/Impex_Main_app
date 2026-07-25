<?php

declare(strict_types=1);

namespace App\Domain\AI\Import\Targets;

use App\Domain\AI\Import\InquiryDraftSchema;
use App\Domain\AI\Import\ResolveInquiryDraft;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Inquiries\Actions\ImportInquiryAction;
use App\Models\User;

class InquiryTarget implements ImportTarget
{
    public function key(): string
    {
        return 'inquiry';
    }

    public function label(): string
    {
        return __('assistant.target_inquiry');
    }

    public function classifierHint(): string
    {
        return 'Pedido de cotação ENVIADO POR UM CLIENTE (inquiry/RFQ): lista de produtos que o '
            .'cliente quer comprar, com quantidades, às vezes preço-alvo e prazo de resposta. '
            .'Sem preços oferecidos por fornecedor, sem MOQ/lead time de fábrica.';
    }

    public function extractionToolName(): string
    {
        return 'registrar_inquiry';
    }

    public function extractionSchema(): array
    {
        return InquiryDraftSchema::schema();
    }

    public function extractionUserPrompt(): string
    {
        return 'Extraia o pedido de cotação (inquiry) deste cliente do documento a seguir. '
            .'Use a ferramenta registrar_inquiry. Não invente dados ausentes — omita campos que não constam.';
    }

    public function extractionSystemPrompt(): string
    {
        return 'Você extrai pedidos de cotação (inquiries) de clientes a partir de planilhas e PDFs '
            .'para uma trading company Brasil–China. Valores na moeda do documento, como números decimais. '
            .'Datas em YYYY-MM-DD. Cada item deve ter description e quantity; capture part_no, unit, '
            .'target_price (preço-alvo do cliente), specifications e notes quando constarem. '
            .'Se o documento não identificar o cliente, use string vazia em cliente.nome. '
            .'Em documentos PDF, preencha "page" em CADA item com o número da página onde a linha aparece '
            .'(1 = primeira página) — isso é usado para associar as fotos aos itens. '
            .'Quando uma linha NÃO tiver descrição textual, descreva o produto objetivamente a partir da '
            .'FOTO da linha (tipo de produto, material, cor) e marque descricao_inferida=true. '
            .'Nunca deixe description vazio e nunca copie a descrição de outra linha. '
            .'Quando uma linha agrupar VARIAÇÕES do mesmo produto (pesos/tamanhos com quantidade própria), '
            .'mantenha a descrição base no item e liste cada variação em "variantes" com rotulo e '
            .'quantity próprios — não as repita como itens separados.';
    }

    public function editToolName(): string
    {
        return 'atualizar_inquiry';
    }

    public function editSystemPrompt(): string
    {
        return <<<'PROMPT'
        You edit an already-extracted client inquiry (request for quotation) for a Brazil–China
        trading company, based on the user's instruction. The data is being reviewed before import.

        Rules:
        - Return the FULL updated inquiry (keep unchanged fields as-is). You may add, remove or
          merge items and change client fields when asked.
        - Keep prices in the document currency as decimal numbers; target_price is the client's
          target unit price.
        - If the user only asks a QUESTION about the inquiry, answer it in `reply` and return the
          draft unchanged.
        - Write `reply` as a short message in :language. Never invent data.
        PROMPT;
    }

    public function resolve(array $draft): array
    {
        return app(ResolveInquiryDraft::class)->resolve($draft);
    }

    public function supportsImages(): bool
    {
        // Fotos do documento vão para os produtos DRAFT criados no confirm —
        // mesma mecânica do import de cotação de fornecedor.
        return true;
    }

    public function authorize(User $user): bool
    {
        return $user->can('create-inquiries') || $user->can('edit-inquiries');
    }

    public function buildForm(array $preview, array $itemPhoto): array
    {
        $itens = [];
        foreach (array_values($preview['itens']) as $i => $it) {
            $minor = $it['target_price_minor'] ?? null;
            $itens[] = [
                'part_no' => $it['part_no'] ?? null,
                'description' => $it['description'] ?? '',
                'quantity' => $it['quantity'] ?? 0,
                'unit' => $it['unit'] ?? 'pcs',
                'target_price' => $minor !== null ? round(((int) $minor) / Money::SCALE, 2) : null,
                'specifications' => $it['specifications'] ?? null,
                'notes' => $it['notes'] ?? null,
                'status' => $it['status'] ?? 'novo',
                'product_id' => $it['product_id'] ?? null,
                'product_name' => $it['product_name'] ?? null,
                'description_inferred' => (bool) ($it['description_inferred'] ?? false),
                'photo_index' => $itemPhoto[$i] ?? null,
                'match_source' => $it['match_source'] ?? null,
            ];
        }

        return [
            'modo' => 'nova',
            'inquiry_id' => null,
            'cliente' => [
                'nome' => $preview['cliente']['nome'] ?? '',
                'status' => $preview['cliente']['status'] ?? 'novo',
                'company_id' => $preview['cliente']['company_id'] ?? null,
            ],
            'cabecalho' => $preview['cabecalho'] ?? [],
            'itens' => $itens,
        ];
    }

    public function formToConfirmPayload(array $form, array $imagePool): array
    {
        $itens = [];
        $images = [];
        foreach (array_values($form['itens'] ?? []) as $i => $it) {
            $price = $it['target_price'] ?? null;
            $partNo = filled($it['part_no'] ?? null) ? trim((string) $it['part_no']) : null;
            $itens[] = [
                // Status must be derived from the id: the form is client-editable, and the
                // Action's authorize() gates must always match what the resolvers will do.
                'status' => empty($it['product_id']) ? 'novo' : 'existente',
                'product_id' => $it['product_id'] ?? null,
                'part_no' => $partNo,
                'description' => (string) ($it['description'] ?? ''),
                'quantity' => (int) ($it['quantity'] ?? 0),
                'unit' => $it['unit'] ?? null,
                'target_price_minor' => ($price !== null && $price !== '') ? Money::toMinor($price) : null,
                'specifications' => $it['specifications'] ?? null,
                'notes' => $it['notes'] ?? null,
            ];

            $idx = $it['photo_index'] ?? null;
            if ($idx !== null && isset($imagePool[$idx])) {
                $images[$partNo !== null && $partNo !== '' ? $partNo : 'idx:'.$i] = $imagePool[$idx]['path'];
            }
        }

        $c = $form['cliente'] ?? [];

        return [
            'preview' => [
                'modo' => $form['modo'] ?? 'nova',
                'inquiry_id' => filled($form['inquiry_id'] ?? null) ? (int) $form['inquiry_id'] : null,
                // Status derived from the id (not the client-supplied value) so the
                // authorize() gate for create-companies always matches the resolver.
                'cliente' => ['status' => empty($c['company_id']) ? 'novo' : 'existente', 'company_id' => $c['company_id'] ?? null, 'nome' => $c['nome'] ?? ''],
                'cabecalho' => $form['cabecalho'] ?? [],
                'itens' => $itens,
            ],
            'images' => $images,
        ];
    }

    public function confirm(array $preview, User $user, string $filePath, array $images, array $context = []): array
    {
        // O lock de inquiry já chega via form (modo=existente + inquiry_id); $context não é necessário aqui.
        $inquiry = app(ImportInquiryAction::class)($preview, $user, $filePath, $images);

        return ['reference' => (string) $inquiry->reference, 'count' => count($preview['itens'])];
    }
}
