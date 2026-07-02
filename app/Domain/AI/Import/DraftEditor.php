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
 * Conversationally adjusts an already-extracted draft based on a natural-language
 * instruction, returning the full updated draft plus a short reply. Operates only
 * on the in-memory draft (preview-before-confirm) — never writes to the DB.
 */
class DraftEditor
{
    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(apiKey: (string) config('services.anthropic.key'));
    }

    /**
     * @param  array<string,mixed>  $draft  the current extracted draft
     * @return array{draft:array<string,mixed>,reply:string}
     */
    public function edit(ImportTarget $target, array $draft, string $instruction): array
    {
        $content = [TextBlockParam::with(
            "Dados atuais (JSON):\n"
            .json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\nInstrução do usuário: ".$instruction
        )];

        $response = $this->callModel($target, $content);

        foreach ($response->content as $block) {
            if (($block->type ?? null) === 'tool_use' && ($block->name ?? null) === $target->editToolName()) {
                $input = (array) $block->input;
                $reply = (string) ($input['reply'] ?? '');
                unset($input['reply']);

                return ['draft' => $input, 'reply' => $reply];
            }
        }

        throw new ExtractionFailedException('O modelo não retornou os dados atualizados.');
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
            system: str_replace(':language', $this->userLanguage(), $target->editSystemPrompt()),
            tools: [Tool::with(
                inputSchema: $this->editSchema($target),
                name: $target->editToolName(),
                description: 'Retorna os dados COMPLETOS atualizados conforme a instrução, mais uma resposta curta ao usuário.',
            )],
            toolChoice: ToolChoiceTool::with(name: $target->editToolName()),
        );
    }

    /**
     * The target's draft schema plus a required `reply` field.
     *
     * @return array<string,mixed>
     */
    private function editSchema(ImportTarget $target): array
    {
        $schema = $target->extractionSchema();
        $schema['properties']['reply'] = ['type' => 'string', 'description' => 'Resposta curta ao usuário, no idioma dele.'];
        $schema['required'][] = 'reply';

        return $schema;
    }

    private function userLanguage(): string
    {
        return match (app()->getLocale()) {
            'pt_BR' => 'Brazilian Portuguese',
            'zh_CN' => 'Simplified Chinese',
            default => 'English',
        };
    }
}
