# Nomenclatura da contraparte nos documentos — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir escolher, por empresa e por geração de documento, se código, nome e descrição saem da nomenclatura da contraparte ou do cadastro do sistema — e exibir o NCM com 4 dígitos na Commercial Invoice.

**Architecture:** Um value object `NamingPreference` carrega as quatro decisões e é passado às fábricas do `ProductIdentityResolver`, que passa a consultar o pivot campo a campo. Os templates continuam chamando `resolve()` e não ganham lógica de decisão. O padrão vem de quatro colunas em `companies`; o modal sobrepõe.

**Tech Stack:** Laravel 11, Filament 4, PHPUnit, MySQL.

**Spec:** `docs/superpowers/specs/2026-08-24-nomenclatura-documentos-design.md`

---

## Revisão de código: o que mudou nas Tasks 1–3

As Tasks 1–3 foram implementadas e revisadas. A revisão de qualidade mudou decisões que
as tarefas seguintes precisam respeitar. **O código implementado é a fonte da verdade;
os blocos das Tasks 1–3 abaixo ficaram desatualizados de propósito, como registro.**

| Mudou | De | Para |
|---|---|---|
| Cases do enum | `Counterparty` / `System` | `COUNTERPARTY` / `SYSTEM` (convenção do Catalog) |
| Enum | sem contrato | `implements HasLabel` + `enums.document_naming_source.*` nos 3 idiomas |
| Colunas | `enum()` NOT NULL com default | `string(20)` NULLABLE, sem default |
| Semântica do NULL | não existia | **NULL = herda da matriz, senão o padrão histórico** |
| `fromCompany()` | `(?Company)` | `(?Company $company, ?Company $fallback = null)`, campo a campo |
| Chave do toggle | `show_description` | `naming_show_description` |
| Chaves do modal | strings soltas | constantes `NamingPreference::KEY_*` |
| Enum inválido | `from()` lança ValueError | `tryFrom() ?? $current`, degrada em vez de dar 500 |

Consequência para quem for implementar as tarefas seguintes: nos formulários, use as
constantes e `->options(DocumentNamingSource::class)` em vez de arrays de opções
escritos à mão, e lembre que a filial herda da matriz — os templates precisam passar
as duas empresas para `fromCompany()`.

**Rejeitado da revisão:** a sugestão de o `ProductIdentityResolver` resolver as empresas
internamente. O docblock dele diz que NUNCA consulta o banco, e é por isso que recebe ids
e exige eager-load. A preferência fica fora do resolver e é passada pronta.

---

## Contexto que o plano assume

`ProductIdentityResolver` (`app/Domain/Catalog/Services/ProductIdentityResolver.php`) é a regra
única de identidade. Hoje o pivot da contraparte sempre vence. Defaults novos preservam isso.

`GeneratePdfAction::createTemplate()` (`app/Filament/Actions/GeneratePdfAction.php:111`) reflete o
construtor do template: um parâmetro `array $options` recebe **todo** o `$data` do formulário;
os demais são mapeados por nome em snake_case. É assim que o modal chega no template.

`commercialInvoiceOptions()` (`app/Filament/Resources/Shipments/Concerns/ShipmentHeaderActions.php:182`)
é compartilhado por quatro ações que constroem um template: CI PDF, preview, Excel e PI do
embarque. A ação de e-mail (`SendDocumentByEmailAction`) fica de fora — ela anexa um documento
já gerado e nunca instancia um template, então não tem controles de nomenclatura próprios.

Rodar testes: `php artisan test --filter=NomeDoTeste`

---

## Estrutura de arquivos

**Herdam de graça, não precisam ser tocados:**
- `CommercialInvoiceExcelExporter` — chama `(new CommercialInvoicePdfTemplate(...))->getData()`
- `ShipmentProformaInvoicePdfTemplate` — `extends CommercialInvoicePdfTemplate`

**Criar:**
- `app/Domain/Catalog/Enums/DocumentNamingSource.php` — enum `counterparty|system`
- `app/Domain/Catalog/DataTransferObjects/NamingPreference.php` — as quatro decisões
- `database/migrations/XXXX_add_document_naming_to_companies_table.php`
- `tests/Unit/Catalog/NamingPreferenceTest.php`
- `tests/Unit/Catalog/ProductIdentityNamingPreferenceTest.php`

**Modificar:**
- `app/Domain/Catalog/Services/ProductIdentityResolver.php` — fábricas e `resolve()`
- `app/Domain/CRM/Models/Company.php` — `$fillable` e `casts()`
- `app/Domain/Infrastructure/Pdf/Templates/CommercialInvoicePdfTemplate.php`
- `app/Domain/Infrastructure/Pdf/Templates/PackingListPdfTemplate.php`
- `app/Domain/Infrastructure/Pdf/Templates/ProformaInvoicePdfTemplate.php`
- `app/Domain/Infrastructure/Pdf/Templates/PurchaseOrderPdfTemplate.php`
- `app/Domain/Infrastructure/Pdf/Templates/RfqPdfTemplate.php`
- `app/Domain/Infrastructure/Excel/Templates/RfqExcelTemplate.php`
- `app/Filament/Resources/Shipments/Concerns/ShipmentHeaderActions.php`
- `app/Filament/Resources/PurchaseOrders/Concerns/PurchaseOrderHeaderActions.php`
- `app/Filament/Resources/SupplierQuotations/Concerns/SupplierQuotationHeaderActions.php`
- `app/Filament/Resources/CRM/Companies/Schemas/CompanyForm.php`

