<?php

namespace App\Domain\Catalog\Services;

use App\Domain\AI\Import\Support\NameNormalizer;
use App\Domain\Catalog\DataTransferObjects\ProductIdentity;
use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;

/**
 * Resolve como uma contraparte identifica um produto em um documento.
 *
 * Regra única do sistema: mostre o que AQUELA contraparte chama de produto,
 * caia para o modelo do fabricante e só então para o nosso código interno.
 *
 *     código:    pivot.external_code > product.model_number > product.sku
 *     nome:      pivot.external_name > nome da linha > product.name
 *     descrição: descrição digitada na linha > pivot.external_description
 *                > descrição da linha (auto-preenchida)
 *
 * O pivot é sempre filtrado por company_id E role — sem o filtro de papel, uma
 * empresa que é cliente e fornecedora do mesmo produto vaza o código de um lado
 * para o documento do outro.
 *
 * Uma instância por documento: o cache de pivots vive enquanto o documento é
 * montado, exatamente como os caches privados que CI e Packing List mantinham
 * cada um por conta própria.
 *
 * Contrato de eager-load: este serviço NUNCA consulta o banco. O chamador deve
 * carregar a relação `companies` dos produtos (escopada ou não — o resolver
 * refiltra). Produto sem a relação carregada devolve identidade sem pivot e
 * reporta fora de produção, para a falta de eager-load falhar alto em dev em
 * vez de virar N+1 silencioso em produção.
 */
class ProductIdentityResolver
{
    public const ROLE_CLIENT = 'client';

    public const ROLE_SUPPLIER = 'supplier';

    /** @var array<int, CompanyProduct|null> */
    private array $pivotCache = [];

    private function __construct(
        private readonly ?int $companyId,
        private readonly string $role,
        /** Usado quando o documento é endereçado a uma filial: tenta a filial, depois a matriz. */
        private readonly ?int $fallbackCompanyId = null,
    ) {}

    public static function forClient(?int $companyId, ?int $fallbackCompanyId = null): self
    {
        return new self($companyId, self::ROLE_CLIENT, $fallbackCompanyId);
    }

    public static function forSupplier(?int $companyId): self
    {
        return new self($companyId, self::ROLE_SUPPLIER);
    }

    public function isClientSide(): bool
    {
        return $this->role === self::ROLE_CLIENT;
    }

    /**
     * Pré-carrega o cache. Opcional — resolve()/pivot() preenchem sob demanda —
     * mas deixa explícito no template quais produtos participam do documento.
     *
     * @param  iterable<Product|null>  $products
     */
    public function warm(iterable $products): void
    {
        foreach ($products as $product) {
            if ($product instanceof Product) {
                $this->pivot($product);
            }
        }
    }

    public function pivot(?Product $product): ?CompanyProduct
    {
        if (! $product || ! $this->companyId) {
            return null;
        }

        if (array_key_exists($product->getKey(), $this->pivotCache)) {
            return $this->pivotCache[$product->getKey()];
        }

        $loaded = $this->loadedCompanies($product);

        if ($loaded === null) {
            if (! app()->isProduction()) {
                report(new \RuntimeException(
                    'ProductIdentityResolver: relação companies não carregada no produto '
                    .$product->getKey().'. Adicione o eager-load para evitar N+1.'
                ));
            }

            return $this->pivotCache[$product->getKey()] = null;
        }

        return $this->pivotCache[$product->getKey()] = $this->findPivot($loaded);
    }

    /**
     * @param  ?string  $lineName  nome gravado na linha do documento (snapshot)
     * @param  ?string  $lineDescription  descrição gravada na linha do documento
     */
    public function resolve(?Product $product, ?string $lineName = null, ?string $lineDescription = null): ProductIdentity
    {
        if (! $product) {
            return new ProductIdentity(
                code: '',
                name: filled($lineName) ? $lineName : '—',
                description: filled($lineDescription) ? $lineDescription : null,
            );
        }

        $pivot = $this->pivot($product);

        $code = (string) ($pivot?->external_code ?: ($product->model_number ?: ($product->sku ?: '')));

        $name = (string) ($pivot?->external_name
            ?: (filled($lineName) ? $lineName : ($product->name ?: '—')));

        return new ProductIdentity(
            code: $code,
            name: $name,
            description: $this->resolveDescription($product, $pivot, $lineDescription),
            // NCM é classificação do importador: não existe do lado do fornecedor
            // e não cai para products.hs_code (HS de 6 dígitos não é NCM).
            ncm: $this->isClientSide() ? ($pivot?->external_ncm ?: null) : null,
            fromCounterparty: filled($pivot?->external_code),
        );
    }

    /**
     * Descrição digitada de verdade na linha vence a descrição cadastrada da
     * contraparte; a cadastrada é o padrão quando a linha só tem o texto
     * auto-preenchido.
     *
     * "Auto-preenchida" = igual ao nome do produto após normalização — é a
     * assinatura de fillFromProduct(), que copia product.name para a descrição
     * ao escolher o produto. Sem esse teste, "linha vence" anularia a descrição
     * do cliente em praticamente todas as linhas do sistema.
     *
     * Limitação aceita: se o produto for renomeado depois, a descrição antiga
     * deixa de casar com o nome e passa a ser tratada como digitada — o texto
     * do snapshot é preservado, que é o comportamento conservador correto.
     */
    private function resolveDescription(Product $product, ?CompanyProduct $pivot, ?string $lineDescription): ?string
    {
        if ($this->isDeliberate($lineDescription, $product)) {
            return $lineDescription;
        }

        if (filled($pivot?->external_description)) {
            return $pivot->external_description;
        }

        return filled($lineDescription) ? $lineDescription : null;
    }

    private function isDeliberate(?string $lineDescription, Product $product): bool
    {
        if (blank($lineDescription)) {
            return false;
        }

        return NameNormalizer::normalize($lineDescription) !== NameNormalizer::normalize($product->name);
    }

    /**
     * A coleção de empresas já carregada, aceitando tanto `companies` quanto o
     * alias por papel (`clients` / `suppliers`) que vários chamadores usam.
     * Null significa "nada carregado" — o chamador esqueceu o eager-load.
     *
     * @return \Illuminate\Support\Collection<int, \App\Domain\CRM\Models\Company>|null
     */
    private function loadedCompanies(Product $product): ?\Illuminate\Support\Collection
    {
        $aliasByRole = [
            self::ROLE_CLIENT => 'clients',
            self::ROLE_SUPPLIER => 'suppliers',
        ];

        foreach (['companies', $aliasByRole[$this->role]] as $relation) {
            if ($product->relationLoaded($relation)) {
                return $product->getRelation($relation);
            }
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Domain\CRM\Models\Company>  $companies
     */
    private function findPivot(\Illuminate\Support\Collection $companies): ?CompanyProduct
    {
        foreach (array_filter([$this->companyId, $this->fallbackCompanyId]) as $companyId) {
            $match = $companies
                ->first(fn ($company) => (int) $company->pivot->company_id === (int) $companyId
                    && $company->pivot->role === $this->role);

            if ($match) {
                return $match->pivot;
            }
        }

        return null;
    }
}
