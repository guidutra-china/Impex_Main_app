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
 * blocks via forced-tool calls (structured output). Target-agnostic: schema,
 * prompts and tool name come from the ImportTarget. The model never writes
 * anything.
 *
 * Planilhas grandes são extraídas em partes: o JSON de centenas de itens não
 * cabe no maxTokens de uma resposta (caso real: contrato com 274 itens veio
 * truncado com stop_reason=max_tokens e a extração falhava). Cada chunk recebe
 * o documento INTEIRO (o custo está na saída, não na entrada) com instrução de
 * extrair somente um intervalo de linhas; os drafts são mesclados ao final.
 */
class DraftExtractor
{
    /** Acima deste nº de linhas achatadas a planilha é extraída em partes. */
    private const CHUNK_THRESHOLD = 100;

    /** Tamanho-alvo de cada parte, em linhas (o real é distribuído por igual). */
    private const CHUNK_SIZE = 100;

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
        $ranges = $this->chunkRanges($documentBlocks);

        $draft = $ranges === null
            ? $this->extractOnce($target, $documentBlocks, $target->extractionUserPrompt())
            : $this->extractChunked($target, $documentBlocks, $ranges);

        if (empty($draft['itens'] ?? [])) {
            throw new ExtractionFailedException('Nenhum item encontrado no documento.');
        }

        return $draft;
    }

    /**
     * Line ranges when the payload is a large flattened spreadsheet; null keeps
     * the single-call path (small documents, PDFs). Ranges are evenly sized so
     * no tiny trailing chunk is produced.
     *
     * @param  list<object>  $documentBlocks
     * @return list<array{int,int}>|null
     */
    private function chunkRanges(array $documentBlocks): ?array
    {
        if (count($documentBlocks) !== 1 || ! $documentBlocks[0] instanceof TextBlockParam) {
            return null;
        }

        $lines = substr_count($documentBlocks[0]->text, "\n") + 1;
        if ($lines <= self::CHUNK_THRESHOLD) {
            return null;
        }

        $size = (int) ceil($lines / ceil($lines / self::CHUNK_SIZE));

        $ranges = [];
        for ($from = 1; $from <= $lines; $from += $size) {
            $ranges[] = [$from, min($from + $size - 1, $lines)];
        }

        return $ranges;
    }

    /**
     * @param  list<object>  $documentBlocks
     * @param  list<array{int,int}>  $ranges
     * @return array<string,mixed>
     */
    private function extractChunked(ImportTarget $target, array $documentBlocks, array $ranges): array
    {
        $merged = null;
        $seenRows = [];

        foreach ($ranges as $index => [$from, $to]) {
            $prompt = $target->extractionUserPrompt().$this->chunkInstruction($from, $to, first: $index === 0);
            $draft = $this->extractOnce($target, $documentBlocks, $prompt);

            $items = [];
            foreach (array_values((array) ($draft['itens'] ?? [])) as $item) {
                $item = (array) $item;
                $row = $item['source_row'] ?? null;
                if (is_numeric($row)) {
                    if (isset($seenRows[(int) $row])) {
                        continue; // o modelo vazou um item de outro intervalo — fica a 1ª ocorrência
                    }
                    $seenRows[(int) $row] = true;
                }
                $items[] = $item;
            }

            if ($merged === null) {
                $merged = $draft;
                $merged['itens'] = $items;

                continue;
            }

            $merged['itens'] = array_merge($merged['itens'], $items);
            $merged['extras'] = array_merge((array) ($merged['extras'] ?? []), (array) ($draft['extras'] ?? []));
            if (empty($merged['documento_total']) && ! empty($draft['documento_total'])) {
                $merged['documento_total'] = $draft['documento_total'];
            }
        }

        return $merged ?? [];
    }

    private function chunkInstruction(int $from, int $to, bool $first): string
    {
        if ($first) {
            return "\n\nATENÇÃO: documento grande — a extração está sendo feita em partes. "
                ."Nesta parte, extraia os dados completos do fornecedor/cabeçalho e SOMENTE os itens até a Linha {$to} (inclusive), "
                ."preenchendo source_row. NÃO inclua itens após a Linha {$to}: eles serão extraídos em outra parte.";
        }

        return "\n\nATENÇÃO: documento grande — a extração está sendo feita em partes. "
            ."Extraia SOMENTE os itens (e extras) da Linha {$from} até a Linha {$to} (inclusive), preenchendo source_row. "
            .'NÃO inclua itens fora desse intervalo: eles já foram extraídos em outra parte. '
            .'No fornecedor, preencha apenas o nome. Se não houver itens no intervalo, retorne itens vazio.';
    }

    /**
     * One forced-tool round-trip. Throws only when the response carries no
     * structured tool call — an empty item list is legítimo em chunks.
     *
     * @param  list<object>  $documentBlocks
     * @return array<string,mixed>
     */
    private function extractOnce(ImportTarget $target, array $documentBlocks, string $userPrompt): array
    {
        $content = array_merge([TextBlockParam::with($userPrompt)], $documentBlocks);

        $response = $this->callModel($target, $content);

        foreach ($response->content as $block) {
            if (($block->type ?? null) === 'tool_use' && ($block->name ?? null) === $target->extractionToolName()) {
                return (array) $block->input;
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