---

### Task 1: Enum `DocumentNamingSource`

**Files:**
- Create: `app/Domain/Catalog/Enums/DocumentNamingSource.php`

- [ ] **Step 1: Criar o enum**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * De onde um documento tira código, nome e descrição do produto.
 *
 * COUNTERPARTY é o comportamento histórico: o pivot da contraparte vence.
 * SYSTEM ignora o pivot e usa o cadastro interno do produto.
 */
enum DocumentNamingSource: string
{
    case Counterparty = 'counterparty';
    case System = 'system';

    public function isCounterparty(): bool
    {
        return $this === self::Counterparty;
    }
}
```

- [ ] **Step 2: Confirmar que carrega**

Run: `php artisan tinker --execute='echo App\Domain\Catalog\Enums\DocumentNamingSource::Counterparty->value;'`
Expected: `counterparty`

- [ ] **Step 3: Commit**

```bash
git add app/Domain/Catalog/Enums/DocumentNamingSource.php
git commit -m "feat(catalog): enum de fonte de nomenclatura dos documentos"
```

---

### Task 2: Value object `NamingPreference`

**Files:**
- Create: `app/Domain/Catalog/DataTransferObjects/NamingPreference.php`
- Test: `tests/Unit/Catalog/NamingPreferenceTest.php`

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\DataTransferObjects\NamingPreference;
use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NamingPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_preserva_o_comportamento_historico(): void
    {
        $preference = NamingPreference::default();

        $this->assertSame(DocumentNamingSource::Counterparty, $preference->code);
        $this->assertSame(DocumentNamingSource::Counterparty, $preference->name);
        $this->assertSame(DocumentNamingSource::Counterparty, $preference->description);
        $this->assertTrue($preference->showDescription);
    }

    public function test_le_os_padroes_da_empresa(): void
    {
        $company = Company::factory()->create([
            'document_name_source' => DocumentNamingSource::System,
            'document_show_description' => false,
        ]);

        $preference = NamingPreference::fromCompany($company);

        $this->assertSame(DocumentNamingSource::System, $preference->name);
        $this->assertSame(DocumentNamingSource::Counterparty, $preference->code);
        $this->assertFalse($preference->showDescription);
    }

    public function test_empresa_nula_devolve_o_default(): void
    {
        $this->assertEquals(NamingPreference::default(), NamingPreference::fromCompany(null));
    }

    public function test_overrides_do_modal_vencem_a_empresa(): void
    {
        $company = Company::factory()->create([
            'document_name_source' => DocumentNamingSource::System,
        ]);

        $preference = NamingPreference::fromCompany($company)->withOverrides([
            'naming_name_source' => 'counterparty',
            'show_description' => false,
        ]);

        $this->assertSame(DocumentNamingSource::Counterparty, $preference->name);
        $this->assertFalse($preference->showDescription);
    }

    public function test_override_ausente_nao_altera_o_valor(): void
    {
        $preference = NamingPreference::default()->withOverrides(['irrelevante' => true]);

        $this->assertEquals(NamingPreference::default(), $preference);
    }
}
```

- [ ] **Step 2: Rodar para confirmar que falha**

Run: `php artisan test --filter=NamingPreferenceTest`
Expected: FAIL — `Class "App\Domain\Catalog\DataTransferObjects\NamingPreference" not found`

- [ ] **Step 3: Implementar o value object**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DataTransferObjects;

use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\CRM\Models\Company;

/**
 * Como um documento deve nomear os produtos da contraparte.
 *
 * Value object em vez de quatro parâmetros soltos porque atravessa três camadas
 * (ação Filament -> template -> resolver) e engordaria todas as assinaturas.
 *
 * `default()` é o comportamento histórico do sistema: tudo da contraparte, com
 * descrição visível. Nenhum documento existente muda enquanto ninguém trocar um
 * valor no cadastro da empresa ou no modal.
 */
class NamingPreference
{
    public function __construct(
        public readonly DocumentNamingSource $code = DocumentNamingSource::Counterparty,
        public readonly DocumentNamingSource $name = DocumentNamingSource::Counterparty,
        public readonly DocumentNamingSource $description = DocumentNamingSource::Counterparty,
        public readonly bool $showDescription = true,
    ) {}

    public static function default(): self
    {
        return new self;
    }

    public static function fromCompany(?Company $company): self
    {
        if (! $company) {
            return self::default();
        }

        return new self(
            code: $company->document_code_source ?? DocumentNamingSource::Counterparty,
            name: $company->document_name_source ?? DocumentNamingSource::Counterparty,
            description: $company->document_description_source ?? DocumentNamingSource::Counterparty,
            showDescription: $company->document_show_description ?? true,
        );
    }

