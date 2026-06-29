<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

/**
 * JSON Schema for the structured supplier-quotation draft. Shared by the extractor
 * (initial extraction) and the editor (conversational adjustments) so both speak the
 * exact same shape.
 */
class SupplierQuotationDraftSchema
{
    /**
     * @param  list<string>  $categoryNames  existing category names the model may pick from (enum); empty = no category field
     * @return array<string,mixed>
     */
    public static function schema(array $categoryNames = []): array
    {
        $categoryNames = array_values($categoryNames);

        return [
            'type' => 'object',
            'properties' => [
                'fornecedor' => [
                    'type' => 'object',
                    'properties' => [
                        'nome' => ['type' => 'string'],
                        'supplier_reference' => ['type' => 'string'],
                        'incoterm' => ['type' => 'string'],
                        'currency_code' => ['type' => 'string', 'description' => 'ISO, ex: USD, CNY.'],
                        'lead_time_days' => ['type' => 'integer'],
                        'moq' => ['type' => 'integer'],
                        'valid_until' => ['type' => 'string', 'description' => 'YYYY-MM-DD.'],
                        'payment' => ['type' => 'string'],
                        'notes' => ['type' => 'string'],
                        'legal_name' => ['type' => 'string'],
                        'tax_number' => ['type' => 'string'],
                        'phone' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                        'website' => ['type' => 'string'],
                        'address_street' => ['type' => 'string'],
                        'address_number' => ['type' => 'string'],
                        'address_complement' => ['type' => 'string'],
                        'address_city' => ['type' => 'string'],
                        'address_state' => ['type' => 'string'],
                        'address_zip' => ['type' => 'string'],
                        'address_country' => ['type' => 'string', 'description' => 'Código do país ISO 3166-1 alpha-2 (ex: CN, BR).'],
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
                            'unit_price' => ['type' => 'number', 'description' => 'Preço unitário como aparece no documento.'],
                            'line_total' => ['type' => 'number', 'description' => 'Valor TOTAL da linha (coluna Amount/Total), quando existir.'],
                            'source_row' => ['type' => 'integer', 'description' => 'Número da linha de origem na planilha (campo "Linha N:"), quando houver.'],
                            ...($categoryNames !== [] ? [
                                'categoria' => [
                                    'type' => 'string',
                                    'enum' => $categoryNames,
                                    'description' => 'Categoria do produto, escolhida DENTRE as existentes. Omita se nenhuma for adequada.',
                                ],
                            ] : []),
                            'specifications' => ['type' => 'string'],
                            'moq' => ['type' => 'integer'],
                            'lead_time_days' => ['type' => 'integer'],
                            'notes' => ['type' => 'string'],
                        ],
                        'required' => ['description', 'quantity', 'unit_price'],
                    ],
                ],
                'documento_total' => ['type' => 'number', 'description' => 'Total geral do documento, se houver.'],
                'extras' => [
                    'type' => 'array',
                    'description' => 'Linhas que NÃO são produtos: taxas, frete, customization fee, descontos.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'descricao' => ['type' => 'string'],
                            'valor' => ['type' => 'number', 'description' => 'Negativo para desconto.'],
                        ],
                        'required' => ['descricao', 'valor'],
                    ],
                ],
            ],
            'required' => ['fornecedor', 'itens'],
        ];
    }
}
