# Import Product Matching (aliases + matcher LLM) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O import do AI Assistant (SQ e Inquiry) passa a casar produtos por 4 camadas — part_no → nome exato → alias aprendido → sugestão LLM — e aprende um alias novo a cada import confirmado, eliminando a duplicação de produtos em requotes.

**Architecture:** Nova tabela `product_import_aliases` (empresa+descrição normalizada → produto, última confirmação vence). Os resolvers (`ResolveSupplierQuotationDraft`, `ResolveInquiryDraft`) ganham as camadas 3 (alias) e 4 (`ProductMatchSuggester`, uma chamada Anthropic por documento só com itens sem match, degradação silenciosa). Confirm actions gravam aliases; comando de backfill povoa do histórico. Spec: `docs/superpowers/specs/2026-07-25-import-product-matching-design.md`.

**Tech Stack:** Laravel 12, PHPUnit 11, Anthropic SDK (padrão `DraftExtractor`: seam `protected callModel()` para testes — NUNCA bater na API real em teste).

**Convenções:** rodar `vendor/bin/pint --dirty --format agent` antes de cada commit. Testes: `php artisan test --compact <arquivo>`.

---

### Task 1: `NameNormalizer` compartilhado

**Files:**
- Create: `app/Domain/AI/Import/Support/NameNormalizer.php`
- Modify: `app/Domain/AI/Import/ResolveSupplierQuotationDraft.php` (remover `normalizeName()` privado, usar a classe)
- Modify: `app/Console/Commands/ImportYangrunPi0706Command.php` (idem)
- Test: `tests/Unit/AI/NameNormalizerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Domain\AI\Import\Support\NameNormalizer;
use PHPUnit\Framework\TestCase;

class NameNormalizerTest extends TestCase
{
    public function test_strips_punctuation_and_uppercases(): void
    {
        $this->assertSame('DUMBBELL5KG', NameNormalizer::normalize('Dumbbell — 5kg'));
        $this->assertSame('DUMBBELL5KG', NameNormalizer::normalize('  dumbbell 5KG  '));
        $this->assertSame('BARRAWPARAPUXADOR', NameNormalizer::normalize('Barra "W" para Puxador'));
        $this->assertSame('', NameNormalizer::normalize(null));
        $this->assertSame('', NameNormalizer::normalize('—  ,.'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Unit/AI/NameNormalizerTest.php`
Expected: FAIL — `Class "App\Domain\AI\Import\Support\NameNormalizer" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Domain\AI\Import\Support;

/**
 * Chave de comparação de nomes/descrições: só letras e números, maiúsculas.
 * Ignorar pontuação/espaços faz "Dumbbell — 5kg" casar com "Dumbbell 5kg" —
 * variações de formatação entre extrações não podem virar produto duplicado.
 */
class NameNormalizer
{
    public static function normalize(?string $name): string
    {
        return mb_strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $name) ?? '');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Unit/AI/NameNormalizerTest.php`
Expected: PASS

- [ ] **Step 5: Substituir as cópias privadas**

Em `ResolveSupplierQuotationDraft.php`: apagar o método privado `normalizeName()` (linhas ~152-155) e trocar as 2 chamadas `$this->normalizeName(...)` por `NameNormalizer::normalize(...)` (adicionar `use App\Domain\AI\Import\Support\NameNormalizer;`).
Em `ImportYangrunPi0706Command.php`: idem — apagar `normalizeName()` privado e usar `NameNormalizer::normalize(...)` nas 3 chamadas.

- [ ] **Step 6: Run regression tests**