    /**
     * Aplica o que veio do modal. Chave ausente mantém o valor da empresa — o
     * formulário só sobrepõe o que ele realmente controla.
     *
     * @param  array<string, mixed>  $options
     */
    public function withOverrides(array $options): self
    {
        return new self(
            code: $this->source($options, 'naming_code_source', $this->code),
            name: $this->source($options, 'naming_name_source', $this->name),
            description: $this->source($options, 'naming_description_source', $this->description),
            showDescription: array_key_exists('show_description', $options)
                ? (bool) $options['show_description']
                : $this->showDescription,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function source(array $options, string $key, DocumentNamingSource $current): DocumentNamingSource
    {
        if (! array_key_exists($key, $options) || blank($options[$key])) {
            return $current;
        }

        return $options[$key] instanceof DocumentNamingSource
            ? $options[$key]
            : DocumentNamingSource::from((string) $options[$key]);
    }
}
```

- [ ] **Step 4: Rodar — ainda falha por causa das colunas**

Run: `php artisan test --filter=NamingPreferenceTest`
Expected: FAIL — coluna `document_name_source` não existe. A Task 3 resolve; os testes de `default()` já passam.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Catalog/DataTransferObjects/NamingPreference.php tests/Unit/Catalog/NamingPreferenceTest.php
git commit -m "feat(catalog): NamingPreference com o default histórico"
```

---

### Task 3: Colunas em `companies`

**Files:**
- Create: `database/migrations/XXXX_add_document_naming_to_companies_table.php`
- Modify: `app/Domain/CRM/Models/Company.php`

- [ ] **Step 1: Criar a migration**

```bash
php artisan make:migration add_document_naming_to_companies_table
```

- [ ] **Step 2: Escrever a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Padrão de nomenclatura da contraparte nos documentos.
 *
 * Defaults reproduzem o comportamento histórico (tudo da contraparte, descrição
 * visível): nenhum documento existente muda com esta migration.
 *
 * Um conjunto de colunas serve cliente e fornecedor — em 2026-08-24 não havia
 * nenhuma empresa com os dois papéis. Se aparecer uma que precise de tratamento
 * diferente por papel, separar é migration aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('document_code_source', ['counterparty', 'system'])
                ->default('counterparty')
                ->after('preferred_language');
            $table->enum('document_name_source', ['counterparty', 'system'])
                ->default('counterparty')
                ->after('document_code_source');
            $table->enum('document_description_source', ['counterparty', 'system'])
                ->default('counterparty')
                ->after('document_name_source');
            $table->boolean('document_show_description')
                ->default(true)
                ->after('document_description_source');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'document_code_source',
                'document_name_source',
                'document_description_source',
                'document_show_description',
            ]);
        });
    }
};
```

- [ ] **Step 3: Adicionar ao `$fillable` do Company**

Em `app/Domain/CRM/Models/Company.php`, após `'preferred_language',` na lista `$fillable`:

```php
        'preferred_language',
        'document_code_source',
        'document_name_source',
        'document_description_source',
        'document_show_description',
    ];
```

- [ ] **Step 4: Adicionar os casts**

Substituir o corpo de `casts()` em `app/Domain/CRM/Models/Company.php`:

```php
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'document_code_source' => DocumentNamingSource::class,
            'document_name_source' => DocumentNamingSource::class,
            'document_description_source' => DocumentNamingSource::class,
            'document_show_description' => 'boolean',
        ];
    }
```

E o import no topo do arquivo:

```php
use App\Domain\Catalog\Enums\DocumentNamingSource;
```

- [ ] **Step 5: Migrar e rodar o teste da Task 2**

Run: `php artisan migrate && php artisan test --filter=NamingPreferenceTest`
Expected: PASS — 5 testes

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Domain/CRM/Models/Company.php
git commit -m "feat(crm): padrão de nomenclatura de documentos por empresa"
```

---

### Task 4: Resolver aceita a preferência (código e nome)

**Files:**
- Modify: `app/Domain/Catalog/Services/ProductIdentityResolver.php`
- Test: `tests/Unit/Catalog/ProductIdentityNamingPreferenceTest.php`

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\DataTransferObjects\NamingPreference;
use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductIdentityResolver;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A preferência escolhe, campo a campo, se o pivot da contraparte é consultado.
 * O default continua sendo "o pivot vence", que é o comportamento histórico.
 */
class ProductIdentityNamingPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Company::factory()->create();
    }

    private function linkedProduct(array $pivot = [], array $attributes = []): Product
    {
        $product = Product::factory()->create(array_merge([
            'name' => 'Olympic bearing bar',
            'description' => 'Barra em aço para treinos livres',
            'model_number' => 'MOD-1',
            'sku' => 'SKU-'.uniqid(),
        ], $attributes));

        $product->companies()->attach($this->client->id, array_merge([
            'role' => 'client',
            'external_code' => 'DPF-OBB',
            'external_name' => 'Barra Olímpica com Rolamentos',
            'external_description' => 'Olympic bearing bar, 1.5 m',
            'external_ncm' => '9506.91.00',
        ], $pivot));
        $product->load('companies');

        return $product;
    }

    public function test_sem_preferencia_o_pivot_vence_como_sempre(): void
    {
        $identity = ProductIdentityResolver::forClient($this->client->id)
            ->resolve($this->linkedProduct());

        $this->assertSame('DPF-OBB', $identity->code);
        $this->assertSame('Barra Olímpica com Rolamentos', $identity->name);
    }

    public function test_nome_do_sistema_mantendo_o_codigo_do_cliente(): void
    {
        $preference = new NamingPreference(name: DocumentNamingSource::System);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct());

        $this->assertSame('DPF-OBB', $identity->code);
        $this->assertSame('Olympic bearing bar', $identity->name);
    }

    public function test_codigo_do_sistema_cai_para_model_number(): void
    {
        $preference = new NamingPreference(code: DocumentNamingSource::System);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct());

        $this->assertSame('MOD-1', $identity->code);
        $this->assertFalse($identity->fromCounterparty);
    }

    public function test_codigo_do_sistema_sem_model_number_cai_para_sku(): void
    {
        $preference = new NamingPreference(code: DocumentNamingSource::System);
        $product = $this->linkedProduct([], ['model_number' => null, 'sku' => 'SKU-XYZ']);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($product);

        $this->assertSame('SKU-XYZ', $identity->code);
    }

    public function test_nome_do_sistema_ignora_o_snapshot_da_linha(): void
    {
        $preference = new NamingPreference(name: DocumentNamingSource::System);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct(), lineName: 'Nome da linha');

        $this->assertSame('Olympic bearing bar', $identity->name);
    }

    public function test_ncm_nao_e_afetado_por_nenhum_toggle(): void
    {
        $preference = new NamingPreference(
            code: DocumentNamingSource::System,
            name: DocumentNamingSource::System,
            description: DocumentNamingSource::System,
            showDescription: false,
        );

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct());

        $this->assertSame('9506.91.00', $identity->ncm);
    }
}
```

- [ ] **Step 2: Rodar para confirmar que falha**

Run: `php artisan test --filter=ProductIdentityNamingPreferenceTest`
Expected: FAIL — `forClient()` aceita no máximo 2 argumentos

- [ ] **Step 3: Aceitar a preferência nas fábricas**

Em `app/Domain/Catalog/Services/ProductIdentityResolver.php`, adicionar o import:

```php
use App\Domain\Catalog\DataTransferObjects\NamingPreference;
```

Substituir o construtor e as duas fábricas:

```php
    private function __construct(
        private readonly ?int $companyId,
        private readonly string $role,
        /** Usado quando o documento é endereçado a uma filial: tenta a filial, depois a matriz. */
        private readonly ?int $fallbackCompanyId = null,
        /** Null significa o comportamento histórico: o pivot da contraparte vence. */
        private readonly NamingPreference $naming = new NamingPreference,
    ) {}

    public static function forClient(
        ?int $companyId,
        ?int $fallbackCompanyId = null,
        ?NamingPreference $naming = null,
    ): self {
        return new self($companyId, self::ROLE_CLIENT, $fallbackCompanyId, $naming ?? NamingPreference::default());
    }

    public static function forSupplier(?int $companyId, ?NamingPreference $naming = null): self
    {
        return new self($companyId, self::ROLE_SUPPLIER, null, $naming ?? NamingPreference::default());
    }
```

- [ ] **Step 4: Aplicar a preferência a código e nome**

Substituir, dentro de `resolve()`, as linhas que montam `$code` e `$name`:

```php
        $pivot = $this->pivot($product);

        $counterpartyCode = $this->naming->code->isCounterparty()
            ? $pivot?->external_code
            : null;

        $code = (string) ($counterpartyCode ?: ($product->model_number ?: ($product->sku ?: '')));

        $counterpartyName = $this->naming->name->isCounterparty()
            ? $pivot?->external_name
            : null;

        $name = $this->naming->name->isCounterparty()
            ? (string) ($counterpartyName ?: (filled($lineName) ? $lineName : ($product->name ?: '—')))
            : (string) ($product->name ?: '—');

        return new ProductIdentity(
            code: $code,
            name: $name,
            description: $this->resolveDescription($product, $pivot, $lineDescription),
            // NCM é classificação do importador: não existe do lado do fornecedor
            // e não cai para products.hs_code (HS de 6 dígitos não é NCM).
            ncm: $this->isClientSide() ? ($pivot?->external_ncm ?: null) : null,
            fromCounterparty: filled($counterpartyCode),
        );
```

- [ ] **Step 5: Rodar os testes**

Run: `php artisan test --filter=ProductIdentityNamingPreferenceTest`
Expected: PASS — 6 testes

- [ ] **Step 6: Rodar a regressão do resolver**

Run: `php artisan test --filter="ProductIdentityResolverTest|ProductIdentityDescriptionTest|DocumentIdentityCharacterizationTest|ProductIdentityAcrossDocumentsTest|SupplierFacingDocumentIdentityTest"`
Expected: PASS — nenhum teste existente pode quebrar; o default é o comportamento antigo

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Catalog/Services/ProductIdentityResolver.php tests/Unit/Catalog/ProductIdentityNamingPreferenceTest.php
git commit -m "feat(catalog): resolver escolhe a fonte de código e nome"
```

---

### Task 5: Descrição — fonte e exibição

**Files:**
- Modify: `app/Domain/Catalog/Services/ProductIdentityResolver.php`
- Test: `tests/Unit/Catalog/ProductIdentityNamingPreferenceTest.php`

- [ ] **Step 1: Acrescentar os testes ao arquivo da Task 4**

```php
    public function test_descricao_do_cliente_e_o_padrao(): void
    {
        $identity = ProductIdentityResolver::forClient($this->client->id)
            ->resolve($this->linkedProduct());

        $this->assertSame('Olympic bearing bar, 1.5 m', $identity->description);
    }

    public function test_descricao_do_sistema_usa_o_cadastro_do_produto(): void
    {
        $preference = new NamingPreference(description: DocumentNamingSource::System);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct());

        $this->assertSame('Barra em aço para treinos livres', $identity->description);
    }

    public function test_texto_digitado_na_linha_vence_as_duas_fontes(): void
    {
        $preference = new NamingPreference(description: DocumentNamingSource::System);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct(), lineDescription: 'Special packing, 2 pcs/ctn');

        $this->assertSame('Special packing, 2 pcs/ctn', $identity->description);
    }

    public function test_ocultar_descricao_zera_inclusive_o_texto_digitado(): void
    {
        $preference = new NamingPreference(showDescription: false);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct(), lineDescription: 'Special packing, 2 pcs/ctn');

        $this->assertNull($identity->description);
    }

    public function test_descricao_do_sistema_vazia_nao_cai_para_o_cliente(): void
    {
        $preference = new NamingPreference(description: DocumentNamingSource::System);
        $product = $this->linkedProduct([], ['description' => null]);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($product);

        $this->assertNull($identity->description);
    }
```

