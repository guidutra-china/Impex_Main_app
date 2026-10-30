<?php

declare(strict_types=1);

namespace App\Domain\AI\Import\Targets;

use App\Domain\AI\Import\ResolveSupplierQuotationResponse;
use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\SupplierQuotations\Actions\ApplySupplierQuotationResponseAction;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Models\User;

/**
 * Resposta do fornecedor a uma RFQ JÁ EXISTENTE: em vez de criar SQ/produtos, a
 * extração casa cada linha do documento com as NOSSAS linhas da SQ (numeradas
 * no prompt) e devolve preço/MOQ/prazo por linha. Fora do ImportTargetRegistry:
 * só faz sentido com a SQ de contexto, nunca pelo classificador do Assistant.
 */
class SupplierQuotationResponseTarget implements ImportTarget
{
    public function __construct(private readonly SupplierQuotation $sq) {}

    public function key(): string
    {
        return 'supplier_quotation_response';
    }

    public function label(): string
    {
        return __('forms.labels.import_supplier_response');
    }

    public function classifierHint(): string
    {
        return 'Resposta de um fornecedor a uma RFQ já enviada, com preços para as linhas pedidas.';
    }

    public function extractionToolName(): string
    {
        return 'registrar_resposta';
    }

    public function extractionSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cabecalho' => [
                    'type' => 'object',
                    'properties' => [
                        'currency_code' => ['type' => 'string', 'description' => 'Moeda dos preços (ISO 4217: USD, CNY, EUR...).'],
                        'supplier_reference' => ['type' => 'string', 'description' => 'Número/referência da cotação ou proforma do fornecedor.'],
                        'valid_until' => ['type' => 'string', 'description' => 'Validade da cotação, YYYY-MM-DD.'],
                        'lead_time_days' => ['type' => 'integer', 'description' => 'Prazo de produção geral, em dias.'],
                        'moq' => ['type' => 'integer', 'description' => 'MOQ geral, quando não é por item.'],
                        'incoterm' => ['type' => 'string', 'description' => 'Incoterm e porto, como aparece (ex.: "FOB Ningbo").'],
                    ],
                ],
                'itens' => [
                    'type' => 'array',
                    'description' => 'Uma entrada por linha do documento que corresponde a uma das NOSSAS linhas.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'linha' => ['type' => 'integer', 'description' => 'Número da NOSSA linha (da lista enviada) a que este preço corresponde.'],
                            'source_row' => ['type' => 'integer', 'description' => 'Linha da planilha ("Linha N:") de onde o preço saiu.'],
                            'descricao_documento' => ['type' => 'string', 'description' => 'Descrição/código como aparece no documento do fornecedor.'],
                            'quantity' => ['type' => 'number', 'description' => 'Quantidade cotada pelo fornecedor nessa linha.'],
                            'unit_price' => ['type' => 'number', 'description' => 'Preço unitário como aparece.'],
                            'line_total' => ['type' => 'number', 'description' => 'Valor total da linha (coluna Amount/Total), quando existir.'],
                            'moq' => ['type' => 'integer'],
                            'lead_time_days' => ['type' => 'integer'],
                            'notes' => ['type' => 'string', 'description' => 'Observação do fornecedor para a linha (marca, material, embalagem...).'],
                        ],
                        'required' => ['linha', 'descricao_documento'],
                    ],
                ],
                'extras' => [
                    'type' => 'array',
                    'description' => 'Linhas do documento que NÃO correspondem a nenhuma das nossas linhas (outros produtos, taxas, frete, descontos).',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'descricao' => ['type' => 'string'],
                            'valor' => ['type' => 'number'],
                        ],
                        'required' => ['descricao'],
                    ],
                ],
            ],
            'required' => ['itens'],
        ];
    }

    public function extractionSystemPrompt(): string
    {
        return 'Você lê a resposta de um fornecedor chinês a um pedido de cotação (RFQ) de uma trading company. '
            .'Você recebe a lista das NOSSAS linhas pedidas (numeradas) e o documento do fornecedor. '
            .'Para cada linha do documento, identifique a qual das nossas linhas ela corresponde, comparando '
            .'código (SKU, modelo, código do fornecedor), descrição, medidas e quantidade. '
            .'Só associe quando tiver confiança; se uma linha do documento não corresponder a nenhuma das nossas, '
            .'coloque-a em "extras" (linhas de total/subtotal do documento não entram em lugar nenhum). Nunca associe duas linhas do documento à mesma linha nossa. '
            .'Se o fornecedor não cotou uma das nossas linhas, simplesmente não a inclua. '
            .'Valores na moeda do documento, como números decimais. Datas em YYYY-MM-DD. '
            .'Capture unit_price E line_total (o total da linha) quando existirem.';
    }

    public function extractionUserPrompt(): string
    {
        $items = $this->sq->items()->orderBy('id')->with('product')->get();
        $supplierCodes = CompanyProduct::query()
            ->where('company_id', $this->sq->company_id)
            ->whereIn('product_id', $items->pluck('product_id')->filter())
            ->whereNotNull('external_code')
            ->pluck('external_code', 'product_id');

        $lines = $items->values()->map(function ($item, int $index) use ($supplierCodes) {
            $codes = array_filter([
                'SKU' => $item->product?->sku,
                'Modelo' => $item->product?->model_number,
                'Cód. fornecedor' => $supplierCodes[$item->product_id] ?? null,
            ]);
            $codeText = collect($codes)->map(fn ($v, $k) => "{$k}: {$v}")->implode(' | ');

            return ($index + 1).'. '.trim($codeText.' | '.$item->description, ' |')
                .' | Qtd: '.$item->quantity.' '.$item->unit;
        })->implode("\n");

        return 'Nossa RFQ '.$this->sq->reference.' (moeda '.$this->sq->currency_code.') pediu estas linhas:'."\n"
            .$lines."\n\n"
            .'Leia a resposta do fornecedor a seguir e use a ferramenta registrar_resposta. '
            .'Em "linha" use o número da nossa lista acima. Não invente dados ausentes.';
    }

    public function editToolName(): string
    {
        return 'atualizar_resposta';
    }

    public function editSystemPrompt(): string
    {
        return 'Edit the extracted supplier response as instructed. Reply in :language. Never invent data.';
    }

    public function resolve(array $draft): array
    {
        return app(ResolveSupplierQuotationResponse::class)->resolve($this->sq, $draft);
    }

    public function supportsImages(): bool
    {
        return false;
    }

    public function authorize(User $user): bool
    {
        return $user->can('edit-supplier-quotations');
    }

    /**
     * Form de revisão: as linhas da SQ com os valores lidos, em unidades maiores.
     */
    public function buildForm(array $preview, array $itemPhoto): array
    {
        return [
            'cabecalho' => $preview['cabecalho'],
            'linhas' => array_map(fn (array $linha) => [
                'sq_item_id' => $linha['sq_item_id'],
                'description' => $linha['description'],
                'quantity' => $linha['quantity'].' '.$linha['unit'],
                'source' => $linha['source'],
                'unit_cost' => $linha['unit_cost_minor'] !== null ? Money::toMajor($linha['unit_cost_minor']) : null,
                'moq' => $linha['moq'],
                'lead_time_days' => $linha['lead_time_days'],
                'notes' => $linha['notes'],
            ], $preview['linhas']),
        ];
    }

    public function formToConfirmPayload(array $form, array $imagePool): array
    {
        return ['preview' => $form, 'images' => []];
    }

    public function confirm(array $preview, User $user, string $filePath, array $images, array $context = []): array
    {
        $result = app(ApplySupplierQuotationResponseAction::class)(
            $this->sq,
            $preview['linhas'] ?? [],
            $preview['cabecalho'] ?? [],
            $user,
            $filePath,
            (string) ($context['file_name'] ?? basename($filePath)),
        );

        return ['reference' => (string) $this->sq->reference, 'count' => $result['updated']];
    }
}
