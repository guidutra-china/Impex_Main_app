<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use App\Domain\AI\Import\Models\ProductImportAlias;
use App\Domain\AI\Import\Support\NameNormalizer;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;

/**
 * Deterministically resolves an extracted inquiry draft into a preview model:
 * matches the client company by name and each product by part number vs
 * reference_code/model_number/sku, with a client-scoped exact-name fallback,
 * a learned alias layer, and an LLM suggestion layer for items still unmatched
 * (match-only — inquiry items never create products; unmatched items keep
 * product_id null with the description, mirroring the deterministic Excel
 * import). No writes.
 */
class ResolveInquiryDraft
{
    public function __construct(private readonly ProductMatchSuggester $suggester = new ProductMatchSuggester) {}

    /**
     * @param  array<string,mixed>  $draft
     * @return array<string,mixed>
     */
    public function resolve(array $draft): array
    {
        $clientName = trim((string) ($draft['cliente']['nome'] ?? ''));
        $client = $clientName !== ''
            ? Company::where('name', 'like', '%'.$clientName.'%')->first()
            : null;

        $currency = strtoupper((string) ($draft['cliente']['currency_code'] ?? 'USD'));
        $clientProductsByName = $this->clientProductsByName($client);
        $aliasesByKey = $this->aliasesByKey($client);
        $itens = array_map(fn (array $item) => $this->resolveItem($item, $clientProductsByName, $aliasesByKey), $draft['itens'] ?? []);
        $itens = $this->applyAiSuggestions($itens, $client);

        $matched = count(array_filter($itens, fn ($i) => $i['status'] === 'existente'));
        $totalMinor = array_sum(array_map(
            fn ($i) => ($i['target_price_minor'] ?? 0) * $i['quantity'],
            $itens,
        ));

        return [
            'cliente' => [
                'status' => $client ? 'existente' : 'novo',
                'company_id' => $client?->id,
                'nome' => $client?->name ?? $clientName,
                'contato' => $draft['cliente']['contato'] ?? null,
            ],
            'cabecalho' => [
                'currency_code' => $currency,
                'deadline' => $draft['cliente']['deadline'] ?? null,
                'notes' => $draft['cliente']['notes'] ?? null,
            ],
            'itens' => $itens,
            'resumo' => [
                'total_itens' => count($itens),
                'produtos_casados' => $matched,
                'produtos_sem_match' => count($itens) - $matched,
                // Zero stays hidden ("no prices" and "all zero" are indistinguishable), but any non-zero total — even negative — must surface for review.
                'total_estimado' => $totalMinor !== 0 ? $currency.' '.Money::format($totalMinor) : null,
            ],
        ];
    }