- [ ] **Step 2: Rodar para confirmar que falha**

Run: `php artisan test --filter=ProductIdentityNamingPreferenceTest`
Expected: FAIL — `test_descricao_do_sistema_usa_o_cadastro_do_produto` devolve a descrição do pivot

- [ ] **Step 3: Reescrever `resolveDescription()`**

Substituir o método inteiro em `app/Domain/Catalog/Services/ProductIdentityResolver.php`:

```php
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
     * A preferência escolhe apenas o fallback: texto digitado à mão vence
     * qualquer fonte, porque alguém o escreveu para aquele documento.
     * showDescription = false zera tudo, inclusive o digitado.
     *
     * Limitação aceita: se o produto for renomeado depois, a descrição antiga
     * deixa de casar com o nome e passa a ser tratada como digitada — o texto
     * do snapshot é preservado, que é o comportamento conservador correto.
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
```

- [ ] **Step 4: Rodar os testes**

Run: `php artisan test --filter=ProductIdentityNamingPreferenceTest`
Expected: PASS — 11 testes

- [ ] **Step 5: Rodar a regressão do resolver**

Run: `php artisan test --filter="ProductIdentityResolverTest|ProductIdentityDescriptionTest|DocumentIdentityCharacterizationTest|ProductIdentityAcrossDocumentsTest|SupplierFacingDocumentIdentityTest"`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Catalog/Services/ProductIdentityResolver.php tests/Unit/Catalog/ProductIdentityNamingPreferenceTest.php
git commit -m "feat(catalog): fonte e exibição da descrição no resolver"
```

---

### Fábricas do resolver — use estas nas tarefas de fiação

A revisão das Tasks 4–5 substituiu a assinatura que os blocos abaixo usam. **Não use
`forClient($id, $fallback, $naming)`** — essa forma não existe mais, e a variante de
fornecedor colocava `$naming` em outra posição, o que o PHP descartava em silêncio.

```php
// documentos de cliente (CI, Packing List, Proforma, PI do embarque)
$resolver = ProductIdentityResolver::forClientCompany(
    $shipment->getDocumentClient(),   // filial ou matriz, o model
    $shipment->company,               // a matriz, para herança
    $this->options,                   // dados do modal
);

// documentos de fornecedor (PO, RFQ)
$resolver = ProductIdentityResolver::forSupplierCompany($po->supplierCompany, $this->options);
```

Uma chamada deriva o pivot **e** a preferência, com a mesma regra de precedência
filial > matriz. Derivar as duas separadamente deixava passar em silêncio o caso de
uma receber a matriz e a outra não.

`forClient(?int, ?int)` e `forSupplier(?int)` continuam existindo, sem preferência,
para os chamadores que não geram documento — `PackingListBuilder`, os relation managers,
`GenerateProductionScheduleTemplate` e `PaymentStatementPdfTemplate`. **Não mexa neles.**

Os templates precisam ter a empresa carregada. Onde hoje só existe `company_id`
(Proforma, PO, RFQ), acrescente o eager-load em vez de deixar o Eloquent buscar sozinho.

---

### Task 6: NCM com 4 dígitos na Commercial Invoice

**Files:**
- Modify: `app/Domain/Infrastructure/Pdf/Templates/CommercialInvoicePdfTemplate.php`
- Test: `tests/Feature/Catalog/CommercialInvoiceNcmTest.php`

O `show_ncm` já existe e liga sozinho quando algum item tem NCM. A única mudança é
imprimir a posição de 4 dígitos a partir dos 8 guardados.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Tests\Feature\Catalog;

use App\Domain\Infrastructure\Pdf\Templates\CommercialInvoicePdfTemplate;
use Tests\TestCase;

class CommercialInvoiceNcmTest extends TestCase
{
    public function test_ncm_e_impresso_com_quatro_digitos(): void
    {
        $format = new \ReflectionMethod(CommercialInvoicePdfTemplate::class, 'formatNcm');
        $format->setAccessible(true);

        $this->assertSame('9506', $format->invoke(null, '9506.91.00'));
        $this->assertSame('9403', $format->invoke(null, '9403.20.00'));
        $this->assertSame('9506', $format->invoke(null, '9506'));
        $this->assertNull($format->invoke(null, null));
        $this->assertNull($format->invoke(null, ''));
        $this->assertNull($format->invoke(null, 'sem digitos'));
    }
}
```

- [ ] **Step 2: Rodar para confirmar que falha**

Run: `php artisan test --filter=CommercialInvoiceNcmTest`
Expected: FAIL — método `formatNcm` não existe

- [ ] **Step 3: Implementar `formatNcm()`**

Adicionar o método em `app/Domain/Infrastructure/Pdf/Templates/CommercialInvoicePdfTemplate.php`:

```php
    /**
     * O documento mostra a posição de 4 dígitos; o banco guarda os 8 que o
     * despachante enviou, que é o que a DI/DUIMP precisa. Formatação é do
     * documento, não do dado.
     */
    private static function formatNcm(?string $ncm): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $ncm);

        return strlen((string) $digits) >= 4 ? substr((string) $digits, 0, 4) : null;
    }
```

- [ ] **Step 4: Usar no mapeamento da linha**

Substituir a linha `'ncm' => $identity->ncm,` por:

```php
                    'ncm' => self::formatNcm($identity->ncm),
```

