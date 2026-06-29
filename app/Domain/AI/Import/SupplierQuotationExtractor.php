<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use Anthropic\Client;
use Anthropic\Messages\TextBlockParam;
use Anthropic\Messages\Tool;
use Anthropic\Messages\ToolChoiceTool;
use App\Domain\AI\Import\Exceptions\ExtractionFailedException;

/**
 * Extracts a structured supplier-quotation draft from document content blocks via a
 * single forced-tool call (structured output). The model never writes anything.
 */
class SupplierQuotationExtractor
{
    protected Client $client;

    /** @var list<string> Existing category names the model may assign items to. */
    protected array $categoryNames = [];

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(apiKey: (string) config('services.anthropic.key'));
    }

    /**
     * @param  list<object>  $documentBlocks  content blocks from DocumentExtractor
     * @param  list<string>  $categoryNames  existing category names the model may assign (no inventing)
     * @return array{fornecedor:array<string,mixed>,itens:list<array<string,mixed>>}
     */
    public function extract(array $documentBlocks, array $categoryNames = []): array
    {
        $this->categoryNames = array_values($categoryNames);

        $content = array_merge(
            [TextBlockParam::with(
                'Extraia a cotação deste fornecedor do documento a seguir. '
                .'Use a ferramenta registrar_cotacao. Não invente dados ausentes — omita campos que não constam.'
            )],
            $documentBlocks,
        );

        $response = $this->callModel($content);

        foreach ($response->content as $block) {
            if (($block->type ?? null) === 'tool_use' && ($block->name ?? null) === 'registrar_cotacao') {
                /** @var array{fornecedor:array<string,mixed>,itens:list<array<string,mixed>>} $draft */
                $draft = (array) $block->input;

                if (empty($draft['itens'] ?? [])) {
                    throw new ExtractionFailedException('Nenhum item encontrado no documento.');
                }

                return $draft;
            }
        }

        throw new ExtractionFailedException('O modelo não retornou uma cotação estruturada.');
    }

    /**
     * Single forced-tool round-trip. Seam for tests (override to avoid the API).
     *
     * @param  list<object>  $content
     */
    protected function callModel(array $content): object
    {
        return $this->client->messages->create(
            maxTokens: 8192,
            messages: [['role' => 'user', 'content' => $content]],
            model: (string) config('services.anthropic.assistant_model', 'claude-opus-4-8'),
            system: 'Você extrai cotações de fornecedores de planilhas e PDFs para uma trading company. '
                .'Valores na moeda do documento, como números decimais. Datas em YYYY-MM-DD. '
                .'Para CADA item capture quantity, unit_price (preço unitário como aparece) E line_total '
                .'(o valor TOTAL da linha, a coluna "Amount"/"Total"), quando existir — o line_total é o valor '
                .'confiável quando o preço unitário está por kg ou não multiplica direto pela quantidade. '
                .'Capture o total geral do documento em documento_total. '
                .'Linhas que NÃO são produtos (taxas, frete, customization fee, descontos) vão em "extras" '
                .'com descricao e valor (use valor NEGATIVO para descontos). Não as inclua como itens. '
                .($this->categoryNames !== []
                    ? 'Para cada item, atribua "categoria" escolhendo APENAS uma das categorias existentes fornecidas; '
                        .'se nenhuma for claramente adequada, omita a categoria (não invente).'
                    : '')
                .' Capture também os dados de contato do fornecedor quando constarem no documento '
                .'(legal_name, tax_number, phone, email, website e endereço).',
            tools: [Tool::with(
                inputSchema: SupplierQuotationDraftSchema::schema($this->categoryNames),
                name: 'registrar_cotacao',
                description: 'Registra a cotação extraída do documento (fornecedor + itens).',
            )],
            toolChoice: ToolChoiceTool::with(name: 'registrar_cotacao'),
        );
    }
}
