<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use Anthropic\Client;
use Anthropic\Messages\TextBlockParam;
use Anthropic\Messages\Tool;
use Anthropic\Messages\ToolChoiceTool;
use App\Domain\AI\Import\Exceptions\ExtractionFailedException;
use App\Domain\AI\Import\Targets\ImportTarget;

/**
 * Extracts a structured draft for a given import target from document content
 * blocks via a single forced-tool call (structured output). Target-agnostic:
 * schema, prompts and tool name come from the ImportTarget. The model never
 * writes anything.
 */
class DraftExtractor
{
    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(apiKey: (string) config('services.anthropic.key'));
    }

    /**
     * @param  list<object>  $documentBlocks  content blocks from DocumentExtractor
     * @return array<string,mixed>
     */
    public function extract(ImportTarget $target, array $documentBlocks): array
    {
        $content = array_merge(
            [TextBlockParam::with($target->extractionUserPrompt())],
            $documentBlocks,
        );

        $response = $this->callModel($target, $content);

        foreach ($response->content as $block) {
            if (($block->type ?? null) === 'tool_use' && ($block->name ?? null) === $target->extractionToolName()) {
                $draft = (array) $block->input;

                if (empty($draft['itens'] ?? [])) {
                    throw new ExtractionFailedException('Nenhum item encontrado no documento.');
                }

                return $draft;
            }
        }

        throw new ExtractionFailedException('O modelo não retornou dados estruturados.');
    }

    /**
     * Single forced-tool round-trip. Seam for tests (override to avoid the API).
     *
     * @param  list<object>  $content
     */
    protected function callModel(ImportTarget $target, array $content): object
    {
        return $this->client->messages->create(
            maxTokens: 8192,
            messages: [['role' => 'user', 'content' => $content]],
            model: (string) config('services.anthropic.assistant_model', 'claude-opus-4-8'),
            system: $target->extractionSystemPrompt(),
            tools: [Tool::with(
                inputSchema: $target->extractionSchema(),
                name: $target->extractionToolName(),
                description: 'Registra os dados estruturados extraídos do documento.',
            )],
            toolChoice: ToolChoiceTool::with(name: $target->extractionToolName()),
        );
    }
}
