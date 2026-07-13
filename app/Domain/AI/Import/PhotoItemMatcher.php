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
     *                                                          qual foto está em qual linha da tabela e qual a
     *                                                          orientação correta de cada foto
     * @param  bool  $checkOrientation  roda a verificação dedicada de orientação antes
     *                                  do mapeamento (uma chamada extra de visão)
     * @return array<int,?int>
     */
    public function reconcile(array $pool, array $itens, array $itemPhoto, bool $applyMappings = true, array $pageRenders = [], bool $checkOrientation = false): array
    {
        if ($checkOrientation) {
            // Antes do mapeamento: os thumbnails da chamada de mapeamento releem os
            // arquivos já corrigidos.
            $this->fixOrientation($pool, $pageRenders);
        }

        if (! $applyMappings) {
            return $itemPhoto;
        }

        try {
            $content = $this->buildContent($pool, $itens, $pageRenders);
            if ($content === null) {
                return $itemPhoto;
            }

            $response = $this->callModel($content);

            foreach ($response->content as $block) {
                if (($block->type ?? null) === 'tool_use' && ($block->name ?? null) === 'mapear_fotos') {
                    return $this->merge((array) $block->input, $pool, $itens, $itemPhoto);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $itemPhoto;
    }

    /**
     * Verificação DEDICADA de orientação: geradores Excel→PDF gravam os pixels de
     * parte (às vezes TODAS) as imagens espelhados verticalmente e corrigem só na
     * renderização — o pdfimages extrai o espelho, e não há sinal determinístico
     * (o CTM é negativo para todas, invertidas ou não; a diferença está nos JPEGs).
     * Uma tarefa focada com as páginas renderizadas como gabarito é muito mais
     * confiável do que pegar carona na chamada de mapeamento. Best-effort.
     *
     * @param  list<array{id:int,path:string,page:int}>  $pool
     * @param  list<array{page:int,path:string}>  $pageRenders
     */
    private function fixOrientation(array $pool, array $pageRenders): void
    {
        try {
            // Comparação A/B: julgar uma foto isolada é difícil (halteres na diagonal
            // não têm pista de gravidade); escolher entre a original e a espelhada é
            // trivial. Cada imagem entra 2x — só nesta chamada, thumbnails 384px.
            $content = [TextBlockParam::with(
                'Para cada imagem numerada há duas versões: A (como foi extraída) e B (espelhada '
                .'verticalmente). Escolha, para CADA imagem, qual versão está na orientação '
                .'NATURAL correta: produto apoiado/pendurado de forma plausível, texto e números '
                .'legíveis (ex: "5KG"), sombras e chão embaixo. As páginas renderizadas do '
                .'documento mostram as fotos na orientação correta — use-as como referência.'
            )];

            foreach ($pageRenders as $render) {
                $b64 = $this->thumbnailBase64($render['path'], 1200);
                if ($b64 === null) {
                    continue;
                }
                $content[] = TextBlockParam::with("Página {$render['page']} do documento (referência, orientação correta):");
                $content[] = ImageBlockParam::with(source: Base64ImageSource::with(data: $b64, mediaType: 'image/png'));
            }

            $anyImage = false;
            foreach ($pool as $entry) {
                $original = $this->thumbnailBase64($entry['path'], 384);
                $flipped = $this->thumbnailBase64($entry['path'], 384, flipVertical: true);
                if ($original === null || $flipped === null) {
                    continue;
                }
                $anyImage = true;
                $content[] = TextBlockParam::with("Imagem {$entry['id']} — versão A:");
                $content[] = ImageBlockParam::with(source: Base64ImageSource::with(data: $original, mediaType: 'image/png'));
                $content[] = TextBlockParam::with("Imagem {$entry['id']} — versão B:");
                $content[] = ImageBlockParam::with(source: Base64ImageSource::with(data: $flipped, mediaType: 'image/png'));
            }

            if (! $anyImage) {
                return;
            }

            $response = $this->callOrientationModel($content);

            $byId = array_column($pool, null, 'id');
            foreach ($response->content as $block) {
                if (($block->type ?? null) === 'tool_use' && ($block->name ?? null) === 'marcar_invertidas') {
                    foreach ((array) (((array) $block->input)['avaliacoes'] ?? []) as $avaliacao) {
                        $avaliacao = (array) $avaliacao;
                        if (strtoupper((string) ($avaliacao['correta'] ?? 'A')) !== 'B') {
                            continue;
                        }
                        $entry = $byId[(int) ($avaliacao['imagem'] ?? -1)] ?? null;
                        if ($entry !== null) {
                            self::flipVertical($entry['path']);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Single forced-tool round-trip for the orientation check. Seam for tests.
     *
     * @param  list<object>  $content
     */
    protected function callOrientationModel(array $content): object
    {
        return $this->client->messages->create(
            maxTokens: 1024,
            messages: [['role' => 'user', 'content' => $content]],
            model: (string) config('services.anthropic.assistant_model', 'claude-opus-4-8'),
            system: 'Você compara duas versões (A = extraída, B = espelhada verticalmente) de fotos '
                .'de produto extraídas de um PDF e escolhe a que está na orientação natural correta. '
                .'Pistas decisivas: texto e números legíveis (ex: "5KG" nos pesos), produto apoiado '
                .'no chão ou pendurado de forma plausível, sombras embaixo. '
                .'Julgue CADA imagem de forma INDEPENDENTE, apenas pelo conteúdo dela — documentos '
                .'variam: às vezes todas as fotos estão corretas (A), às vezes todas invertidas (B), '
                .'às vezes misto. Não assuma padrão; na dúvida, prefira A (não mexer).',
            tools: [Tool::with(
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'avaliacoes' => [
                            'type' => 'array',
                            'description' => 'Uma avaliação para CADA imagem numerada.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'imagem' => ['type' => 'integer'],
                                    'correta' => ['type' => 'string', 'enum' => ['A', 'B'], 'description' => 'Versão na orientação correta.'],
                                ],
                                'required' => ['imagem', 'correta'],
                            ],
                        ],
                    ],
                    'required' => ['avaliacoes'],
                ],
                name: 'marcar_invertidas',
                description: 'Registra qual versão (A ou B) de cada imagem está na orientação correta.',
            )],
            toolChoice: ToolChoiceTool::with(name: 'marcar_invertidas'),
        );
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
    private function thumbnailBase64(string $path, int $max = 256, bool $flipVertical = false): ?string
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

        $out = $thumb !== false ? $thumb : $src;
        if ($flipVertical && function_exists('imageflip')) {
            imageflip($out, IMG_FLIP_VERTICAL);
        }

        ob_start();
        imagepng($out);
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
                .'o que realmente não der para localizar.',
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
                    ],
                    'required' => ['mapeamentos'],
                ],
                name: 'mapear_fotos',
                description: 'Registra o mapeamento imagem → itens.',
            )],
            toolChoice: ToolChoiceTool::with(name: 'mapear_fotos'),
        );
    }
}
