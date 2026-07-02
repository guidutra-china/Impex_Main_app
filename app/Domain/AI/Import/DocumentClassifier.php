<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use Anthropic\Client;
use Anthropic\Messages\TextBlockParam;
use Anthropic\Messages\Tool;
use Anthropic\Messages\ToolChoiceTool;
use App\Domain\AI\Import\Targets\ImportTarget;

/**
 * Cheap single-call document classification: suggests which import target the
 * uploaded document belongs to. Runs on the lightweight model — the expensive
 * per-target extraction only happens after the user confirms the destination.
 * Best-effort: any uncertainty resolves to 'desconhecido'; never blocks the flow.
 */
class DocumentClassifier
{
    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(apiKey: (string) config('services.anthropic.key'));
    }

    /**
     * @param  list<object>  $documentBlocks
     * @param  array<string,ImportTarget>  $targets  targets the user may import into
     * @return array{tipo:string,confianca:string,motivo:string}
     */
    public function classify(array $documentBlocks, array $targets): array
    {
        $keys = array_keys($targets);
        $hints = implode("\n", array_map(
            fn (ImportTarget $t) => "- {$t->key()}: {$t->classifierHint()}",
            array_values($targets),
        ));

        $content = array_merge(
            [TextBlockParam::with('Classifique o tipo deste documento usando a ferramenta classificar_documento.')],
            $documentBlocks,
        );

        $response = $this->callModel($content, $keys, $hints);

        foreach ($response->content as $block) {
            if (($block->type ?? null) === 'tool_use' && ($block->name ?? null) === 'classificar_documento') {
                $input = (array) $block->input;
                $tipo = (string) ($input['tipo'] ?? 'desconhecido');

                return [
                    'tipo' => in_array($tipo, [...$keys, 'desconhecido'], true) ? $tipo : 'desconhecido',
                    'confianca' => (string) ($input['confianca'] ?? 'baixa'),
                    'motivo' => (string) ($input['motivo'] ?? ''),
                ];
            }
        }

        return ['tipo' => 'desconhecido', 'confianca' => 'baixa', 'motivo' => ''];
    }

    /**
     * Single forced-tool round-trip. Seam for tests (override to avoid the API).
     *
     * @param  list<object>  $content
     * @param  list<string>  $keys
     */
    protected function callModel(array $content, array $keys, string $hints): object
    {
        return $this->client->messages->create(
            maxTokens: 1024,
            messages: [['role' => 'user', 'content' => $content]],
            model: (string) config('services.anthropic.model', 'claude-haiku-4-5-20251001'),
            system: "Você classifica documentos comerciais de uma trading company Brasil–China.\n"
                ."Tipos possíveis:\n{$hints}\n"
                ."Se não tiver certeza razoável, use 'desconhecido'. Responda o motivo no idioma do usuário.",
            tools: [Tool::with(
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'tipo' => ['type' => 'string', 'enum' => [...$keys, 'desconhecido']],
                        'confianca' => ['type' => 'string', 'enum' => ['alta', 'media', 'baixa']],
                        'motivo' => ['type' => 'string', 'description' => 'Justificativa curta.'],
                    ],
                    'required' => ['tipo', 'confianca'],
                ],
                name: 'classificar_documento',
                description: 'Registra o tipo identificado do documento.',
            )],
            toolChoice: ToolChoiceTool::with(name: 'classificar_documento'),
        );
    }
}
