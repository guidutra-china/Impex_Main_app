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
 *
 * O corte considera linhas E volume de caracteres — linha é proxy ruim para o
 * tamanho da SAÍDA (caso real Jinmu: 48 itens com listas de compatibilidade de
 * 340 chars estouravam 8192 tokens em 51 linhas). Defesa em profundidade: se
 * mesmo assim uma resposta vier truncada (stop_reason=max_tokens), o resultado
 * parcial é descartado e o intervalo é dividido ao meio e re-extraído; PDFs
 * (bloco único, sem linhas para dividir) falham com mensagem clara.
 */
class DraftExtractor
{
    /** Tamanho-alvo de cada parte, em linhas (o real é distribuído por igual). */
    private const CHUNK_SIZE = 100;

    /** Orçamento-alvo de caracteres do texto achatado por parte. */
    private const CHUNK_CHAR_BUDGET = 6000;

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

        if ($ranges === null) {
            [$draft, $truncated] = $this->extractOnce($target, $documentBlocks, $target->extractionUserPrompt());

            if ($truncated) {
                // Resposta cortada em maxTokens: o parcial é inútil (o array de
                // itens some no parse). Re-extrai o documento em duas metades.
                $lines = $this->flattenedLineCount($documentBlocks);
                if ($lines === null || $lines < 2) {
                    throw $this->tooDenseException();
                }
                $mid = intdiv(1 + $lines, 2);
                $draft = $this->extractChunked($target, $documentBlocks, [[1, $mid], [$mid + 1, $lines]]);
            }
        } else {
            $draft = $this->extractChunked($target, $documentBlocks, $ranges);
        }

        if (empty($draft['itens'] ?? [])) {
            throw new ExtractionFailedException('Nenhum item encontrado no documento.');
        }

