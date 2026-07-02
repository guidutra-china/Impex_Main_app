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
                        ],
                        'required' => ['description', 'quantity'],
                    ],
                ],
            ],
            'required' => ['cliente', 'itens'],
        ];
    }
}