- [ ] **Step 5: Rodar os testes**

Run: `php artisan test --filter=CommercialInvoiceNcmTest`
Expected: PASS — 1 teste, 6 asserções

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Infrastructure/Pdf/Templates/CommercialInvoicePdfTemplate.php tests/Feature/Catalog/CommercialInvoiceNcmTest.php
git commit -m "feat(commercial-invoice): NCM impresso com 4 dígitos"
```

---

### Task 7: Ligar a preferência na Commercial Invoice

**Files:**
- Modify: `app/Domain/Infrastructure/Pdf/Templates/CommercialInvoicePdfTemplate.php:51-56`

- [ ] **Step 1: Ler a preferência da empresa e aplicar os overrides do modal**

Substituir o bloco que cria o resolver:

```php
        // Documento endereçado à filial resolve o pivot da filial primeiro e
        // cai para a matriz — o endereço já segue getDocumentClient().
        $documentClient = $shipment->getDocumentClient();

        $this->identity = ProductIdentityResolver::forClient(
            $documentClient?->id,
            $shipment->company_id,
            NamingPreference::fromCompany($documentClient)->withOverrides($this->options),
        );
```

E o import no topo:

```php
use App\Domain\Catalog\DataTransferObjects\NamingPreference;
```

- [ ] **Step 2: Rodar a suíte de documentos**

Run: `php artisan test --filter="CommercialInvoice|DocumentIdentity"`
Expected: PASS — sem o modal preenchido, `withOverrides([])` mantém o padrão da empresa

- [ ] **Step 3: Commit**

```bash
git add app/Domain/Infrastructure/Pdf/Templates/CommercialInvoicePdfTemplate.php
git commit -m "feat(commercial-invoice): respeita a preferência de nomenclatura"
```

---

### Task 8: Controles no modal compartilhado

**Files:**
- Modify: `app/Filament/Resources/Shipments/Concerns/ShipmentHeaderActions.php:182`

Este modal serve CI PDF, preview, e-mail, Excel e PI do embarque — os cinco herdam.

- [ ] **Step 1: Acrescentar os quatro controles**

Adicionar ao final do array devolvido por `commercialInvoiceOptions()`, antes do `];`:

```php
            Select::make(NamingPreference::KEY_CODE)
                ->label('MODEL NO')
                ->options([
                    'counterparty' => 'Código do cliente',
                    'system' => 'Código do sistema',
                ])
                ->default(fn () => $this->namingDefault('document_code_source'))
                ->live(),
            Select::make(NamingPreference::KEY_NAME)
                ->label('Nome do produto')
                ->options([
                    'counterparty' => 'Nome do cliente',
                    'system' => 'Nome do sistema',
                ])
                ->default(fn () => $this->namingDefault('document_name_source'))
                ->live(),
            Toggle::make(NamingPreference::KEY_SHOW_DESCRIPTION)
                ->label('Exibir descrição')
                ->default(fn () => (bool) ($this->getRecord()?->getDocumentClient()?->document_show_description ?? true))
                ->live(),
            Select::make(NamingPreference::KEY_DESCRIPTION)
                ->label('Descrição')
                ->options([
                    'counterparty' => 'Descrição do cliente',
                    'system' => 'Descrição do sistema',
                ])
                ->default(fn () => $this->namingDefault('document_description_source'))
                ->visible(fn (Get $get) => (bool) $get(NamingPreference::KEY_SHOW_DESCRIPTION))
                ->helperText('A descrição do sistema está hoje em português; a do cliente, em inglês.')
                ->live(),
```

- [ ] **Step 2: Adicionar o helper que lê o padrão da empresa**

Adicionar o método na mesma trait:

```php
    /**
     * Padrão de nomenclatura vindo do cliente do documento. Empresa sem valor
     * cai para 'counterparty', que é o comportamento histórico.
     */
    protected function namingDefault(string $column): string
    {
        $client = $this->getRecord()?->getDocumentClient();

        return $client?->{$column}?->value ?? 'counterparty';
    }
```

- [ ] **Step 3: Verificar os imports da trait**

`Select`, `Toggle` e `Get` já são usados no arquivo. Confirmar:

Run: `grep -n "use Filament\\\\Forms\\\\Components\\\\Select;\|use Filament\\\\Forms\\\\Components\\\\Toggle;\|use Filament\\\\Schemas\\\\Components\\\\Utilities\\\\Get;" app/Filament/Resources/Shipments/Concerns/ShipmentHeaderActions.php`
Expected: as três linhas presentes. Se `Get` faltar, adicionar o import que o arquivo já usa em `visible(fn (Get $get) ...)`.

- [ ] **Step 4: Rodar a suíte de shipments**

Run: `php artisan test --filter=Shipment`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/Shipments/Concerns/ShipmentHeaderActions.php
git commit -m "feat(shipments): controles de nomenclatura no modal de documentos"
```

---

### Task 9: Packing List e Proforma Invoice

**Files:**
- Modify: `app/Domain/Infrastructure/Pdf/Templates/PackingListPdfTemplate.php:60`
- Modify: `app/Domain/Infrastructure/Pdf/Templates/ProformaInvoicePdfTemplate.php:79`

- [ ] **Step 1: Packing List — passar a preferência**

Substituir o bloco que cria o resolver em `PackingListPdfTemplate.php`:

```php
        $documentClient = $shipment->getDocumentClient();

        $this->identity = ProductIdentityResolver::forClient(
            $documentClient?->id,
            $shipment->company_id,
            NamingPreference::fromCompany($documentClient)->withOverrides($this->options),
        );
```

