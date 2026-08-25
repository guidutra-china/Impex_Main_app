<?php

namespace App\Domain\Catalog\Services;

use App\Domain\AI\Import\Support\NameNormalizer;
use App\Domain\Catalog\DataTransferObjects\NamingPreference;
use App\Domain\Catalog\DataTransferObjects\ProductIdentity;
use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;

/**
 * Resolve como uma contraparte identifica um produto em um documento.
 *
 * Comportamento padrão (preferência COUNTERPARTY em todos os campos, o
 * histórico do sistema): mostre o que AQUELA contraparte chama de produto,
 * caia para o modelo do fabricante e só então para o nosso código interno.
 *
 *     código:    pivot.external_code > product.model_number > product.sku
 *     nome:      pivot.external_name > nome da linha > product.name
 *     descrição: descrição digitada na linha > pivot.external_description
 *                > descrição da linha (auto-preenchida)
 *
 * Essas cadeias valem para os campos cuja NamingPreference está em
 * COUNTERPARTY. Código, nome e descrição são redirecionáveis
 * independentemente — um documento pode pedir nome do cadastro interno
 * (SYSTEM) mantendo código e descrição do pivot, por exemplo. Veja
 * resolve() e resolveDescription() para o que SYSTEM faz em cada campo, e
 * a classe NamingPreference para a origem da preferência.
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

    /**
     * Placeholder quando não há nome nenhum para mostrar — nem da contraparte,
     * nem da linha, nem do cadastro do produto.
     */
    private const NO_NAME_PLACEHOLDER = '—';

    /** @var array<int, CompanyProduct|null> */
    private array $pivotCache = [];

    private function __construct(
        private readonly ?int $companyId,
        private readonly string $role,
        /** Usado quando o documento é endereçado a uma filial: tenta a filial, depois a matriz. */
        private readonly ?int $fallbackCompanyId,
        /** As factories resolvem null para NamingPreference::default() — aqui já chega sempre explícito. */
        private readonly NamingPreference $naming,
    ) {}

    /**
     * Ids sem preferência — comportamento histórico (COUNTERPARTY em tudo).
     * Serve quem nunca precisou de NamingPreference: PackingListBuilder, as
     * relation managers do Filament, GenerateProductionScheduleTemplate,
     * PaymentStatementPdfTemplate. Quem tem as empresas carregadas e quer
     * honrar a preferência cadastrada usa forClientCompany()/forSupplierCompany().
     */
    public static function forClient(?int $companyId, ?int $fallbackCompanyId = null): self
    {
        return new self($companyId, self::ROLE_CLIENT, $fallbackCompanyId, NamingPreference::default());
    }

    public static function forSupplier(?int $companyId): self
    {
        return new self($companyId, self::ROLE_SUPPLIER, null, NamingPreference::default());
    }

    /**
     * Fábrica para documentos: recebe as empresas, não ids, e deriva de uma vez
     * o pivot (filial > matriz) e a preferência de nomenclatura, que seguem a
     * mesma regra de precedência. Derivar as duas em chamadas separadas deixava
     * passar em silêncio o caso de uma receber a matriz e a outra não.
     *
     * Não viola o contrato de nunca consultar o banco: os models chegam prontos
     * e nenhuma relação é percorrida aqui.
     *
     * @param  array<string, mixed>  $overrides  dados do formulário de geração
     */
    public static function forClientCompany(?Company $company, ?Company $parent = null, array $overrides = []): self
    {
        return new self(
            $company?->id,
            self::ROLE_CLIENT,
            $parent?->id,
            NamingPreference::fromCompany($company, $parent)->withOverrides($overrides),
        );
    }

    public static function forSupplierCompany(?Company $company, array $overrides = []): self
    {
        return new self(
            $company?->id,
            self::ROLE_SUPPLIER,
            null,
            NamingPreference::fromCompany($company)->withOverrides($overrides),
        );
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
                name: filled($lineName) ? $lineName : self::NO_NAME_PLACEHOLDER,
                description: filled($lineDescription) ? $lineDescription : null,
            );
        }

        $pivot = $this->pivot($product);

        $counterpartyCode = $this->naming->code->isCounterparty()
            ? $pivot?->external_code
            : null;

        $code = (string) ($counterpartyCode ?: ($product->model_number ?: ($product->sku ?: '')));

        // SYSTEM ignora tanto o pivot quanto o snapshot da linha — só o
        // cadastro do produto. Por isso o lineName só entra na chain quando a
        // preferência já é COUNTERPARTY, dentro deste mesmo ramo.
        $counterpartyName = $this->naming->name->isCounterparty()
            ? ($pivot?->external_name ?: (filled($lineName) ? $lineName : null))
            : null;

        $name = (string) ($counterpartyName ?: ($product->name ?: self::NO_NAME_PLACEHOLDER));

        return new ProductIdentity(
            code: $code,
            name: $name,
            description: $this->resolveDescription($product, $pivot, $lineDescription),
            // NCM é classificação do importador: não existe do lado do fornecedor
            // e não cai para products.hs_code (HS de 6 dígitos não é NCM).
            ncm: $this->isClientSide() ? ($pivot?->external_ncm ?: null) : null,
            fromCounterparty: filled($counterpartyCode),
        );
    }

    /**
     * Descrição digitada de verdade na linha vence as duas fontes possíveis
     * (pivot da contraparte ou cadastro do produto); a fonte é o padrão
     * quando a linha só tem o texto auto-preenchido.
     *
     * "Auto-preenchida" = igual ao nome do produto após normalização — é a
     * assinatura de fillFromProduct(), que copia product.name para a descrição
     * ao escolher o produto. Sem esse teste, "linha vence" anularia a descrição
     * cadastrada em praticamente todas as linhas do sistema.
     *
     * Limitação aceita: se o produto for renomeado depois, a descrição antiga
     * deixa de casar com o nome e passa a ser tratada como digitada — o texto
     * do snapshot é preservado, que é o comportamento conservador correto.
     *
     * A preferência entra em dois pontos. Primeiro, showDescription zera tudo
     * — inclusive o texto digitado, porque é um controle de exibição, não de
     * fonte. Depois, texto digitado ainda vence as duas fontes, porque é um
     * humano escrevendo para aquele documento específico; a preferência só
     * escolhe o que usar quando não há texto digitado.
     *
     * As duas fontes NÃO são simétricas nesse ponto, e é deliberado:
     *   - COUNTERPARTY sem external_description cai para o texto
     *     auto-preenchido da linha (histórico: a linha nunca fica vazia só
     *     porque o pivot não tem descrição própria).
     *   - SYSTEM sem product.description NÃO cai para a linha — devolve null.
     *     Cair para a linha duplicaria o nome do produto na coluna de
     *     descrição (o auto-preenchido É o nome do produto) e, pior, quando
     *     o usuário pediu SYSTEM explicitamente, herdar o texto que só existe
     *     porque outra fonte o auto-preencheu anularia a escolha dele.
     */
    private function resolveDescription(Product $product, ?CompanyProduct $pivot, ?string $lineDescription): ?string
    {
        if (! $this->naming->showDescription) {
            return null;
        }

        if ($this->isDeliberate($lineDescription, $product)) {
            return $lineDescription;
        }

        if (! $this->naming->description->isCounterparty()) {
            return filled($product->description) ? (string) $product->description : null;
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