        return $draft;
    }

    /**
     * Line ranges when the payload is a large or dense flattened spreadsheet;
     * null keeps the single-call path (small light documents, PDFs). Chunk count
     * honours BOTH the line and the character budget — output size follows text
     * volume, not row count — and ranges are evenly sized so no tiny trailing
     * chunk is produced.
     *
     * @param  list<object>  $documentBlocks
     * @return list<array{int,int}>|null
     */
    private function chunkRanges(array $documentBlocks): ?array
    {
        $lines = $this->flattenedLineCount($documentBlocks);
        if ($lines === null) {
            return null;
        }

        $chunks = max(
            (int) ceil($lines / self::CHUNK_SIZE),
            (int) ceil(strlen($documentBlocks[0]->text) / self::CHUNK_CHAR_BUDGET),
        );
        if ($chunks <= 1 || $lines < 2) {
            return null;
        }

        $size = (int) ceil($lines / min($chunks, $lines));

        $ranges = [];
        for ($from = 1; $from <= $lines; $from += $size) {
            $ranges[] = [$from, min($from + $size - 1, $lines)];
        }

        return $ranges;
    }

    /**
     * Nº de LINHAS DE PLANILHA do texto achatado (maior rótulo "Linha N:"), ou
     * null quando o payload não é uma planilha achatada (ex.: PDF). Contar "\n"
     * cru superestima: células multi-linha ("Weight: X\nFits: ...") criavam
     * intervalos além da última linha real e o modelo fabricava itens/source_rows
     * para o intervalo fantasma (caso real IMPEX 5th shipment: 376 vs 276).
     */
    private function flattenedLineCount(array $documentBlocks): ?int
    {
        if (count($documentBlocks) !== 1 || ! $documentBlocks[0] instanceof TextBlockParam) {
            return null;
        }

        preg_match_all('/^Linha (\d+):/m', $documentBlocks[0]->text, $matches);

        return $matches[1] === [] ? 1 : max(array_map('intval', $matches[1]));
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
            foreach ($this->extractRangeDrafts($target, $documentBlocks, $from, $to, first: $index === 0) as $draft) {
                $merged = $this->mergeDraft($merged, $draft, $seenRows);
            }
        }

        return $merged ?? [];
    }

    /**
     * Extrai um intervalo de linhas; se a resposta truncar em maxTokens, divide
     * o intervalo ao meio e re-extrai cada metade (recursivo). Um intervalo de
     * UMA linha que ainda trunca não tem mais como encolher → documento denso.
     *
     * @param  list<object>  $documentBlocks
     * @return list<array<string,mixed>> drafts em ordem de linha
     */
    private function extractRangeDrafts(ImportTarget $target, array $documentBlocks, int $from, int $to, bool $first): array
    {
        $prompt = $target->extractionUserPrompt().$this->chunkInstruction($from, $to, $first);
        [$draft, $truncated] = $this->extractOnce($target, $documentBlocks, $prompt);

        if (! $truncated) {
            return [$draft];
        }

        if ($from >= $to) {
            throw $this->tooDenseException();
        }

        $mid = intdiv($from + $to, 2);

        return array_merge(
            $this->extractRangeDrafts($target, $documentBlocks, $from, $mid, $first),
            $this->extractRangeDrafts($target, $documentBlocks, $mid + 1, $to, false),
        );
    }

    /**
     * Mescla um draft de chunk no acumulado: itens concatenados (dedupe por
     * source_row — o modelo pode vazar um item de intervalo vizinho), extras
     * concatenados, documento_total preservado; cabeçalho vem do primeiro.
     *
     * @param  array<string,mixed>|null  $merged
     * @param  array<string,mixed>  $draft
     * @param  array<int,bool>  $seenRows
     * @return array<string,mixed>
     */
    private function mergeDraft(?array $merged, array $draft, array &$seenRows): array
    {
        $items = [];
        foreach (array_values((array) ($draft['itens'] ?? [])) as $item) {
            $item = (array) $item;
            $row = $item['source_row'] ?? null;
            if (is_numeric($row)) {
                if (isset($seenRows[(int) $row])) {
                    continue;
                }
                $seenRows[(int) $row] = true;
            }
            $items[] = $item;
        }

        if ($merged === null) {
            $draft['itens'] = $items;

            return $draft;
        }

        $merged['itens'] = array_merge((array) ($merged['itens'] ?? []), $items);
        $merged['extras'] = array_merge((array) ($merged['extras'] ?? []), (array) ($draft['extras'] ?? []));
        if (empty($merged['documento_total']) && ! empty($draft['documento_total'])) {
            $merged['documento_total'] = $draft['documento_total'];
        }

        return $merged;
    }

    private function tooDenseException(): ExtractionFailedException
    {
        return new ExtractionFailedException(
            'O documento é denso demais para a extração estruturada (a resposta excedeu o limite '
            .'e veio truncada). Divida o arquivo em partes menores e importe cada uma.',
        );
    }

    private function chunkInstruction(int $from, int $to, bool $first): string
    {
        // source_row fabricado quebra o pareamento de fotos (ancorado na linha
        // real); intervalo sem produto deve voltar vazio — o modelo já inventou
        // "itens explicativos" para um intervalo sem linhas de produto.
        $guard = 'Em source_row use EXATAMENTE o número do rótulo "Linha N:" da linha do item — nunca invente. '
            .'Se não houver itens de produto no intervalo, retorne itens como lista vazia — '
            .'NUNCA crie itens de observação ou explicação.';

        if ($first) {
            return "\n\nATENÇÃO: documento grande — a extração está sendo feita em partes. "
                ."Nesta parte, extraia os dados completos do fornecedor/cabeçalho e SOMENTE os itens até a Linha {$to} (inclusive), "
                ."preenchendo source_row. NÃO inclua itens após a Linha {$to}: eles serão extraídos em outra parte. "
                .$guard;
        }

        return "\n\nATENÇÃO: documento grande — a extração está sendo feita em partes. "
            ."Extraia SOMENTE os itens (e extras) da Linha {$from} até a Linha {$to} (inclusive), preenchendo source_row. "
            .'NÃO inclua itens fora desse intervalo: eles já foram extraídos em outra parte. '
            .'No fornecedor, preencha apenas o nome. '
            .$guard;
    }

    /**
     * One forced-tool round-trip. Returns the parsed draft plus whether the
     * response was cut at maxTokens (partial JSON — o array de itens some no
     * parse, então o chamador descarta e re-divide). Throws only when a
     * NON-truncated response carries no structured tool call.
     *
     * @param  list<object>  $documentBlocks
     * @return array{0:array<string,mixed>,1:bool}
     */
    private function extractOnce(ImportTarget $target, array $documentBlocks, string $userPrompt): array
    {
        $content = array_merge([TextBlockParam::with($userPrompt)], $documentBlocks);

        $response = $this->callModel($target, $content);

        $stop = $response->stopReason ?? null;
        $truncated = ($stop instanceof \BackedEnum ? $stop->value : $stop) === 'max_tokens';

        foreach ($response->content as $block) {
            if (($block->type ?? null) === 'tool_use' && ($block->name ?? null) === $target->extractionToolName()) {
                return [(array) $block->input, $truncated];
            }
        }

        // Truncamento agressivo pode cortar o próprio bloco de tool_use.
        if ($truncated) {
            return [[], true];
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