Import no topo:

```php
use App\Domain\Catalog\DataTransferObjects\NamingPreference;
```

- [ ] **Step 2: Proforma Invoice — aceitar options e passar a preferência**

Em `ProformaInvoicePdfTemplate.php`, acrescentar `array $options = []` ao final do construtor e repassar ao pai:

```php
    public function __construct(
        \Illuminate\Database\Eloquent\Model $model,
        string $locale = 'en',
        bool $hideCommission = false,
        bool $withImages = false,
        bool $showProductCode = false,
        bool $showModelNumber = true,
        array $options = [],
    ) {
        parent::__construct($model, $locale, $options);
        $this->hideCommission = $hideCommission;
        $this->withImages = $withImages;
        $this->showProductCode = $showProductCode;
        $this->showModelNumber = $showModelNumber;
    }
```

E o resolver:

```php
        $identityResolver = ProductIdentityResolver::forClient(
            $pi->company_id,
            null,
            NamingPreference::fromCompany($pi->company)->withOverrides($this->options),
        );
```

Import no topo:

```php
use App\Domain\Catalog\DataTransferObjects\NamingPreference;
```

- [ ] **Step 3: Rodar as suítes**

Run: `php artisan test --filter="PackingList|ProformaInvoice"`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Domain/Infrastructure/Pdf/Templates/PackingListPdfTemplate.php app/Domain/Infrastructure/Pdf/Templates/ProformaInvoicePdfTemplate.php
git commit -m "feat(documents): preferência de nomenclatura no packing list e na proforma"
```

---

### Task 10: Purchase Order e RFQ

**Files:**
- Modify: `app/Domain/Infrastructure/Pdf/Templates/PurchaseOrderPdfTemplate.php:13-20,63`
- Modify: `app/Domain/Infrastructure/Pdf/Templates/RfqPdfTemplate.php:45`
- Modify: `app/Domain/Infrastructure/Excel/Templates/RfqExcelTemplate.php:68`
- Modify: `app/Filament/Resources/PurchaseOrders/Concerns/PurchaseOrderHeaderActions.php`
- Modify: `app/Filament/Resources/SupplierQuotations/Concerns/SupplierQuotationHeaderActions.php`

- [ ] **Step 1: PO — aceitar options**

Substituir o construtor em `PurchaseOrderPdfTemplate.php`:

```php
    public function __construct(
        \Illuminate\Database\Eloquent\Model $model,
        string $locale = 'en',
        bool $withImages = false,
        array $options = [],
    ) {
        parent::__construct($model, $locale, $options);
        $this->withImages = $withImages;
    }
```

E o resolver:

```php
        $identityResolver = ProductIdentityResolver::forSupplier(
            $po->supplier_company_id,
            NamingPreference::fromCompany($po->supplierCompany)->withOverrides($this->options),
        );
```

Import no topo:

```php
use App\Domain\Catalog\DataTransferObjects\NamingPreference;
```

- [ ] **Step 2: Relações já confirmadas**

`PurchaseOrder::supplierCompany()` está em `app/Domain/PurchaseOrders/Models/PurchaseOrder.php:155`
e `SupplierQuotation::company()` em `app/Domain/SupplierQuotations/Models/SupplierQuotation.php:120`.
Nada a verificar — os nomes usados neste plano são os reais.

- [ ] **Step 3: RFQ PDF e Excel — passar a preferência**

Nos dois arquivos, substituir a criação do resolver:

```php
        $identityResolver = ProductIdentityResolver::forSupplier(
            $sq->company_id,
            NamingPreference::fromCompany($sq->company)->withOverrides($this->options),
        );
```

Import no topo de cada um:

```php
use App\Domain\Catalog\DataTransferObjects\NamingPreference;
```

- [ ] **Step 4: Extrair os controles para uma trait reutilizável**

Criar `app/Filament/Concerns/HasDocumentNamingOptions.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Controles de nomenclatura para os modais de geração de documento.
 *
 * O rótulo muda por papel — "cliente" numa CI, "fornecedor" num PO — mas a
 * mecânica é a mesma: o default vem da empresa e o modal sobrepõe.
 */
trait HasDocumentNamingOptions
{
    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected function documentNamingOptions(?object $counterparty, string $counterpartyLabel): array
    {
        $source = fn (string $column) => $counterparty?->{$column}?->value ?? 'counterparty';

        return [
            Select::make(NamingPreference::KEY_CODE)
                ->label('MODEL NO')
                ->options([
                    'counterparty' => "Código do {$counterpartyLabel}",
                    'system' => 'Código do sistema',
                ])
                ->default($source('document_code_source'))
                ->live(),
            Select::make(NamingPreference::KEY_NAME)
                ->label('Nome do produto')
                ->options([
                    'counterparty' => "Nome do {$counterpartyLabel}",
                    'system' => 'Nome do sistema',
                ])
                ->default($source('document_name_source'))
                ->live(),
            Toggle::make(NamingPreference::KEY_SHOW_DESCRIPTION)
                ->label('Exibir descrição')
                ->default((bool) ($counterparty?->document_show_description ?? true))
                ->live(),
            Select::make(NamingPreference::KEY_DESCRIPTION)
                ->label('Descrição')
                ->options([
                    'counterparty' => "Descrição do {$counterpartyLabel}",
                    'system' => 'Descrição do sistema',
                ])
                ->default($source('document_description_source'))
                ->visible(fn (Get $get) => (bool) $get(NamingPreference::KEY_SHOW_DESCRIPTION))
                ->helperText('A descrição do sistema está hoje em português.')
                ->live(),
        ];
    }
}
```

- [ ] **Step 5: Trocar os controles da Task 8 pela trait**

Em `ShipmentHeaderActions.php`, usar a trait e substituir os quatro controles adicionados na Task 8 e o helper `namingDefault()` por:

```php
            ...$this->documentNamingOptions($this->getRecord()?->getDocumentClient(), 'cliente'),