Run: `php artisan test --compact tests/Feature/AI/Import/ResolveSupplierQuotationDraftTest.php`
Expected: PASS (comportamento idêntico)

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "refactor(ai-import): extract shared NameNormalizer"
```

---

### Task 2: Tabela + model `ProductImportAlias`

**Files:**
- Create: `database/migrations/2026_07_25_100000_create_product_import_aliases_table.php`
- Create: `app/Domain/AI/Import/Models/ProductImportAlias.php`
- Test: `tests/Feature/AI/Import/ProductImportAliasTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\Models\ProductImportAlias;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductImportAliasTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_alias_and_enforces_unique_per_company(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->create();

        ProductImportAlias::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'alias' => 'Hex rubber dumbbell — 5kg',
            'alias_normalized' => 'HEXRUBBERDUMBBELL5KG',
            'source' => 'import_confirm',
            'last_confirmed_at' => now(),
        ]);

        $this->assertDatabaseCount('product_import_aliases', 1);

        $this->expectException(QueryException::class);

        ProductImportAlias::create([
            'company_id' => $company->id,
            'product_id' => Product::factory()->create()->id,
            'alias' => 'HEX RUBBER DUMBBELL 5kg',
            'alias_normalized' => 'HEXRUBBERDUMBBELL5KG',
            'source' => 'import_confirm',
            'last_confirmed_at' => now(),
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/AI/Import/ProductImportAliasTest.php`
Expected: FAIL — class/tabela não existem

- [ ] **Step 3: Migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_import_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 500);
            $table->string('alias_normalized', 255);
            $table->string('source', 20); // backfill | import_confirm
            $table->timestamp('last_confirmed_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'alias_normalized']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_import_aliases');
    }
};
```

- [ ] **Step 4: Model**

```php
<?php

declare(strict_types=1);

namespace App\Domain\AI\Import\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alias aprendido de import: "esta descrição, nesta empresa, é este produto".
 * Empresa = fornecedor (SQ) ou cliente (Inquiry). Único por
 * (company_id, alias_normalized); a confirmação mais recente vence.
 */
class ProductImportAlias extends Model
{
    protected $fillable = [
        'company_id',
        'product_id',
        'alias',
        'alias_normalized',
        'source',
        'last_confirmed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['last_confirmed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/AI/Import/ProductImportAliasTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(ai-import): product_import_aliases table + model"
```

---

### Task 3: `UpsertProductImportAliasAction`

**Files:**
- Create: `app/Domain/AI/Import/UpsertProductImportAliasAction.php`
- Test: `tests/Feature/AI/Import/UpsertProductImportAliasActionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\Models\ProductImportAlias;
use App\Domain\AI\Import\UpsertProductImportAliasAction;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpsertProductImportAliasActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_updates_and_guards(): void
    {
        $company = Company::factory()->create();
        $a = Product::factory()->create();
        $b = Product::factory()->create();
        $action = new UpsertProductImportAliasAction;

        // Cria.
        $alias = $action->execute($company->id, $a->id, 'Hex dumbbell — 5kg', 'import_confirm');
        $this->assertNotNull($alias);
        $this->assertSame('HEXDUMBBELL5KG', $alias->alias_normalized);

        // Mesma descrição, outro produto: a confirmação mais recente vence (sem duplicar).
        $action->execute($company->id, $b->id, 'HEX DUMBBELL 5kg', 'import_confirm');
        $this->assertDatabaseCount('product_import_aliases', 1);
        $this->assertSame($b->id, ProductImportAlias::sole()->product_id);
        $this->assertSame('HEX DUMBBELL 5kg', ProductImportAlias::sole()->alias);

        // Guarda: normalizado < 3 chars não grava.
        $this->assertNull($action->execute($company->id, $a->id, '5', 'import_confirm'));
        $this->assertNull($action->execute($company->id, $a->id, '  ', 'import_confirm'));
        $this->assertDatabaseCount('product_import_aliases', 1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/AI/Import/UpsertProductImportAliasActionTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implementation**

```php
<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use App\Domain\AI\Import\Models\ProductImportAlias;
use App\Domain\AI\Import\Support\NameNormalizer;

/**
 * Grava/atualiza um alias aprendido de import. Última confirmação vence:
 * se a mesma descrição normalizada já existir para a empresa apontando para
 * outro produto, o vínculo é trocado (dentro de uma empresa, uma descrição
 * aponta para um único produto). Descrições curtas demais são ignoradas.
 */
class UpsertProductImportAliasAction
{
    public function execute(int $companyId, int $productId, ?string $alias, string $source, ?int $userId = null): ?ProductImportAlias
    {
        $alias = trim((string) $alias);
        $normalized = mb_substr(NameNormalizer::normalize($alias), 0, 255);

        if (mb_strlen($normalized) < 3) {
            return null;
        }

        $record = ProductImportAlias::query()
            ->where('company_id', $companyId)
            ->where('alias_normalized', $normalized)
            ->first();

        if ($record) {
            $record->update([
                'product_id' => $productId,
                'alias' => mb_substr($alias, 0, 500),
                'source' => $source,
                'last_confirmed_at' => now(),
            ]);

            return $record;
        }

        return ProductImportAlias::create([
            'company_id' => $companyId,
            'product_id' => $productId,
            'alias' => mb_substr($alias, 0, 500),
            'alias_normalized' => $normalized,
            'source' => $source,
            'last_confirmed_at' => now(),
            'created_by' => $userId,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/AI/Import/UpsertProductImportAliasActionTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(ai-import): upsert action for learned product aliases"
```

---

### Task 4: Camada 3 (alias) + `match_source` no resolver de SQ

**Files:**
- Modify: `app/Domain/AI/Import/ResolveSupplierQuotationDraft.php`
- Test: `tests/Feature/AI/Import/ResolveSupplierQuotationDraftTest.php` (adicionar testes)

- [ ] **Step 1: Write the failing tests**

Adicionar ao `ResolveSupplierQuotationDraftTest`:

```php
    public function test_alias_layer_matches_learned_description(): void
    {
        $supplier = Company::factory()->create(['name' => 'Hebei Yangrun Sports Equipment Co., Ltd.']);
        $product = Product::factory()->create(['name' => 'Hexagonal Dumbbell 5kg', 'reference_code' => null, 'model_number' => null]);
        $product->companies()->attach($supplier->id, ['role' => 'supplier']);

        \App\Domain\AI\Import\Models\ProductImportAlias::create([
            'company_id' => $supplier->id,
            'product_id' => $product->id,
            'alias' => 'Halter hexagonal emborrachado — 5kg',
            'alias_normalized' => \App\Domain\AI\Import\Support\NameNormalizer::normalize('Halter hexagonal emborrachado — 5kg'),
            'source' => 'import_confirm',
            'last_confirmed_at' => now(),
        ]);

        $preview = (new ResolveSupplierQuotationDraft)->resolve([
            'fornecedor' => ['nome' => 'Hebei Yangrun', 'currency_code' => 'USD'],
            'itens' => [
                ['description' => 'Halter Hexagonal Emborrachado 5kg', 'quantity' => 10, 'unit_price' => 3.5],
            ],
        ]);

        $this->assertSame('existente', $preview['itens'][0]['status']);
        $this->assertSame($product->id, $preview['itens'][0]['product_id']);
        $this->assertSame('alias', $preview['itens'][0]['match_source']);
    }

    public function test_exact_name_beats_alias(): void
    {
        $supplier = Company::factory()->create(['name' => 'Hebei Yangrun Sports Equipment Co., Ltd.']);
        $byName = Product::factory()->create(['name' => 'Dumbbell 10kg', 'reference_code' => null, 'model_number' => null]);
        $byName->companies()->attach($supplier->id, ['role' => 'supplier']);
        $byAlias = Product::factory()->create(['name' => 'Outro produto']);

        // Alias conflitante para a MESMA descrição — o nome exato deve vencer.
        \App\Domain\AI\Import\Models\ProductImportAlias::create([
            'company_id' => $supplier->id,
            'product_id' => $byAlias->id,
            'alias' => 'Dumbbell 10kg',
            'alias_normalized' => \App\Domain\AI\Import\Support\NameNormalizer::normalize('Dumbbell 10kg'),
            'source' => 'backfill',
            'last_confirmed_at' => now(),
        ]);

        $preview = (new ResolveSupplierQuotationDraft)->resolve([
            'fornecedor' => ['nome' => 'Hebei Yangrun', 'currency_code' => 'USD'],
            'itens' => [['description' => 'Dumbbell — 10kg', 'quantity' => 1, 'unit_price' => 1.0]],
        ]);

        $this->assertSame($byName->id, $preview['itens'][0]['product_id']);
        $this->assertSame('name', $preview['itens'][0]['match_source']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/AI/Import/ResolveSupplierQuotationDraftTest.php`
Expected: FAIL — `match_source` ausente / alias não casa

- [ ] **Step 3: Implementation**

Em `ResolveSupplierQuotationDraft`:

1. Em `resolve()`, depois de `$supplierProductsByName = ...`, adicionar:

```php
        $aliasesByKey = $this->aliasesByKey($supplier);
        $itens = array_map(fn (array $item) => $this->resolveItem($item, $supplierProductsByName, $aliasesByKey), $draft['itens'] ?? []);
```

2. Novo método privado:

```php
    /**
     * Aliases aprendidos da empresa, indexados pela descrição normalizada.
     *
     * @return array<string, int> alias_normalized => product_id
     */
    private function aliasesByKey(?Company $supplier): array
    {
        if ($supplier === null) {
            return [];
        }

        return \App\Domain\AI\Import\Models\ProductImportAlias::query()
            ->where('company_id', $supplier->id)
            ->pluck('product_id', 'alias_normalized')
            ->all();
    }
```

(usar `use App\Domain\AI\Import\Models\ProductImportAlias;` no topo em vez do FQN)

3. Em `resolveItem(array $item, array $supplierProductsByName, array $aliasesByKey)`: registrar a origem do match e aplicar a camada 3:

```php
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

        // Documento sem coluna de modelo: casa a descrição com o NOME dos
        // produtos deste fornecedor (exato, normalizado, único).
        if ($product === null) {
            $product = $supplierProductsByName[NameNormalizer::normalize($item['description'] ?? null)] ?? null;
            $matchSource = $product !== null ? 'name' : null;
        }

        // Camada 3: alias aprendido em imports confirmados anteriores.
        if ($product === null) {
            $aliasProductId = $aliasesByKey[NameNormalizer::normalize($item['description'] ?? null)] ?? null;
            $product = $aliasProductId !== null ? Product::find($aliasProductId) : null;
            $matchSource = $product !== null ? 'alias' : null;
        }
```

E no array de retorno do item, adicionar a chave:

```php
            'match_source' => $matchSource,
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/AI/Import/ResolveSupplierQuotationDraftTest.php`
Expected: PASS (novos e antigos)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(ai-import): alias layer + match_source in SQ resolver"
```

---

### Task 5: `ProductMatchSuggester` (matcher LLM)

**Files:**
- Create: `app/Domain/AI/Import/ProductMatchSuggester.php`
- Test: `tests/Feature/AI/Import/ProductMatchSuggesterTest.php`

- [ ] **Step 1: Write the failing test**

O fake segue o padrão do `DraftExtractorTest`: subclasse anônima sobrescrevendo `callModel()` — nunca bater na API real (vendor/ pode ser symlink do repo principal em worktrees).

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\ProductMatchSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMatchSuggesterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int,mixed>  $matches
     */
    private function fake(array $matches): ProductMatchSuggester
    {
        return new class($matches) extends ProductMatchSuggester
        {
            public function __construct(private readonly array $matches)
            {
                parent::__construct();
            }

            protected function callModel(string $system, string $user): object
            {
                return (object) ['content' => [
                    (object) ['type' => 'tool_use', 'name' => 'sugerir_vinculos', 'input' => (object) ['matches' => $this->matches]],
                ]];
            }
        };
    }

    public function test_returns_index_to_product_map_ignoring_ids_outside_catalog(): void
    {
        $suggester = $this->fake([
            (object) ['index' => 0, 'product_id' => 10],
            (object) ['index' => 1, 'product_id' => null],
            (object) ['index' => 2, 'product_id' => 999], // fora do catálogo — descartado
        ]);

        $result = $suggester->suggest(
            [
                0 => ['description' => 'Halter hexagonal 5kg'],
                1 => ['description' => 'Produto desconhecido'],
                2 => ['description' => 'Outro'],
            ],
            [
                ['id' => 10, 'name' => 'Hexagonal Dumbbell 5kg', 'reference_code' => null, 'model_number' => null, 'aliases' => []],
                ['id' => 11, 'name' => 'Weight plate 5kg', 'reference_code' => null, 'model_number' => null, 'aliases' => []],
            ],
        );

        $this->assertSame([0 => 10], $result);
    }

    public function test_empty_input_short_circuits_without_api_call(): void
    {
        $suggester = new class extends ProductMatchSuggester
        {
            protected function callModel(string $system, string $user): object
            {
                throw new \RuntimeException('não deveria chamar a API');
            }
        };

        $this->assertSame([], $suggester->suggest([], [['id' => 1, 'name' => 'X', 'reference_code' => null, 'model_number' => null, 'aliases' => []]]));
        $this->assertSame([], $suggester->suggest([0 => ['description' => 'Y']], []));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/AI/Import/ProductMatchSuggesterTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implementation**

```php
<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use Anthropic\Client;
use Anthropic\Messages\Tool;
use Anthropic\Messages\ToolChoiceTool;

/**
 * Camada 4 do matching de produtos do import: para itens sem match
 * determinístico, pede ao modelo que aponte o produto do catálogo da empresa —
 * ou null. Uma chamada por documento, tool forçada. As camadas determinísticas
 * sempre rodam antes; o chamador trata falhas silenciosamente (o import nunca
 * trava por causa desta chamada).
 */
class ProductMatchSuggester
{
    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(apiKey: (string) config('services.anthropic.key'));
    }

    /**
     * @param  array<int, array{description:string}>  $items  índice original => item sem match
     * @param  list<array{id:int,name:string,reference_code:?string,model_number:?string,aliases:list<string>}>  $catalog
     * @return array<int,int> índice original => product_id sugerido
     */
    public function suggest(array $items, array $catalog): array
    {
        if ($items === [] || $catalog === []) {
            return [];
        }

        $validIds = array_column($catalog, 'id');

        $user = "Itens do documento sem match:\n"
            .json_encode(
                array_map(fn ($i, $item) => ['index' => $i, 'description' => $item['description']], array_keys($items), $items),
                JSON_UNESCAPED_UNICODE,
            )
            ."\n\nCatálogo da empresa:\n"
            .json_encode($catalog, JSON_UNESCAPED_UNICODE);

        $response = $this->callModel($this->systemPrompt(), $user);

        $matches = [];

        foreach ($response->content as $block) {
            if (($block->type ?? null) !== 'tool_use' || ($block->name ?? null) !== 'sugerir_vinculos') {
                continue;
            }

            foreach ((array) ($block->input->matches ?? []) as $match) {
                $index = (int) ($match->index ?? -1);
                $productId = $match->product_id ?? null;

                if ($productId !== null && isset($items[$index]) && in_array((int) $productId, $validIds, true)) {
                    $matches[$index] = (int) $productId;
                }
            }
        }

        return $matches;
    }

    protected function systemPrompt(): string
    {
        return 'Você vincula itens de documentos comerciais (cotações, PIs) a produtos já '
            .'cadastrados de uma empresa, para uma trading company. Para cada item, aponte o '
            .'product_id do catálogo que representa o MESMO produto, ou null se não houver. '
            .'Regras: tokens de peso, tamanho e dimensão devem bater EXATAMENTE (5kg nunca casa '
            .'com 10kg; 1.2m nunca casa com 1.5m). Use nomes, códigos e aliases do catálogo como '
            .'evidência. Na dúvida, retorne null — errar o vínculo é pior que não vincular. '
            .'Responda para TODOS os índices recebidos.';
    }

    /**
     * Single forced-tool round-trip. Seam for tests (override to avoid the API).
     */
    protected function callModel(string $system, string $user): object
    {
        return $this->client->messages->create(
            maxTokens: 4096,
            messages: [['role' => 'user', 'content' => $user]],
            model: (string) config('services.anthropic.assistant_model', 'claude-opus-4-8'),
            system: $system,
            tools: [Tool::with(
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'matches' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'index' => ['type' => 'integer'],
                                    'product_id' => ['type' => ['integer', 'null']],
                                ],
                                'required' => ['index', 'product_id'],
                            ],
                        ],
                    ],
                    'required' => ['matches'],
                ],
                name: 'sugerir_vinculos',
                description: 'Registra o vínculo item→produto sugerido para cada índice.',
            )],
            toolChoice: ToolChoiceTool::with(name: 'sugerir_vinculos'),
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/AI/Import/ProductMatchSuggesterTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(ai-import): ProductMatchSuggester LLM batch matcher"
```

---

### Task 6: Camada 4 no resolver de SQ (com degradação silenciosa)

**Files:**
- Modify: `app/Domain/AI/Import/ResolveSupplierQuotationDraft.php`
- Test: `tests/Feature/AI/Import/ResolveSupplierQuotationDraftTest.php`

- [ ] **Step 1: Write the failing tests**

```php
    /**
     * @param  array<int,int>  $suggestions
     */
    private function resolverWithSuggester(array $suggestions, bool $throw = false): ResolveSupplierQuotationDraft
    {
        $suggester = new class($suggestions, $throw) extends \App\Domain\AI\Import\ProductMatchSuggester
        {
            public function __construct(private readonly array $suggestions, private readonly bool $throw)
            {
                parent::__construct();
            }

            public function suggest(array $items, array $catalog): array
            {
                if ($this->throw) {
                    throw new \RuntimeException('API down');
                }

                return array_intersect_key($this->suggestions, $items);
            }
        };

        return new ResolveSupplierQuotationDraft($suggester);
    }

    public function test_ai_layer_suggests_only_for_unmatched_items(): void
    {
        $supplier = Company::factory()->create(['name' => 'Hebei Yangrun Sports Equipment Co., Ltd.']);
        $exact = Product::factory()->create(['name' => 'Weight plate 5kg', 'reference_code' => null, 'model_number' => null]);
        $exact->companies()->attach($supplier->id, ['role' => 'supplier']);
        $suggested = Product::factory()->create(['name' => 'Hexagonal Dumbbell 5kg', 'reference_code' => null, 'model_number' => null]);
        $suggested->companies()->attach($supplier->id, ['role' => 'supplier']);

        $preview = $this->resolverWithSuggester([1 => $suggested->id])->resolve([
            'fornecedor' => ['nome' => 'Hebei Yangrun', 'currency_code' => 'USD'],
            'itens' => [
                ['description' => 'Weight plate 5kg', 'quantity' => 1, 'unit_price' => 1.0],
                ['description' => 'Halter hexagonal emborrachado 5kg', 'quantity' => 1, 'unit_price' => 2.0],
            ],
        ]);

        $this->assertSame('name', $preview['itens'][0]['match_source']);
        $this->assertSame($suggested->id, $preview['itens'][1]['product_id']);
        $this->assertSame('existente', $preview['itens'][1]['status']);
        $this->assertSame('ai', $preview['itens'][1]['match_source']);
    }

    public function test_ai_failure_degrades_silently_to_novo(): void
    {
        $supplier = Company::factory()->create(['name' => 'Hebei Yangrun Sports Equipment Co., Ltd.']);
        $product = Product::factory()->create(['name' => 'Weight plate 5kg']);
        $product->companies()->attach($supplier->id, ['role' => 'supplier']);

        $preview = $this->resolverWithSuggester([], throw: true)->resolve([
            'fornecedor' => ['nome' => 'Hebei Yangrun', 'currency_code' => 'USD'],
            'itens' => [['description' => 'Coisa desconhecida', 'quantity' => 1, 'unit_price' => 1.0]],
        ]);

        $this->assertSame('novo', $preview['itens'][0]['status']);
        $this->assertNull($preview['itens'][0]['product_id']);
        $this->assertNull($preview['itens'][0]['match_source']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/AI/Import/ResolveSupplierQuotationDraftTest.php`
Expected: FAIL — construtor não aceita suggester

- [ ] **Step 3: Implementation**

Em `ResolveSupplierQuotationDraft`:

1. Construtor (mantém compatibilidade com `new ResolveSupplierQuotationDraft` e `app(...)`):

```php
    public function __construct(private readonly ProductMatchSuggester $suggester = new ProductMatchSuggester) {}
```

2. Em `resolve()`, logo depois do `array_map` que monta `$itens`, aplicar a camada 4 antes de calcular `$existing`/`$itemsMinor`:

```php
        $itens = $this->applyAiSuggestions($itens, $supplier);
```

3. Novos métodos:

```php
    /**
     * Camada 4: sugestão LLM para itens ainda sem match. Erros degradam
     * silenciosamente — o import nunca trava por causa desta chamada.
     *
     * @param  list<array<string,mixed>>  $itens
     * @return list<array<string,mixed>>
     */
    private function applyAiSuggestions(array $itens, ?Company $supplier): array
    {
        if ($supplier === null) {
            return $itens;
        }

        $unmatched = array_filter($itens, fn ($i) => $i['product_id'] === null);

        if ($unmatched === []) {
            return $itens;
        }

        try {
            $suggestions = $this->suggester->suggest(
                array_map(fn ($i) => ['description' => (string) $i['description']], $unmatched),
                $this->catalogFor($supplier),
            );
        } catch (\Throwable $e) {
            report($e);

            return $itens;
        }

        foreach ($suggestions as $index => $productId) {
            $product = Product::find($productId);

            if ($product === null || ! isset($itens[$index])) {
                continue;
            }

            $itens[$index]['status'] = 'existente';
            $itens[$index]['product_id'] = $product->id;
            $itens[$index]['product_name'] = $product->name;
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
    private function catalogFor(Company $supplier): array
    {
        $aliases = ProductImportAlias::query()
            ->where('company_id', $supplier->id)
            ->orderByDesc('last_confirmed_at')
            ->get(['product_id', 'alias'])
            ->groupBy('product_id')
            ->map(fn ($group) => $group->take(5)->pluck('alias')->all());

        return Product::query()
            ->whereHas('companies', fn ($q) => $q
                ->where('companies.id', $supplier->id)
                ->where('company_product.role', 'supplier'))
            ->get(['id', 'name', 'reference_code', 'model_number'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'reference_code' => $p->reference_code,
                'model_number' => $p->model_number,
                'aliases' => $aliases->get($p->id, []),
            ])
            ->all();
    }
```

Nota: `array_filter` preserva os índices originais, então os índices que o suggester devolve apontam para as posições corretas de `$itens`. O `resumo` (contagens `produtos_existentes`/`novos`) já é calculado depois — conferir que a chamada `applyAiSuggestions` vem ANTES de `$existing = count(...)`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/AI/Import/ResolveSupplierQuotationDraftTest.php`
Expected: PASS (todos)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(ai-import): AI suggestion layer in SQ resolver"
```

---

### Task 7: Camadas 2–4 no resolver de Inquiry

**Files:**
- Modify: `app/Domain/AI/Import/ResolveInquiryDraft.php`
- Test: `tests/Feature/AI/Import/ResolveInquiryDraftTest.php`

- [ ] **Step 1: Write the failing tests**

Adicionar ao `ResolveInquiryDraftTest` (seguir os imports/estilo existentes do arquivo):

```php
    public function test_matches_by_client_scoped_name_alias_and_ai(): void
    {
        $client = Company::factory()->create(['name' => 'DEEP FITNESS']);
        $byName = Product::factory()->create(['name' => 'Hexagonal Dumbbell 5kg', 'reference_code' => null, 'model_number' => null]);
        $byName->companies()->attach($client->id, ['role' => 'client']);
        $byAlias = Product::factory()->create(['name' => 'Weight plate 10kg']);
        $byAlias->companies()->attach($client->id, ['role' => 'client']);
        $byAi = Product::factory()->create(['name' => 'Vertical Dumbbell Rack']);
        $byAi->companies()->attach($client->id, ['role' => 'client']);

        \App\Domain\AI\Import\Models\ProductImportAlias::create([
            'company_id' => $client->id,
            'product_id' => $byAlias->id,
            'alias' => 'Anilha emborrachada 10kg',
            'alias_normalized' => \App\Domain\AI\Import\Support\NameNormalizer::normalize('Anilha emborrachada 10kg'),
            'source' => 'import_confirm',
            'last_confirmed_at' => now(),
        ]);

        $suggester = new class([2 => 0]) extends \App\Domain\AI\Import\ProductMatchSuggester
        {
            public function __construct(public array $map)
            {
                parent::__construct();
            }

            public function suggest(array $items, array $catalog): array
            {
                // O teste injeta o id real depois; ver abaixo.
                return $this->map;
            }
        };
        $suggester->map = [2 => $byAi->id];

        $preview = (new ResolveInquiryDraft($suggester))->resolve([
            'cliente' => ['nome' => 'DEEP FITNESS', 'currency_code' => 'USD'],
            'itens' => [
                ['description' => 'Hexagonal Dumbbell — 5kg', 'quantity' => 1],
                ['description' => 'Anilha Emborrachada 10kg', 'quantity' => 1],
                ['description' => 'Rack vertical para halteres', 'quantity' => 1],
            ],
        ]);

        $this->assertSame([$byName->id, 'name'], [$preview['itens'][0]['product_id'], $preview['itens'][0]['match_source']]);
        $this->assertSame([$byAlias->id, 'alias'], [$preview['itens'][1]['product_id'], $preview['itens'][1]['match_source']]);
        $this->assertSame([$byAi->id, 'ai'], [$preview['itens'][2]['product_id'], $preview['itens'][2]['match_source']]);
        $this->assertSame(3, $preview['resumo']['produtos_casados']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/AI/Import/ResolveInquiryDraftTest.php`
Expected: FAIL

- [ ] **Step 3: Implementation**

Espelhar a estrutura do `ResolveSupplierQuotationDraft` em `ResolveInquiryDraft`:

- Construtor: `public function __construct(private readonly ProductMatchSuggester $suggester = new ProductMatchSuggester) {}`
- Em `resolve()`: montar `$clientProductsByName` (produtos com `companies.id = $client->id`, SEM filtro de role — o pool do cliente) e `$aliasesByKey` (mesma query da SQ com `company_id = $client->id`); passar ambos a `resolveItem`; depois do `array_map`, `$itens = $this->applyAiSuggestions($itens, $client);` ANTES de calcular `$matched`.
- `resolveItem`: camada 1 `part_no` (existente, `$matchSource = 'part_no'`), camada 2 nome (`$clientProductsByName[NameNormalizer::normalize($item['description'] ?? null)] ?? null`, `'name'`), camada 3 alias (`'alias'`). Adicionar `'match_source' => $matchSource` ao retorno. **Match-only continua**: nenhum produto é criado aqui.
- `applyAiSuggestions` e `catalogFor`: copiar do resolver de SQ trocando o filtro de role (sem `where('company_product.role', 'supplier')`) — a duplicação é aceitável (dois resolvers já são paralelos); NÃO extrair trait neste plano (YAGNI).
- `clientProductsByName`/mapa de nomes: mesma mecânica de descartar nomes duplicados (copiar `supplierProductsByName` adaptando a query).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/AI/Import/ResolveInquiryDraftTest.php`
Expected: PASS (novos e antigos)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(ai-import): name/alias/AI matching layers in inquiry resolver"
```

---

### Task 8: `match_source` na UI do review (badge "Sugerido por IA")

**Files:**
- Modify: `app/Domain/AI/Import/Targets/SupplierQuotationTarget.php` (`buildForm`)
- Modify: `app/Domain/AI/Import/Targets/InquiryTarget.php` (`buildForm` — mesma adição)
- Modify: `app/Filament/Pages/Concerns/HandlesDocumentImport.php` (vincular/desvincular manual zera o badge)
- Modify: `resources/views/filament/pages/assistant/review-supplier_quotation.blade.php`
- Modify: `resources/views/filament/pages/assistant/review-inquiry.blade.php`
- Modify: `lang/pt_BR/assistant.php`, `lang/en/assistant.php`, `lang/zh_CN/assistant.php`
- Test: `tests/Feature/AI/Import/SupplierQuotationTargetTest.php`

- [ ] **Step 1: Write the failing test**

Adicionar ao `SupplierQuotationTargetTest` (seguir estilo do arquivo):

```php
    public function test_build_form_carries_match_source(): void
    {
        $target = new SupplierQuotationTarget;

        $form = $target->buildForm([
            'fornecedor' => ['nome' => 'X', 'status' => 'existente', 'company_id' => 1],
            'cabecalho' => [],
            'itens' => [
                ['description' => 'A', 'quantity' => 1, 'unit_cost_minor' => 100, 'status' => 'existente', 'product_id' => 5, 'match_source' => 'ai'],
                ['description' => 'B', 'quantity' => 1, 'unit_cost_minor' => 100, 'status' => 'novo', 'product_id' => null],
            ],
        ], []);

        $this->assertSame('ai', $form['itens'][0]['match_source']);
        $this->assertNull($form['itens'][1]['match_source']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/AI/Import/SupplierQuotationTargetTest.php`
Expected: FAIL — chave `match_source` ausente

- [ ] **Step 3: Implementation**

1. `SupplierQuotationTarget::buildForm()` — no array de item, adicionar:

```php
                'match_source' => $it['match_source'] ?? null,
```

2. `InquiryTarget::buildForm()` — mesma adição no array de item (localizar o `foreach` equivalente).

3. `HandlesDocumentImport.php` linha ~769 (vínculo manual seta `status = 'existente'`): adicionar logo abaixo:

```php
        $this->form['itens'][$itemIndex]['match_source'] = 'manual';
```

Procurar também o fluxo de DESVINCULAR (onde `status` volta a `'novo'` — `grep -n "'novo'" app/Filament/Pages/Concerns/HandlesDocumentImport.php`) e no mesmo ponto zerar: `$this->form['itens'][$itemIndex]['match_source'] = null;`

4. Lang — adicionar a chave nos 3 arquivos, perto de `status_new`/`status_existing`:

- `lang/pt_BR/assistant.php`: `'status_ai_suggested' => 'sugerido por IA',`
- `lang/en/assistant.php`: `'status_ai_suggested' => 'AI suggested',`
- `lang/zh_CN/assistant.php`: `'status_ai_suggested' => 'AI 建议',`

5. Blades — em `review-supplier_quotation.blade.php`, junto ao badge de status do item (o bloco `@if (($item['status'] ?? '') === 'novo')` na linha ~133 tem um ramo para itens existentes; inserir ao lado do badge "existente"):

```blade
@if (($item['match_source'] ?? null) === 'ai')
    <span class="ml-1 rounded px-1.5 py-0.5 text-[10px] bg-blue-200 text-blue-900">{{ __('assistant.status_ai_suggested') }}</span>
@endif
```

Mesma inserção em `review-inquiry.blade.php` no ponto equivalente do badge de status do item.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/AI/Import/SupplierQuotationTargetTest.php tests/Feature/AI/Import/InquiryTargetTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(ai-import): AI-suggested badge in import review"
```

---

### Task 9: Aprendizado no confirm (SQ + Inquiry)

**Files:**
- Modify: `app/Domain/SupplierQuotations/Actions/ImportSupplierQuotationAction.php`
- Modify: `app/Domain/Inquiries/Actions/ImportInquiryAction.php`
- Test: `tests/Feature/AI/Import/ImportSupplierQuotationActionTest.php`, `tests/Feature/AI/Import/ImportInquiryActionTest.php`

- [ ] **Step 1: Write the failing tests**

Em `ImportSupplierQuotationActionTest` — o arquivo já tem os helpers `previewWithNewSupplierAndProduct()` (linha ~32), `fakeFile()` (linha ~45) e o `$user` criado no padrão dos testes vizinhos (ver `test_creates_supplier_products_and_quotation_in_one_go`, linha ~54). Novo teste:

```php
    public function test_confirm_learns_aliases_for_items_with_product(): void
    {
        $user = $this->userWithImportPermissions(); // usar o mesmo helper/setup dos testes vizinhos
        $existing = Product::factory()->create(['name' => 'Hexagonal Dumbbell 5kg']);

        $preview = $this->previewWithNewSupplierAndProduct();
        // Item extra 'existente' com produto vinculado:
        $preview['itens'][] = [
            'status' => 'existente',
            'product_id' => $existing->id,
            'part_no' => null,
            'description' => 'Halter hexagonal — 5kg',
            'quantity' => 2,
            'unit' => 'pcs',
            'unit_cost_minor' => 35000,
            'category_id' => null,
        ];

        (new ImportSupplierQuotationAction)($preview, $user, $this->fakeFile());

        $this->assertDatabaseHas('product_import_aliases', [
            'product_id' => $existing->id,
            'alias_normalized' => \App\Domain\AI\Import\Support\NameNormalizer::normalize('Halter hexagonal — 5kg'),
            'source' => 'import_confirm',
        ]);

        // O item "novo" do preview base criou produto draft — o alias dele
        // também é gravado (aponta para o produto recém-criado):
        $this->assertSame(count($preview['itens']), \App\Domain\AI\Import\Models\ProductImportAlias::count());
    }
```

Nota: se o setup de usuário for inline nos testes vizinhos (sem helper), copiar o mesmo bloco inline em vez de `userWithImportPermissions()`.

Em `ImportInquiryActionTest` — o arquivo tem `previewNova(array $overrides = [])` (linha ~38). Novo teste:

```php
    public function test_confirm_learns_alias_scoped_to_client(): void
    {
        $user = /* mesmo setup de usuário de test_new_mode_creates_client_inquiry_and_items */;
        $product = Product::factory()->create(['name' => 'Weight plate 10kg']);

        $preview = $this->previewNova();
        $preview['itens'] = [[
            'product_id' => $product->id,
            'part_no' => null,
            'description' => 'Anilha emborrachada 10kg',
            'quantity' => 4,
            'unit' => 'pcs',
        ]];

        $inquiry = (new ImportInquiryAction)($preview, $user, $this->fakeFile());

        $this->assertDatabaseHas('product_import_aliases', [
            'company_id' => $inquiry->company_id,
            'product_id' => $product->id,
            'source' => 'import_confirm',
        ]);
    }
```

(Se `ImportInquiryActionTest` não tiver `fakeFile()`, copiar o helper do `ImportSupplierQuotationActionTest`.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/AI/Import/ImportSupplierQuotationActionTest.php tests/Feature/AI/Import/ImportInquiryActionTest.php`
Expected: FAIL — tabela vazia

- [ ] **Step 3: Implementation**

1. `ImportSupplierQuotationAction` — construtor ganha o upserter:

```php
    public function __construct(
        private readonly GenerateProductSkuAction $skuGenerator = new GenerateProductSkuAction,
        private readonly UpsertProductImportAliasAction $aliasUpserter = new UpsertProductImportAliasAction,
    ) {}
```

No `foreach` dos itens, logo após `SupplierQuotationItem::create([...])`:

```php
                    if ($product !== null) {
                        $this->aliasUpserter->execute($company->id, $product->id, (string) $item['description'], 'import_confirm', $user->id);
                    }
```

(`use App\Domain\AI\Import\UpsertProductImportAliasAction;`)

2. `ImportInquiryAction` — mesmo padrão: construtor com `private readonly UpsertProductImportAliasAction $aliasUpserter = new UpsertProductImportAliasAction`, e no `foreach` após `InquiryItem::create([...])`:

```php
                    if ($product !== null) {
                        $this->aliasUpserter->execute($inquiry->company_id, $product->id, (string) ($item['description'] ?? ''), 'import_confirm', $user->id);
                    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/AI/Import/ImportSupplierQuotationActionTest.php tests/Feature/AI/Import/ImportInquiryActionTest.php tests/Feature/AI/Import/ImportSupplierQuotationWithInquiryActionTest.php`
Expected: PASS (o WithInquiry compõe a action de SQ — regressão coberta)

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(ai-import): learn product aliases on import confirm"
```

---

### Task 10: Comando de backfill

**Files:**
- Create: `app/Console/Commands/BackfillProductImportAliasesCommand.php`
- Test: `tests/Feature/Console/BackfillProductImportAliasesCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\AI\Import\Models\ProductImportAlias;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillProductImportAliasesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_from_sq_and_inquiry_items_latest_wins(): void
    {
        $supplier = Company::factory()->create();
        $client = Company::factory()->create();
        $old = Product::factory()->create();
        $new = Product::factory()->create();

        $sq = SupplierQuotation::factory()->create(['company_id' => $supplier->id]);
        // Mesma descrição duas vezes — o item mais recente vence.
        SupplierQuotationItem::factory()->create([
            'supplier_quotation_id' => $sq->id, 'product_id' => $old->id,
            'description' => 'Hex dumbbell 5kg', 'created_at' => now()->subDay(),
        ]);
        SupplierQuotationItem::factory()->create([
            'supplier_quotation_id' => $sq->id, 'product_id' => $new->id,
            'description' => 'Hex Dumbbell — 5kg', 'created_at' => now(),
        ]);
        // Sem produto: ignorado.
        SupplierQuotationItem::factory()->create([
            'supplier_quotation_id' => $sq->id, 'product_id' => null, 'description' => 'Orfão',
        ]);

        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);
        InquiryItem::factory()->create([
            'inquiry_id' => $inquiry->id, 'product_id' => $old->id, 'description' => 'Anilha 10kg',
        ]);

        // Dry-run: nada gravado.
        $this->artisan('imports:backfill-product-aliases')->assertSuccessful();
        $this->assertDatabaseCount('product_import_aliases', 0);

        // Apply.
        $this->artisan('imports:backfill-product-aliases', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseCount('product_import_aliases', 2);
        $this->assertSame($new->id, ProductImportAlias::where('company_id', $supplier->id)->sole()->product_id);
        $this->assertSame($old->id, ProductImportAlias::where('company_id', $client->id)->sole()->product_id);
        $this->assertSame('backfill', ProductImportAlias::first()->source);
    }
}
```

Nota: se `SupplierQuotationItem`/`InquiryItem` não tiverem factory, criar os registros com `::create()` direto (conferir factories existentes em `database/factories/` antes; seguir o que os testes vizinhos de import fazem).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Console/BackfillProductImportAliasesCommandTest.php`
Expected: FAIL — comando não existe

- [ ] **Step 3: Implementation**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\AI\Import\UpsertProductImportAliasAction;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Console\Command;

/**
 * Povoa product_import_aliases a partir do histórico confirmado: itens de SQ
 * (empresa = fornecedor da SQ) e de Inquiry (empresa = cliente) que têm
 * product_id. Processa em ordem cronológica — o upsert faz o item mais
 * recente vencer em duplicata. Dry-run por default; --apply grava.
 */
class BackfillProductImportAliasesCommand extends Command
{
    protected $signature = 'imports:backfill-product-aliases {--apply : Persiste (senão, dry-run)}';

    protected $description = 'Backfill de aliases de produto a partir de itens de SQ/Inquiry já vinculados';

    public function handle(UpsertProductImportAliasAction $upserter): int
    {
        $apply = (bool) $this->option('apply');
        $written = 0;
        $skipped = 0;

        $process = function (int $companyId, ?int $productId, ?string $description) use ($apply, $upserter, &$written, &$skipped): void {
            if ($productId === null || blank($description)) {
                $skipped++;

                return;
            }

            if ($apply) {
                $result = $upserter->execute($companyId, $productId, $description, 'backfill');
                $result !== null ? $written++ : $skipped++;
            } else {
                $written++;
            }
        };

        SupplierQuotationItem::query()
            ->whereNotNull('product_id')
            ->join('supplier_quotations', 'supplier_quotations.id', '=', 'supplier_quotation_items.supplier_quotation_id')
            ->orderBy('supplier_quotation_items.created_at')
            ->orderBy('supplier_quotation_items.id')
            ->get(['supplier_quotation_items.*', 'supplier_quotations.company_id as sq_company_id'])
            ->each(fn ($item) => $process((int) $item->sq_company_id, $item->product_id, $item->description));

        InquiryItem::query()
            ->whereNotNull('product_id')
            ->join('inquiries', 'inquiries.id', '=', 'inquiry_items.inquiry_id')
            ->orderBy('inquiry_items.created_at')
            ->orderBy('inquiry_items.id')
            ->get(['inquiry_items.*', 'inquiries.company_id as inq_company_id'])
            ->each(fn ($item) => $process((int) $item->inq_company_id, $item->product_id, $item->description));

        $this->info(sprintf('%s: %d aliases processados, %d ignorados.', $apply ? 'Gravado' : 'Dry-run', $written, $skipped));

        if (! $apply) {
            $this->warn('Dry-run: nada gravado. Use --apply para persistir.');
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Console/BackfillProductImportAliasesCommandTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(ai-import): backfill command for product import aliases"
```

---

### Task 11: Regressão completa + push

- [ ] **Step 1: Suite de import completa**

Run: `php artisan test --compact tests/Feature/AI/Import/ tests/Unit/AI/ tests/Feature/Console/BackfillProductImportAliasesCommandTest.php`
Expected: PASS total. Qualquer falha: corrigir antes de seguir (não pular).

- [ ] **Step 2: Fluxo Livewire do Assistant (regressão do form)**

Run: `php artisan test --compact tests/Feature/AI/Import/AssistantImportFlowTest.php tests/Feature/AI/Import/AssistantInquiryImportFlowTest.php`
Expected: PASS

- [ ] **Step 3: Pint final + push**

```bash
vendor/bin/pint --dirty --format agent
git push
```

- [ ] **Step 4: Rollout (manual, fora do repo)**

Em dev e depois em prod, após deploy:

```bash
php artisan migrate --no-interaction
php artisan imports:backfill-product-aliases
php artisan imports:backfill-product-aliases --apply
```

Validação final: refazer o import do PDF `0706DeepFitness. new.pdf` no Assistant em dev — os itens devem casar nas camadas 2–3 (alias do backfill da SQ-2026-00111), sem chamada de IA e sem nenhum "novo".