    /**
     * Produtos do cliente indexados por nome normalizado — usados como
     * fallback de match quando o documento não traz números de modelo (só
     * nomes descritivos), evitando recriar produtos que já existem. Nomes
     * repetidos dentro do cliente são descartados (match precisa ser único).
     *
     * Sem filtro de role: qualquer produto vinculado a esta empresa é
     * candidato, independentemente do papel (role) desse vínculo ou de o
     * produto também ser fornecido por outra empresa.
     *
     * @return array<string, Product>
     */
    private function clientProductsByName(?Company $client): array
    {
        if ($client === null) {
            return [];
        }

        $map = [];
        $duplicated = [];

        Product::query()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $client->id))
            ->get(['id', 'name'])
            ->each(function (Product $product) use (&$map, &$duplicated) {
                $key = NameNormalizer::normalize($product->name);
                if ($key === '' || isset($duplicated[$key])) {
                    return;
                }
                if (isset($map[$key])) {
                    unset($map[$key]);
                    $duplicated[$key] = true;

                    return;
                }
                $map[$key] = $product;
            });

        return $map;
    }

    /**
     * Aliases aprendidos da empresa, indexados pela descrição normalizada.
     *
     * @return array<string, int> alias_normalized => product_id
     */
    private function aliasesByKey(?Company $client): array
    {
        if ($client === null) {
            return [];
        }

        $aliases = ProductImportAlias::query()
            ->where('company_id', $client->id)
            ->pluck('product_id', 'alias_normalized')
            ->all();

        unset($aliases['']);

        return $aliases;
    }

    /**
     * Camada 4: sugestão LLM para itens ainda sem match. Erros degradam
     * silenciosamente — o import nunca trava por causa desta chamada.
     *
     * @param  list<array<string,mixed>>  $itens
     * @return list<array<string,mixed>>
     */
    private function applyAiSuggestions(array $itens, ?Company $client): array
    {
        if ($client === null) {
            return $itens;
        }

        $unmatched = array_filter($itens, fn ($i) => $i['product_id'] === null);

        if ($unmatched === []) {
            return $itens;
        }

        $catalog = [];

        try {
            $catalog = $this->catalogFor($client);
            $suggestions = $this->suggester->suggest(
                array_map(fn ($i) => ['description' => (string) $i['description'], 'specs' => $i['specifications'] ?? null], $unmatched),
                $catalog,
            );
        } catch (\Throwable $e) {
            report($e);

            return $itens;
        }

        $byId = array_column($catalog, null, 'id');

        foreach ($suggestions as $index => $productId) {
            // Defesa em profundidade: nunca sobrescrever match determinístico,
            // nunca aceitar produto fora do catálogo do cliente.
            if (! isset($itens[$index]) || $itens[$index]['product_id'] !== null || ! isset($byId[$productId])) {
                continue;
            }

            $itens[$index]['status'] = 'existente';
            $itens[$index]['product_id'] = (int) $productId;
            $itens[$index]['product_name'] = $byId[$productId]['name'];
            $itens[$index]['match_source'] = 'ai';
        }

        return $itens;
    }

    /**
     * Catálogo da empresa para o prompt do matcher: produtos + até 5 aliases
     * mais recentes por produto.
     *
     * @return list<array{id:int,name:string,reference_code:?string,model_number:?string,aliases:list<string>}>
     */
    private function catalogFor(Company $client): array
    {
        $aliases = ProductImportAlias::query()
            ->where('company_id', $client->id)
            ->orderByDesc('last_confirmed_at')
            ->get(['product_id', 'alias'])
            ->groupBy('product_id')
            ->map(fn ($group) => $group->take(5)->pluck('alias')->all());

        return Product::query()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $client->id))
            ->get(['id', 'name', 'reference_code', 'model_number'])
            ->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'name' => $p->name,
                'reference_code' => $p->reference_code,
                'model_number' => $p->model_number,
                'aliases' => $aliases->get($p->id, []),
            ])
            ->all();
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string, Product>  $clientProductsByName
     * @param  array<string, int>  $aliasesByKey
     * @return array<string,mixed>
     */
    private function resolveItem(array $item, array $clientProductsByName, array $aliasesByKey): array
    {
        $matchSource = null;

        $partNo = trim((string) ($item['part_no'] ?? ''));
        $product = $partNo !== ''
            ? Product::where('reference_code', $partNo)
                ->orWhere('model_number', $partNo)
                ->orWhere('sku', $partNo)
                ->first()
            : null;

        if ($product !== null) {
            $matchSource = 'part_no';
        }

        $descriptionKey = NameNormalizer::normalize($item['description'] ?? null);

        // Documento sem coluna de modelo: casa a descrição com o NOME dos
        // produtos deste cliente (exato, normalizado, único).
        if ($product === null) {
            $product = $clientProductsByName[$descriptionKey] ?? null;
            $matchSource = $product !== null ? 'name' : null;
        }

        // Camada 3: alias aprendido em imports confirmados anteriores.
        if ($product === null) {
            $aliasProductId = $aliasesByKey[$descriptionKey] ?? null;
            $product = $aliasProductId !== null ? Product::find($aliasProductId) : null;
            $matchSource = $product !== null ? 'alias' : null;
        }

        $targetPrice = $item['target_price'] ?? null;

        return [
            // 'existente' = matched an existing product; 'novo' = no match (imports with product_id null).
            'status' => $product ? 'existente' : 'novo',
            'product_id' => $product?->id,
            'product_name' => $product?->name,
            'match_source' => $matchSource,
            'part_no' => $partNo !== '' ? $partNo : null,
            'description' => (string) ($item['description'] ?? ''),
            'description_inferred' => (bool) ($item['descricao_inferida'] ?? false),
            'quantity' => (int) ($item['quantity'] ?? 0),
            'unit' => trim((string) ($item['unit'] ?? '')) ?: 'pcs',
            'target_price_minor' => ($targetPrice !== null && $targetPrice !== '') ? Money::toMinor($targetPrice) : null,
            'specifications' => $item['specifications'] ?? null,
            'notes' => $item['notes'] ?? null,
        ];
    }
}
