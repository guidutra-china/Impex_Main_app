<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use Anthropic\Client;
use Anthropic\Messages\TextBlockParam;
use Anthropic\Messages\Tool;
use Anthropic\Messages\ToolChoiceTool;
use App\Domain\AI\Import\Exceptions\ExtractionFailedException;

/**
 * Conversationally adjusts an already-extracted supplier-quotation draft based on a
 * natural-language instruction, returning the full updated draft plus a short reply.
 * Operates only on the in-memory draft (preview-before-confirm) — never writes to the DB.
 */
class EditSupplierQuotationDraft
{
    protected Client $client;

    /** @var list<string> Existing category names the model may assign items to. */
    protected array $categoryNames = [];

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(apiKey: (string) config('services.anthropic.key'));
    }

    /**
     * @param  array<string,mixed>  $draft  the current extracted draft
     * @param  list<string>  $categoryNames  existing category names (enum constraint)
     * @return array{draft:array<string,mixed>,reply:string}
     */
    public function edit(array $draft, string $instruction, array $categoryNames = []): array
    {
        $this->categoryNames = array_values($categoryNames);

        $content = [TextBlockParam::with(
            "Cotação atual (JSON):\n"
            .json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\nInstrução do usuário: ".$instruction
        )];

        $response = $this->callModel($content);

        foreach ($response->content as $block) {
            if (($block->type ?? null) === 'tool_use' && ($block->name ?? null) === 'atualizar_cotacao') {
                $input = (array) $block->input;
                $reply = (string) ($input['reply'] ?? '');
                unset($input['reply']);

                return ['draft' => $input, 'reply' => $reply];
            }
        }

        throw new ExtractionFailedException('O modelo não retornou a cotação atualizada.');
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
            system: $this->systemPrompt(),
            tools: [Tool::with(
                inputSchema: $this->editSchema(),
                name: 'atualizar_cotacao',
                description: 'Retorna a cotação COMPLETA atualizada conforme a instrução, mais uma resposta curta ao usuário.',
            )],
            toolChoice: ToolChoiceTool::with(name: 'atualizar_cotacao'),
        );
    }

    /**
     * The draft schema plus a required `reply` field.
     *
     * @return array<string,mixed>
     */
    private function editSchema(): array
    {
        $schema = SupplierQuotationDraftSchema::schema($this->categoryNames);
        $schema['properties']['reply'] = ['type' => 'string', 'description' => 'Resposta curta ao usuário, no idioma dele.'];
        $schema['required'][] = 'reply';

        return $schema;
    }

    private function systemPrompt(): string
    {
        $language = $this->userLanguage();

        return <<<PROMPT
        You edit an already-extracted supplier quotation for a Brazil–China trading company,
        based on the user's instruction. The data is being reviewed before import.

        Rules:
        - Return the FULL updated quotation (keep unchanged fields as-is). You may add, remove or
          merge items and change supplier fields when asked.
        - Keep prices in the document currency as decimal numbers; line_total is the line amount.
        - If a category is provided, choose only from the existing category list; otherwise omit it.
        - If the user only asks a QUESTION about the quotation, answer it in `reply` and return the
          draft unchanged.
        - Write `reply` as a short message in {$language}. Never invent data.
        - Supplier contact fields (phone, email, address, etc.) may be edited when asked.
        PROMPT;
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