```

Adicionar `use HasDocumentNamingOptions;` no corpo da trait e o import correspondente.

- [ ] **Step 6: Usar a trait em PO e RFQ**

Em `PurchaseOrderHeaderActions.php`, adicionar ao `formSchema` das ações de PDF e preview:

```php
                    ...$this->documentNamingOptions($this->getRecord()?->supplierCompany, 'fornecedor'),
```

Em `SupplierQuotationHeaderActions.php`, o mesmo nas ações de RFQ:

```php
                    ...$this->documentNamingOptions($this->getRecord()?->company, 'fornecedor'),
```

Adicionar `use HasDocumentNamingOptions;` e o import nas duas traits.

- [ ] **Step 7: Rodar as suítes**

Run: `php artisan test --filter="PurchaseOrder|Rfq|SupplierQuotation|Shipment"`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Domain/Infrastructure app/Filament/Concerns app/Filament/Resources
git commit -m "feat(documents): preferência de nomenclatura em PO e RFQ"
```

---

### Task 11: Padrões no cadastro da empresa

**Files:**
- Modify: `app/Filament/Resources/CRM/Companies/Schemas/CompanyForm.php`

- [ ] **Step 1: Localizar a seção onde `preferred_language` é editado**

Run: `grep -n "preferred_language" app/Filament/Resources/CRM/Companies/Schemas/CompanyForm.php`
Expected: uma linha dentro de alguma `Section::make(...)`. Os campos novos entram logo depois.

- [ ] **Step 2: Adicionar os campos**

```php
                Select::make('document_code_source')
                    ->label('MODEL NO nos documentos')
                    ->options([
                        'counterparty' => 'Código desta empresa',
                        'system' => 'Código do sistema',
                    ])
                    ->default('counterparty')
                    ->helperText('Qual código aparece na coluna MODEL NO dos documentos.'),
                Select::make('document_name_source')
                    ->label('Nome do produto nos documentos')
                    ->options([
                        'counterparty' => 'Nome desta empresa',
                        'system' => 'Nome do sistema',
                    ])
                    ->default('counterparty'),
                Toggle::make('document_show_description')
                    ->label('Exibir descrição nos documentos')
                    ->default(true),
                Select::make('document_description_source')
                    ->label('Descrição nos documentos')
                    ->options([
                        'counterparty' => 'Descrição desta empresa',
                        'system' => 'Descrição do sistema',
                    ])
                    ->default('counterparty')
                    ->helperText('A descrição do sistema está hoje em português.'),
```

- [ ] **Step 3: Escrever o teste de persistência**

Criar `tests/Feature/CRM/CompanyDocumentNamingTest.php`:

```php
<?php

namespace Tests\Feature\CRM;

use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyDocumentNamingTest extends TestCase
{
    use RefreshDatabase;

    public function test_empresa_nova_nasce_com_o_comportamento_historico(): void
    {
        $company = Company::factory()->create();

        $this->assertSame(DocumentNamingSource::Counterparty, $company->fresh()->document_code_source);
        $this->assertSame(DocumentNamingSource::Counterparty, $company->fresh()->document_name_source);
        $this->assertSame(DocumentNamingSource::Counterparty, $company->fresh()->document_description_source);
        $this->assertTrue($company->fresh()->document_show_description);
    }

    public function test_os_padroes_sao_persistidos_e_casteados(): void
    {
        $company = Company::factory()->create();

        $company->update([
            'document_name_source' => 'system',
            'document_show_description' => false,
        ]);

        $this->assertSame(DocumentNamingSource::System, $company->fresh()->document_name_source);
        $this->assertFalse($company->fresh()->document_show_description);
    }
}
```

- [ ] **Step 4: Rodar**

Run: `php artisan test --filter=CompanyDocumentNamingTest`
Expected: PASS — 2 testes

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/CRM/Companies/Schemas/CompanyForm.php tests/Feature/CRM/CompanyDocumentNamingTest.php
git commit -m "feat(crm): padrões de nomenclatura no cadastro da empresa"
```

---

### Task 12: Verificação final

- [ ] **Step 1: Rodar a suíte inteira**

Run: `php artisan test`
Expected: PASS — nenhum teste pré-existente pode quebrar. Se algum quebrar, é sinal de que um
default deixou de reproduzir o comportamento histórico; corrigir o default, não o teste.

- [ ] **Step 2: Conferir o Pint**

Run: `./vendor/bin/pint --test`
Expected: sem violações

- [ ] **Step 3: Validar a Deep Fitness na tela**

1. Importar `produtos-deep-fitness-NCM-8digitos.xlsx` pelo relatório de produtos do cliente.
2. No cadastro da Deep Fitness, marcar `Nome do produto nos documentos = Nome do sistema`.
3. Gerar a CI de um embarque e conferir: MODEL NO com `DPF-*`, nome em inglês, descrição em
   inglês, coluna NCM presente com 4 dígitos.

- [ ] **Step 4: Commit final**

```bash
git add -A
git commit -m "chore(documents): nomenclatura configurável por empresa e por documento"
```
