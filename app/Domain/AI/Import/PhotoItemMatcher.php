<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use Anthropic\Client;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\TextBlockParam;
use Anthropic\Messages\Tool;
use Anthropic\Messages\ToolChoiceTool;

/**
 * Vision fallback for item↔photo matching: sends numbered thumbnails of the
 * extracted images plus the item list to the lightweight model and asks for an
 * image→items mapping (n:1 allowed for variant rows; unmapped allowed for logos
 * and factory photos). Only meant to run when the deterministic page-aware match
 * left gaps — and fully best-effort: any failure keeps the deterministic result.
 * The manual picker in the review UI remains the final authority.
 */
class PhotoItemMatcher
{
    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(apiKey: (string) config('services.anthropic.key'));
    }

    /**
     * Trigger policy: worth a vision call when the deterministic match is suspect —
     * an item ended up without photo, or pool images were left unused (per-page
     * count mismatch: multi-photo cells, stacked product strips — the positional
     * zip may have assigned neighbours' photos). A clean 1:1 document skips the call.
     *
     * @param  list<array{id:int,path:string,page:int}>  $pool
     * @param  array<int,?int>  $itemPhoto
     */
    public function shouldRun(array $pool, array $itemPhoto): bool
    {
        if ($pool === [] || $itemPhoto === []) {
            return false;
        }

        if (in_array(null, $itemPhoto, true)) {
            return true;
        }

        $used = array_unique(array_filter($itemPhoto, fn (?int $id) => $id !== null));

        return count($used) < count($pool);
    }

    /**
     * @param  list<array{id:int,path:string,page:int}>  $pool
     * @param  list<array<string,mixed>>  $itens
     * @param  array<int,?int>  $itemPhoto  deterministic assignment to refine
     * @param  bool  $applyMappings  false = orientation-only pass: flipped images are
     *                               corrected but the deterministic photo↔item mapping
     *                               is kept (used when the alignment wasn't suspect)
     * @param  list<array{page:int,path:string}>  $pageRenders  páginas renderizadas do
     *                                                          documento — contexto de layout essencial para saber
     *                                                          qual foto está em qual linha da tabela
     * @return array<int,?int>
     */
    public function reconcile(array $pool, array $itens, array $itemPhoto, bool $applyMappings = true, array $pageRenders = []): array
    {
        try {
            $content = $this->buildContent($pool, $itens, $pageRenders);
            if ($content === null) {
                return $itemPhoto;
            }

            $response = $this->callModel($content);

            foreach ($response->content as $block) {
                if (($block->type ?? null) === 'tool_use' && ($block->name ?? null) === 'mapear_fotos') {
                    $input = (array) $block->input;

                    $this->fixFlippedImages($input, $pool);

                    return $applyMappings ? $this->merge($input, $pool, $itens, $itemPhoto) : $itemPhoto;
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $itemPhoto;
    }

    /**
     * Excel→PDF grava os pixels de algumas imagens de cabeça para baixo e corrige
     * só na renderização (CTM negativo) — o pdfimages extrai o espelho. O modelo
     * aponta quais estão invertidas e o arquivo é corrigido in-place (miniaturas e
     * anexos passam a ler a versão certa).
     *
     * @param  array<string,mixed>  $input
     * @param  list<array{id:int,path:string,page:int}>  $pool
     */
    private function fixFlippedImages(array $input, array $pool): void
    {
        $byId = array_column($pool, null, 'id');

        foreach ((array) ($input['imagens_invertidas'] ?? []) as $poolId) {
            $entry = $byId[(int) $poolId] ?? null;
            if ($entry !== null) {
                self::flipVertical($entry['path']);
            }
        }
    }

    /** Espelha o arquivo de imagem verticalmente, regravando no mesmo caminho (best-effort). */
    public static function flipVertical(string $path): void
    {
        if (! is_file($path) || ! function_exists('imageflip')) {
            return;
        }

        $data = @file_get_contents($path);
        $src = ($data !== false && $data !== '') ? @imagecreatefromstring($data) : false;
        if ($src === false) {
            return;
        }

        imageflip($src, IMG_FLIP_VERTICAL);

        match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => imagejpeg($src, $path, 92),
            'gif' => imagegif($src, $path),
            'webp' => imagewebp($src, $path),
            default => imagepng($src, $path),
        };

        imagedestroy($src);
    }

    /**
     * Vision mappings win for the items they cover; everything else keeps the
     * deterministic assignment — EXCETO quando a visão atribuiu aquela mesma imagem
     * a outros itens: aí o palpite determinístico está desmentido e o item fica sem
     * foto (caso real: linhas cuja foto não existe no PDF herdavam a foto do
     * vizinho). Out-of-range ids are ignored.
     *
     * @param  array<string,mixed>  $input
     * @param  list<array{id:int,path:string,page:int}>  $pool
     * @param  list<array<string,mixed>>  $itens
     * @param  array<int,?int>  $itemPhoto
     * @return array<int,?int>
     */
    private function merge(array $input, array $pool, array $itens, array $itemPhoto): array
    {
        $validPoolIds = array_column($pool, 'id');
        $covered = [];
        $claimed = [];

        foreach ((array) ($input['mapeamentos'] ?? []) as $mapping) {
            $mapping = (array) $mapping;
            $poolId = (int) ($mapping['imagem'] ?? -1);
            if (! in_array($poolId, $validPoolIds, true)) {
                continue;
            }

            foreach ((array) ($mapping['itens'] ?? []) as $itemIndex) {
                $itemIndex = (int) $itemIndex;
                if (array_key_exists($itemIndex, $itens)) {
                    $itemPhoto[$itemIndex] = $poolId;
                    $covered[$itemIndex] = true;
                    $claimed[$poolId] = true;
                }
            }
        }

        foreach ($itemPhoto as $itemIndex => $poolId) {
            if ($poolId !== null && ! isset($covered[$itemIndex]) && isset($claimed[$poolId])) {
                $itemPhoto[$itemIndex] = null;
            }
        }

        return $itemPhoto;
    }

    /**
     * Page renders (layout context), numbered thumbnails (kept small — the pool can
     * have dozens of images) plus a compact item list. Returns null when no
     * thumbnail could be produced.
     *
     * @param  list<array{id:int,path:string,page:int}>  $pool
     * @param  list<array<string,mixed>>  $itens
     * @param  list<array{page:int,path:string}>  $pageRenders
     * @return list<object>|null
     */
    private function buildContent(array $pool, array $itens, array $pageRenders = []): ?array
    {
        $content = [TextBlockParam::with(
            'Associe cada IMAGEM aos ITENS correspondentes da lista. Uma imagem pode pertencer a '
            .'vários itens (variações do mesmo produto) ou a nenhum (logo, foto de fábrica, decoração). '
            .'Use as páginas renderizadas do documento para localizar em QUAL LINHA da tabela cada '
            .'foto aparece — a posição na tabela determina o item.'
        )];

        foreach ($pageRenders as $render) {
            $b64 = $this->thumbnailBase64($render['path'], 1200);
            if ($b64 === null) {
                continue;
            }
            $content[] = TextBlockParam::with("Página {$render['page']} do documento (layout completo):");
            $content[] = ImageBlockParam::with(source: Base64ImageSource::with(data: $b64, mediaType: 'image/png'));
        }

        $anyImage = false;
        foreach ($pool as $entry) {
            $thumb = $this->thumbnailBase64($entry['path']);
            if ($thumb === null) {
                continue;
            }
            $anyImage = true;
            $page = ($entry['page'] ?? 0) > 0 ? " (página {$entry['page']})" : '';
            $content[] = TextBlockParam::with("Imagem {$entry['id']}{$page}:");
            $content[] = ImageBlockParam::with(source: Base64ImageSource::with(data: $thumb, mediaType: 'image/png'));
        }

        if (! $anyImage) {
            return null;
        }

        $lines = [];
        foreach ($itens as $i => $item) {
            $page = (int) ($item['page'] ?? 0);
            $lines[] = $i.' | '.(($item['part_no'] ?? null) ?: '-')
                .' | '.mb_substr((string) ($item['description'] ?? ''), 0, 120)
                .($page > 0 ? ' | página '.$page : '');
        }
        $content[] = TextBlockParam::with("Itens (índice | part_no | descrição | página):\n".implode("\n", $lines));

        return $content;
    }

    /** Downscaled PNG (max $max px) as raw base64; null when the file can't be read as an image. */
    private function thumbnailBase64(string $path, int $max = 256): ?string
    {
        if (! is_file($path) || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            return null;
        }

        $src = @imagecreatefromstring($data);
        if ($src === false) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $max / max(1, $w, $h));
        $thumb = imagescale($src, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));

        ob_start();
        imagepng($thumb !== false ? $thumb : $src);
        $png = (string) ob_get_clean();

        imagedestroy($src);
        if ($thumb !== false) {
            imagedestroy($thumb);
        }

        return base64_encode($png);
    }

    /**
     * Single forced-tool round-trip on the lightweight model. Seam for tests.
     *
     * @param  list<object>  $content
     */
    protected function callModel(array $content): object
    {
        return $this->client->messages->create(
            maxTokens: 2048,
            messages: [['role' => 'user', 'content' => $content]],
            // Modelo principal (não o haiku): o pareamento visual de dezenas de fotos
            // parecidas de equipamento de ginástica é exatamente onde o modelo barato
            // errava — e cada erro vira foto errada em produto do catálogo.
            model: (string) config('services.anthropic.assistant_model', 'claude-opus-4-8'),
            system: 'Você associa fotos de produtos extraídas de um documento comercial aos itens da lista. '
                .'Quando as páginas renderizadas do documento forem fornecidas, use-as como fonte '
                .'principal: localize cada foto na sua LINHA da tabela e mapeie para o item daquela '
                .'linha — a posição vale mais que a semelhança visual entre produtos parecidos. '
                .'Uma imagem pode mapear para vários itens (variações do mesmo produto). '
                .'NÃO mapeie imagens que não sejam fotos de produto (logos, fotos de fábrica/ambiente). '
                .'ATENÇÃO: algumas linhas do documento podem NÃO ter foto extraída — nesse caso não '
                .'mapeie nada para elas; nunca atribua a mesma imagem a itens de linhas diferentes do '
                .'documento (só a variações da MESMA linha). '
                .'Procure mapear TODOS os itens que tenham foto no documento; deixe sem mapear apenas '
                .'o que realmente não der para localizar. '
                .'Liste em imagens_invertidas os números das imagens que estão de cabeça para baixo '
                .'ou espelhadas verticalmente (texto/objeto invertido).',
            tools: [Tool::with(
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'mapeamentos' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'imagem' => ['type' => 'integer', 'description' => 'Número da imagem.'],
                                    'itens' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Índices dos itens correspondentes.'],
                                ],
                                'required' => ['imagem', 'itens'],
                            ],
                        ],
                        'imagens_invertidas' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Números das imagens de cabeça para baixo / espelhadas verticalmente.',
                        ],
                    ],
                    'required' => ['mapeamentos'],
                ],
                name: 'mapear_fotos',
                description: 'Registra o mapeamento imagem → itens e as imagens com orientação invertida.',
            )],
            toolChoice: ToolChoiceTool::with(name: 'mapear_fotos'),
        );
    }
}
