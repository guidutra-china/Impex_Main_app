<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use Anthropic\Client;
use Anthropic\Messages\Tool;
use Anthropic\Messages\ToolChoiceTool;

/**
 * Camada 4 do matching de produtos do import: para itens sem match
 * determinístico, pede ao modelo que aponte o produto do catálogo da empresa —
 * ou null. Uma chamada por documento, tool forçada. As camadas determinísticas
 * sempre rodam antes; o chamador trata falhas silenciosamente (o import nunca
 * trava por causa desta chamada).
 */
class ProductMatchSuggester
{
    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(apiKey: (string) config('services.anthropic.key'));
    }

    /**
     * @param  array<int, array{description:string}>  $items  índice original => item sem match
     * @param  list<array{id:int,name:string,reference_code:?string,model_number:?string,aliases:list<string>}>  $catalog
     * @return array<int,int> índice original => product_id sugerido
     */
    public function suggest(array $items, array $catalog): array
    {
        if ($items === [] || $catalog === []) {
            return [];
        }

        $validIds = array_column($catalog, 'id');

        $user = "Itens do documento sem match:\n"
            .json_encode(
                array_map(fn ($i, $item) => ['index' => $i, 'description' => $item['description']], array_keys($items), $items),
                JSON_UNESCAPED_UNICODE,
            )
            ."\n\nCatálogo da empresa:\n"
            .json_encode($catalog, JSON_UNESCAPED_UNICODE);

        $response = $this->callModel($this->systemPrompt(), $user);

        $matches = [];

        foreach ($response->content as $block) {
            if (($block->type ?? null) !== 'tool_use' || ($block->name ?? null) !== 'sugerir_vinculos') {
                continue;
            }

            foreach ((array) ($block->input->matches ?? []) as $match) {
                $index = (int) ($match->index ?? -1);
                $productId = $match->product_id ?? null;

                if ($productId !== null && isset($items[$index]) && in_array((int) $productId, $validIds, true)) {
                    $matches[$index] = (int) $productId;
                }
            }
        }

        return $matches;
    }

    protected function systemPrompt(): string
    {
        return 'Você vincula itens de documentos comerciais (cotações, PIs) a produtos já '
            .'cadastrados de uma empresa, para uma trading company. Para cada item, aponte o '
            .'product_id do catálogo que representa o MESMO produto, ou null se não houver. '
            .'Regras: tokens de peso, tamanho e dimensão devem bater EXATAMENTE (5kg nunca casa '
            .'com 10kg; 1.2m nunca casa com 1.5m). Use nomes, códigos e aliases do catálogo como '
            .'evidência. Na dúvida, retorne null — errar o vínculo é pior que não vincular. '
            .'Responda para TODOS os índices recebidos.';
    }

    /**
     * Single forced-tool round-trip. Seam for tests (override to avoid the API).
     */
    protected function callModel(string $system, string $user): object
    {
        return $this->client->messages->create(
            maxTokens: 4096,
            messages: [['role' => 'user', 'content' => $user]],
            model: (string) config('services.anthropic.assistant_model', 'claude-opus-4-8'),
            system: $system,
            tools: [Tool::with(
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'matches' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'index' => ['type' => 'integer'],
                                    'product_id' => ['type' => ['integer', 'null']],
                                ],
                                'required' => ['index', 'product_id'],
                            ],
                        ],
                    ],
                    'required' => ['matches'],
                ],
                name: 'sugerir_vinculos',
                description: 'Registra o vínculo item→produto sugerido para cada índice.',
            )],
            toolChoice: ToolChoiceTool::with(name: 'sugerir_vinculos'),
        );
    }
}
