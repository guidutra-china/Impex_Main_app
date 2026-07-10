<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

/**
 * JSON Schema for the structured client-inquiry draft. Shared by the extractor
 * (initial extraction) and the editor (conversational adjustments).
 */
class InquiryDraftSchema
{
    /** @return array<string,mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cliente' => [
                    'type' => 'object',
                    'properties' => [
                        'nome' => ['type' => 'string', 'description' => 'Nome do cliente. Use string vazia se o documento não identificar o cliente.'],
                        'contato' => ['type' => 'string', 'description' => 'Nome da pessoa de contato, se constar.'],
                        'currency_code' => ['type' => 'string', 'description' => 'ISO, ex: USD, BRL.'],
                        'deadline' => ['type' => 'string', 'description' => 'Prazo de resposta YYYY-MM-DD, se constar.'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['nome'],
                ],
                'itens' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'part_no' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'quantity' => ['type' => 'integer'],
                            'unit' => ['type' => 'string'],
                            'target_price' => ['type' => 'number', 'description' => 'Preço-alvo unitário do cliente, quando constar.'],
                            'specifications' => ['type' => 'string'],
                            'notes' => ['type' => 'string'],
                            'source_row' => ['type' => 'integer', 'description' => 'Número da linha de origem na planilha (campo "Linha N:"), quando houver.'],
                            'page' => ['type' => 'integer', 'description' => 'Em PDFs: número da página onde a linha do item aparece (1 = primeira página).'],
                            'descricao_inferida' => ['type' => 'boolean', 'description' => 'true quando a linha não tinha descrição textual e a description foi deduzida a partir da foto do produto.'],
                            'variantes' => [
                                'type' => 'array',
                                'description' => 'Sub-linhas de variação do MESMO produto (pesos/tamanhos), cada uma com qtd/preço próprios, compartilhando a foto e a descrição base. Use em vez de repetir itens.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'rotulo' => ['type' => 'string', 'description' => 'Rótulo da variação, ex: "8kg", "750mm".'],
                                        'part_no' => ['type' => 'string'],
                                        'quantity' => ['type' => 'integer'],
                                        'target_price' => ['type' => 'number'],
                                    ],
                                    'required' => ['rotulo', 'quantity'],
                                ],
                            ],
                        ],
                        'required' => ['description', 'quantity'],
                    ],
                ],
            ],
            'required' => ['cliente', 'itens'],
        ];
    }
}
