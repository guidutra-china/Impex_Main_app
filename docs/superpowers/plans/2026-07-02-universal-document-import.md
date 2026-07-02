# Universal Document Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generalize the Assistant's AI file-import pipeline behind an `ImportTarget` contract, add the Inquiry destination (new or existing inquiry), an AI document classifier that suggests the destination after upload, and an "Importar com IA" shortcut in the Inquiries module.

**Architecture:** The shared pipeline (upload → content blocks → classification → forced-tool extraction → editable review → confirm → cleanup) lives in a target-agnostic page trait. Each destination implements `ImportTarget`: extraction schema/prompts, deterministic resolve, form mapping, and a transactional confirm Action. The existing SupplierQuotation code is refactored into the first target with identical behavior; Inquiry is the second target.

**Tech Stack:** Laravel 12, Filament 4 (Livewire 3), `anthropic-ai/sdk`, PHPUnit 11, spatie/laravel-activitylog, brick/money via `App\Domain\Infrastructure\Support\Money`.

**Spec:** `docs/superpowers/specs/2026-07-02-universal-document-import-design.md`

**⚠️ Gui's workflow preference (overrides the skill default):** do **NOT** commit. Leave all changes in the working tree. Where this plan says "Commit", instead run `vendor/bin/pint --dirty --format agent` and move on.

**Conventions used below (locked — later tasks depend on these exact names):**
- Interface: `App\Domain\AI\Import\Targets\ImportTarget`
- Registry: `App\Domain\AI\Import\Targets\ImportTargetRegistry`
- Targets: `SupplierQuotationTarget` (`key = 'supplier_quotation'`), `InquiryTarget` (`key = 'inquiry'`)
- Generic LLM callers: `App\Domain\AI\Import\DraftExtractor`, `App\Domain\AI\Import\DraftEditor`
- Classifier: `App\Domain\AI\Import\DocumentClassifier`
- Inquiry pieces: `InquiryDraftSchema`, `ResolveInquiryDraft` (in `App\Domain\AI\Import`), `App\Domain\Inquiries\Actions\ImportInquiryAction`
- Page trait: `App\Filament\Pages\Concerns\HandlesDocumentImport` (replaces `HandlesSupplierQuotationImport`)
- Draft/preview/form all keep the `itens` key across every target; item "status" badge convention stays `'existente'`/`'novo'` (for Inquiry it means product matched / not matched).
- `confirm()` returns `array{reference:string,count:int}` — the trait builds the success message from it.

---

### Task 1: ImportTarget contract, registry, and SupplierQuotationTarget

The SQ target absorbs: the extraction/edit prompts (from `SupplierQuotationExtractor`/`EditSupplierQuotationDraft`), and `buildForm()`/`formToActionPreview()` (from the trait). Nothing calls the target yet — the old code keeps working until Tasks 2 and 7 swap the call sites.

**Files:**
- Create: `app/Domain/AI/Import/Targets/ImportTarget.php`
- Create: `app/Domain/AI/Import/Targets/ImportTargetRegistry.php`
- Create: `app/Domain/AI/Import/Targets/SupplierQuotationTarget.php`
- Test: `tests/Feature/AI/Import/SupplierQuotationTargetTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\Targets\ImportTargetRegistry;
use App\Domain\AI\Import\Targets\SupplierQuotationTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupplierQuotationTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_resolves_target_by_key(): void
    {
        $registry = new ImportTargetRegistry;

        $this->assertInstanceOf(SupplierQuotationTarget::class, $registry->get('supplier_quotation'));
        $this->assertNull($registry->get('nope'));
        $this->assertArrayHasKey('supplier_quotation', $registry->all());
    }

    public function test_target_metadata_matches_existing_pipeline(): void
    {
        $target = new SupplierQuotationTarget;

        $this->assertSame('supplier_quotation', $target->key());
        $this->assertSame('registrar_cotacao', $target->extractionToolName());
        $this->assertSame('atualizar_cotacao', $target->editToolName());
        $this->assertTrue($target->supportsImages());
        // Schema matches the shared draft schema (categories come from the DB — empty here).
        $this->assertSame('object', $target->extractionSchema()['type']);
        $this->assertArrayHasKey('fornecedor', $target->extractionSchema()['properties']);
    }

    public function test_authorize_mirrors_create_supplier_quotations(): void
    {
        Permission::firstOrCreate(['name' => 'create-supplier-quotations', 'guard_name' => 'web']);
        $target = new SupplierQuotationTarget;

        $denied = User::factory()->create();
        $this->assertFalse($target->authorize($denied));

        $allowed = User::factory()->create();
        $allowed->givePermissionTo('create-supplier-quotations');
        $this->assertTrue($target->authorize($allowed));
    }

    public function test_build_form_and_confirm_payload_round_trip(): void
    {
        $target = new SupplierQuotationTarget;

        $preview = [
            'fornecedor' => ['status' => 'novo', 'company_id' => null, 'nome' => 'ACME'],
            'fornecedor_dados' => ['email' => 'a@b.com'],
            'cabecalho' => ['currency_code' => 'USD', 'incoterm' => 'FOB'],
            'itens' => [[
                'status' => 'novo', 'product_id' => null, 'part_no' => 'P-1',
                'description' => 'Widget', 'quantity' => 2, 'unit' => 'pcs',
                'unit_cost_minor' => 5000, 'line_total_minor' => 10000,
                'category_id' => null, 'category_name' => null,
                'specifications' => null, 'moq' => null, 'lead_time_days' => null, 'notes' => null,
            ]],
        ];

        $form = $target->buildForm($preview, [0 => null]);
        $this->assertSame('ACME', $form['fornecedor']['nome']);
        $this->assertSame('a@b.com', $form['fornecedor']['email']);
        $this->assertSame(0.5, $form['itens'][0]['unit_price']);

        $payload = $target->formToConfirmPayload($form, []);
        $this->assertSame(5000, $payload['preview']['itens'][0]['unit_cost_minor']);
        $this->assertSame('ACME', $payload['preview']['fornecedor']['nome']);
        $this->assertSame(['email' => 'a@b.com'], array_filter($payload['preview']['fornecedor_dados']));
        $this->assertSame([], $payload['images']);
    }
}
```

Note on `unit_price`: `Money::SCALE` is the same constant the current trait divides by (`buildForm()` in `HandlesSupplierQuotationImport.php:188`). If `5000 / Money::SCALE` isn't `0.5` (SCALE = 10000 → 4-decimal minor units), adjust the two asserted numbers to `unit_cost_minor / Money::SCALE` accordingly — check `app/Domain/Infrastructure/Support/Money.php` first.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/AI/Import/SupplierQuotationTargetTest.php`
Expected: FAIL — `Class "App\Domain\AI\Import\Targets\ImportTargetRegistry" not found`

- [ ] **Step 3: Create the interface**

`app/Domain/AI/Import/Targets/ImportTarget.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\AI\Import\Targets;

use App\Models\User;

/**
 * A destination the universal document import can write to (Supplier Quotation,
 * Inquiry, ...). Each target owns its extraction schema/prompts, deterministic
 * resolution, editable-form mapping and the transactional confirm Action. The
 * shared pipeline (upload, classification, extraction call, review UI, cleanup)
 * lives in HandlesDocumentImport and is target-agnostic.
 */
interface ImportTarget
{
    /** Stable identifier used in Livewire state, blade partials and the classifier enum. */
    public function key(): string;

    /** Human label (translated) shown in the target chooser. */
    public function label(): string;

    /** One-line hint that teaches the classifier to recognize this document type. */
    public function classifierHint(): string;

    public function extractionToolName(): string;

    /** @return array<string,mixed> JSON Schema for the extraction tool input. Must produce an `itens` array. */
    public function extractionSchema(): array;

    public function extractionSystemPrompt(): string;

    /** First user text block sent alongside the document content blocks. */
    public function extractionUserPrompt(): string;

    public function editToolName(): string;

    /** May contain the `:language` placeholder — DraftEditor substitutes the user language. */
    public function editSystemPrompt(): string;

    /**
     * Deterministically resolve a draft into the preview model (DB matching,
     * money conversion). No writes, no LLM.
     *
     * @param  array<string,mixed>  $draft
     * @return array<string,mixed>
     */
    public function resolve(array $draft): array;

    /** Whether the pipeline should extract embedded images for this target. */
    public function supportsImages(): bool;

    /** Whether the user may run this import at all (the confirm Action re-checks). */
    public function authorize(User $user): bool;

    /**
     * Build the editable review form from a resolved preview.
     *
     * @param  array<string,mixed>  $preview
     * @param  array<int,?int>  $itemPhoto  item index => image pool id
     * @return array<string,mixed>
     */
    public function buildForm(array $preview, array $itemPhoto): array;

    /**
     * Convert the edited form back into the confirm Action's input shape.
     *
     * @param  array<string,mixed>  $form
     * @param  list<array{id:int,path:string}>  $imagePool
     * @return array{preview:array<string,mixed>,images:array<string,string>}
     */
    public function formToConfirmPayload(array $form, array $imagePool): array;

    /**
     * Commit the reviewed preview (transactional, permission-gated, user-triggered).
     *
     * @param  array<string,mixed>  $preview
     * @param  array<string,string>  $images  item key => absolute temp path
     * @return array{reference:string,count:int}
     */
    public function confirm(array $preview, User $user, string $filePath, array $images): array;
}
```

- [ ] **Step 4: Create the registry**

`app/Domain/AI/Import/Targets/ImportTargetRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\AI\Import\Targets;

use App\Models\User;

/** All destinations the universal import knows about. */
class ImportTargetRegistry
{
    /** @var array<string,ImportTarget> */
    private array $targets = [];

    public function __construct()
    {
        // Task 6 adds `new InquiryTarget` here.
        foreach ([new SupplierQuotationTarget] as $target) {
            $this->targets[$target->key()] = $target;
        }
    }

    /** @return array<string,ImportTarget> */
    public function all(): array
    {
        return $this->targets;
    }

    public function get(string $key): ?ImportTarget
    {
        return $this->targets[$key] ?? null;
    }

    /**
     * Targets this user may import into (chooser options + classifier enum).
     *
     * @return array<string,ImportTarget>
     */
    public function allFor(User $user): array
    {
        return array_filter($this->targets, fn (ImportTarget $t) => $t->authorize($user));
    }
}
```

- [ ] **Step 5: Create SupplierQuotationTarget**

The prompt strings are **moved verbatim** from `SupplierQuotationExtractor::callModel()` (system + user prompt) and `EditSupplierQuotationDraft::systemPrompt()` (with `{$language}` replaced by the `:language` placeholder). `buildForm`/`formToConfirmPayload` are **moved** from the trait (`buildForm()` / `formToActionPreview()`) with the image pool passed as a parameter instead of read from `$this->importImagePool`.

`app/Domain/AI/Import/Targets/SupplierQuotationTarget.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\AI\Import\Targets;

use App\Domain\AI\Import\ResolveSupplierQuotationDraft;
use App\Domain\AI\Import\SupplierQuotationDraftSchema;
use App\Domain\Catalog\Models\Category;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\SupplierQuotations\Actions\ImportSupplierQuotationAction;
use App\Models\User;

class SupplierQuotationTarget implements ImportTarget
{
    public function key(): string
    {
        return 'supplier_quotation';
    }

    public function label(): string
    {
        return __('assistant.target_supplier_quotation');
    }

    public function classifierHint(): string
    {
        return 'Cotação/orçamento RECEBIDO DE UM FORNECEDOR: lista de produtos com preços '
            .'oferecidos pelo fornecedor, geralmente com MOQ, lead time, incoterm, validade, '
            .'dados bancários ou condições de pagamento do fornecedor.';
    }

    public function extractionToolName(): string
    {
        return 'registrar_cotacao';
    }

    public function extractionSchema(): array
    {
        return SupplierQuotationDraftSchema::schema($this->categoryNames());
    }

    public function extractionUserPrompt(): string
    {
        return 'Extraia a cotação deste fornecedor do documento a seguir. '
            .'Use a ferramenta registrar_cotacao. Não invente dados ausentes — omita campos que não constam.';
    }

    public function extractionSystemPrompt(): string
    {
        $categoryNames = $this->categoryNames();

        return 'Você extrai cotações de fornecedores de planilhas e PDFs para uma trading company. '
            .'Valores na moeda do documento, como números decimais. Datas em YYYY-MM-DD. '
            .'Para CADA item capture quantity, unit_price (preço unitário como aparece) E line_total '
            .'(o valor TOTAL da linha, a coluna "Amount"/"Total"), quando existir — o line_total é o valor '
            .'confiável quando o preço unitário está por kg ou não multiplica direto pela quantidade. '
            .'Capture o total geral do documento em documento_total. '
            .'Linhas que NÃO são produtos (taxas, frete, customization fee, descontos) vão em "extras" '
            .'com descricao e valor (use valor NEGATIVO para descontos). Não as inclua como itens. '
            .($categoryNames !== []
                ? 'Para cada item, atribua "categoria" escolhendo APENAS uma das categorias existentes fornecidas; '
                    .'se nenhuma for claramente adequada, omita a categoria (não invente).'
                : '')
            .' Capture também os dados de contato do fornecedor quando constarem no documento '
            .'(legal_name, tax_number, phone, email, website e endereço).';
    }

    public function editToolName(): string
    {
        return 'atualizar_cotacao';
    }

    public function editSystemPrompt(): string
    {
        return <<<'PROMPT'
        You edit an already-extracted supplier quotation for a Brazil–China trading company,
        based on the user's instruction. The data is being reviewed before import.

        Rules:
        - Return the FULL updated quotation (keep unchanged fields as-is). You may add, remove or
          merge items and change supplier fields when asked.
        - Keep prices in the document currency as decimal numbers; line_total is the line amount.
        - If a category is provided, choose only from the existing category list; otherwise omit it.
        - If the user only asks a QUESTION about the quotation, answer it in `reply` and return the
          draft unchanged.
        - Write `reply` as a short message in :language. Never invent data.
        - Supplier contact fields (phone, email, address, etc.) may be edited when asked.
        PROMPT;
    }

    public function resolve(array $draft): array
    {
        return app(ResolveSupplierQuotationDraft::class)->resolve($draft);
    }

    public function supportsImages(): bool
    {
        return true;
    }

    public function authorize(User $user): bool
    {
        return $user->can('create-supplier-quotations');
    }

    public function buildForm(array $preview, array $itemPhoto): array
    {
        $itens = [];
        foreach (array_values($preview['itens']) as $i => $it) {
            $itens[] = [
                'description' => $it['description'] ?? '',
                'quantity' => $it['quantity'] ?? 0,
                'unit' => $it['unit'] ?? 'pcs',
                'unit_price' => round(((int) ($it['unit_cost_minor'] ?? 0)) / Money::SCALE, 2),
                'category_id' => $it['category_id'] ?? null,
                'part_no' => $it['part_no'] ?? null,
                'status' => $it['status'] ?? 'novo',
                'product_id' => $it['product_id'] ?? null,
                'specifications' => $it['specifications'] ?? null,
                'moq' => $it['moq'] ?? null,
                'lead_time_days' => $it['lead_time_days'] ?? null,
                'notes' => $it['notes'] ?? null,
                'photo_index' => $itemPhoto[$i] ?? null,
            ];
        }

        return [
            'fornecedor' => array_merge(
                [
                    'nome' => $preview['fornecedor']['nome'] ?? '',
                    'status' => $preview['fornecedor']['status'] ?? 'novo',
                    'company_id' => $preview['fornecedor']['company_id'] ?? null,
                ],
                $preview['fornecedor_dados'] ?? [],
            ),
            'cabecalho' => $preview['cabecalho'] ?? [],
            'itens' => $itens,
        ];
    }

    public function formToConfirmPayload(array $form, array $imagePool): array
    {
        $contactKeys = ['legal_name', 'tax_number', 'phone', 'email', 'website', 'address_street', 'address_number', 'address_complement', 'address_city', 'address_state', 'address_zip', 'address_country'];

        $itens = [];
        $images = [];
        foreach (array_values($form['itens'] ?? []) as $i => $it) {
            $partNo = trim((string) ($it['part_no'] ?? ''));
            $key = $partNo !== '' ? $partNo : 'idx:'.$i;

            $itens[] = [
                'status' => $it['status'] ?? 'novo',
                'product_id' => $it['product_id'] ?? null,
                'part_no' => $partNo !== '' ? $partNo : null,
                'description' => (string) ($it['description'] ?? ''),
                'quantity' => (int) ($it['quantity'] ?? 0),
                'unit' => $it['unit'] ?? null,
                'unit_cost_minor' => Money::toMinor($it['unit_price'] ?? 0),
                'category_id' => $it['category_id'] ?? null,
                'specifications' => $it['specifications'] ?? null,
                'moq' => $it['moq'] ?? null,
                'lead_time_days' => $it['lead_time_days'] ?? null,
                'notes' => $it['notes'] ?? null,
            ];

            $idx = $it['photo_index'] ?? null;
            if ($idx !== null && isset($imagePool[$idx])) {
                $images[$key] = $imagePool[$idx]['path'];
            }
        }

        $f = $form['fornecedor'] ?? [];

        return [
            'preview' => [
                'fornecedor' => ['status' => $f['status'] ?? 'novo', 'company_id' => $f['company_id'] ?? null, 'nome' => $f['nome'] ?? ''],
                'fornecedor_dados' => array_intersect_key($f, array_flip($contactKeys)),
                'cabecalho' => $form['cabecalho'] ?? [],
                'itens' => $itens,
            ],
            'images' => $images,
        ];
    }

    public function confirm(array $preview, User $user, string $filePath, array $images): array
    {
        $sq = app(ImportSupplierQuotationAction::class)($preview, $user, $filePath, $images);

        return ['reference' => (string) $sq->reference, 'count' => count($preview['itens'])];
    }

    /**
     * Active category names the extractor may assign items to (no inventing).
     *
     * @return list<string>
     */
    private function categoryNames(): array
    {
        return Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/AI/Import/SupplierQuotationTargetTest.php`
Expected: PASS (4 tests)

- [ ] **Step 7: Pint**

Run: `vendor/bin/pint --dirty --format agent`

---

### Task 2: Generic DraftExtractor + DraftEditor (retire the SQ-specific classes)

`DraftExtractor`/`DraftEditor` take an `ImportTarget` and replace `SupplierQuotationExtractor`/`EditSupplierQuotationDraft`. Update the trait call sites and the three affected test files, then delete the old classes.

**Files:**
- Create: `app/Domain/AI/Import/DraftExtractor.php`
- Create: `app/Domain/AI/Import/DraftEditor.php`
- Delete: `app/Domain/AI/Import/SupplierQuotationExtractor.php`
- Delete: `app/Domain/AI/Import/EditSupplierQuotationDraft.php`
- Modify: `app/Filament/Pages/Concerns/HandlesSupplierQuotationImport.php` (call sites only)
- Rename+rewrite test: `tests/Feature/AI/Import/SupplierQuotationExtractorTest.php` → `tests/Feature/AI/Import/DraftExtractorTest.php`
- Rename+rewrite test: `tests/Feature/AI/Import/EditSupplierQuotationDraftTest.php` → `tests/Feature/AI/Import/DraftEditorTest.php`
- Modify: `tests/Feature/AI/Import/AssistantImportFlowTest.php` (swap fakes)

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AI/Import/DraftExtractorTest.php` (replaces `SupplierQuotationExtractorTest.php` — delete that file):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use Anthropic\Messages\TextBlockParam;
use App\Domain\AI\Import\DraftExtractor;
use App\Domain\AI\Import\Exceptions\ExtractionFailedException;
use App\Domain\AI\Import\Targets\ImportTarget;
use App\Domain\AI\Import\Targets\SupplierQuotationTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftExtractorTest extends TestCase
{
    use RefreshDatabase;

    private function extractorReturning(array $blocks): DraftExtractor
    {
        return new class($blocks) extends DraftExtractor
        {
            public function __construct(private readonly array $blocks) {}

            protected function callModel(ImportTarget $target, array $content): object
            {
                return (object) ['content' => $this->blocks];
            }
        };
    }

    public function test_returns_draft_from_forced_tool_call(): void
    {
        $extractor = $this->extractorReturning([
            (object) [
                'type' => 'tool_use',
                'name' => 'registrar_cotacao',
                'input' => [
                    'fornecedor' => ['nome' => 'Nanjing Gencrea'],
                    'itens' => [
                        ['part_no' => 'AH223014', 'description' => 'Chaffer arm', 'quantity' => 6, 'unit_price' => 100.0],
                    ],
                ],
            ],
        ]);

        $draft = $extractor->extract(new SupplierQuotationTarget, [TextBlockParam::with('planilha...')]);

        $this->assertSame('Nanjing Gencrea', $draft['fornecedor']['nome']);
        $this->assertCount(1, $draft['itens']);
    }

    public function test_throws_when_no_items(): void
    {
        $extractor = $this->extractorReturning([
            (object) ['type' => 'tool_use', 'name' => 'registrar_cotacao', 'input' => ['fornecedor' => ['nome' => 'X'], 'itens' => []]],
        ]);

        $this->expectException(ExtractionFailedException::class);
        $extractor->extract(new SupplierQuotationTarget, [TextBlockParam::with('planilha...')]);
    }

    public function test_throws_when_no_tool_call(): void
    {
        $extractor = $this->extractorReturning([(object) ['type' => 'text', 'text' => 'oi']]);

        $this->expectException(ExtractionFailedException::class);
        $extractor->extract(new SupplierQuotationTarget, [TextBlockParam::with('planilha...')]);
    }
}
```

`tests/Feature/AI/Import/DraftEditorTest.php` (replaces `EditSupplierQuotationDraftTest.php` — port its existing assertions to the new signature; the canonical shape):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\DraftEditor;
use App\Domain\AI\Import\Targets\ImportTarget;
use App\Domain\AI\Import\Targets\SupplierQuotationTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_updated_draft_and_reply(): void
    {
        $editor = new class extends DraftEditor
        {
            public function __construct() {}

            protected function callModel(ImportTarget $target, array $content): object
            {
                return (object) ['content' => [
                    (object) [
                        'type' => 'tool_use',
                        'name' => 'atualizar_cotacao',
                        'input' => [
                            'fornecedor' => ['nome' => 'ACME'],
                            'itens' => [['description' => 'Item', 'quantity' => 5, 'unit_price' => 1.0]],
                            'reply' => 'Quantidade ajustada.',
                        ],
                    ],
                ]];
            }
        };

        $result = $editor->edit(
            new SupplierQuotationTarget,
            ['fornecedor' => ['nome' => 'ACME'], 'itens' => [['description' => 'Item', 'quantity' => 2, 'unit_price' => 1.0]]],
            'mude a quantidade para 5',
        );

        $this->assertSame(5, $result['draft']['itens'][0]['quantity']);
        $this->assertSame('Quantidade ajustada.', $result['reply']);
        $this->assertArrayNotHasKey('reply', $result['draft']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/AI/Import/DraftExtractorTest.php tests/Feature/AI/Import/DraftEditorTest.php`
Expected: FAIL — `Class "App\Domain\AI\Import\DraftExtractor" not found`

- [ ] **Step 3: Create DraftExtractor**

`app/Domain/AI/Import/DraftExtractor.php`:

```php
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
 * blocks via a single forced-tool call (structured output). Target-agnostic:
 * schema, prompts and tool name come from the ImportTarget. The model never
 * writes anything.
 */
class DraftExtractor
{
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
        $content = array_merge(
            [TextBlockParam::with($target->extractionUserPrompt())],
            $documentBlocks,
        );

        $response = $this->callModel($target, $content);

        foreach ($response->content as $block) {
            if (($block->type ?? null) === 'tool_use' && ($block->name ?? null) === $target->extractionToolName()) {
                $draft = (array) $block->input;

                if (empty($draft['itens'] ?? [])) {
                    throw new ExtractionFailedException('Nenhum item encontrado no documento.');
                }

                return $draft;
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
```

- [ ] **Step 4: Create DraftEditor**

`app/Domain/AI/Import/DraftEditor.php`:

```php
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
 * Conversationally adjusts an already-extracted draft based on a natural-language
 * instruction, returning the full updated draft plus a short reply. Operates only
 * on the in-memory draft (preview-before-confirm) — never writes to the DB.
 */
class DraftEditor
{
    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(apiKey: (string) config('services.anthropic.key'));
    }

    /**
     * @param  array<string,mixed>  $draft  the current extracted draft
     * @return array{draft:array<string,mixed>,reply:string}
     */
    public function edit(ImportTarget $target, array $draft, string $instruction): array
    {
        $content = [TextBlockParam::with(
            "Dados atuais (JSON):\n"
            .json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\nInstrução do usuário: ".$instruction
        )];

        $response = $this->callModel($target, $content);

        foreach ($response->content as $block) {
            if (($block->type ?? null) === 'tool_use' && ($block->name ?? null) === $target->editToolName()) {
                $input = (array) $block->input;
                $reply = (string) ($input['reply'] ?? '');
                unset($input['reply']);

                return ['draft' => $input, 'reply' => $reply];
            }
        }

        throw new ExtractionFailedException('O modelo não retornou os dados atualizados.');
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
            system: str_replace(':language', $this->userLanguage(), $target->editSystemPrompt()),
            tools: [Tool::with(
                inputSchema: $this->editSchema($target),
                name: $target->editToolName(),
                description: 'Retorna os dados COMPLETOS atualizados conforme a instrução, mais uma resposta curta ao usuário.',
            )],
            toolChoice: ToolChoiceTool::with(name: $target->editToolName()),
        );
    }

    /**
     * The target's draft schema plus a required `reply` field.
     *
     * @return array<string,mixed>
     */
    private function editSchema(ImportTarget $target): array
    {
        $schema = $target->extractionSchema();
        $schema['properties']['reply'] = ['type' => 'string', 'description' => 'Resposta curta ao usuário, no idioma dele.'];
        $schema['required'][] = 'reply';

        return $schema;
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
```

- [ ] **Step 5: Swap call sites in the trait**

In `app/Filament/Pages/Concerns/HandlesSupplierQuotationImport.php`:

1. Replace the two imports `use App\Domain\AI\Import\EditSupplierQuotationDraft;` and `use App\Domain\AI\Import\SupplierQuotationExtractor;` with:
```php
use App\Domain\AI\Import\DraftEditor;
use App\Domain\AI\Import\DraftExtractor;
use App\Domain\AI\Import\Targets\SupplierQuotationTarget;
```
2. In `submitImport()`, replace:
```php
$draft = app(SupplierQuotationExtractor::class)->extract($blocks, $this->importCategoryNames());
```
with:
```php
$draft = app(DraftExtractor::class)->extract(new SupplierQuotationTarget, $blocks);
```
3. In `editImport()`, replace:
```php
$result = app(EditSupplierQuotationDraft::class)->edit(
    $this->importDraft,
    $instruction,
    $this->importCategoryNames(),
);
```
with:
```php
$result = app(DraftEditor::class)->edit(new SupplierQuotationTarget, $this->importDraft, $instruction);
```
4. Delete the now-unused `importCategoryNames()` method from the trait (the target owns it). Keep `importCategoryOptions()` — the blade uses it.

- [ ] **Step 6: Update AssistantImportFlowTest fakes and delete old classes/tests**

In `tests/Feature/AI/Import/AssistantImportFlowTest.php`, every fake binding of the form:

```php
$this->app->bind(SupplierQuotationExtractor::class, fn () => new class extends SupplierQuotationExtractor
{
    public function __construct() {}

    public function extract(array $documentBlocks, array $categoryNames = []): array
    {
        return [/* draft */];
    }
});
```

becomes:

```php
$this->app->bind(DraftExtractor::class, fn () => new class extends DraftExtractor
{
    public function __construct() {}

    public function extract(\App\Domain\AI\Import\Targets\ImportTarget $target, array $documentBlocks): array
    {
        return [/* same draft */];
    }
});
```

and every `EditSupplierQuotationDraft` fake becomes a `DraftEditor` fake with signature `public function edit(\App\Domain\AI\Import\Targets\ImportTarget $target, array $draft, string $instruction): array`. Update the `use` statements at the top accordingly. Do this for **all** occurrences in the file (there are three test methods with fakes).

Then delete:
- `app/Domain/AI/Import/SupplierQuotationExtractor.php`
- `app/Domain/AI/Import/EditSupplierQuotationDraft.php`
- `tests/Feature/AI/Import/SupplierQuotationExtractorTest.php`
- `tests/Feature/AI/Import/EditSupplierQuotationDraftTest.php`

Check nothing else references the deleted classes: `grep -rn "SupplierQuotationExtractor\|EditSupplierQuotationDraft" app/ tests/` must return nothing.

- [ ] **Step 7: Run the whole AI import suite**

Run: `php artisan test --compact tests/Feature/AI/Import/`
Expected: PASS (all files, including the untouched Resolve/Action/DocumentExtractor tests)

- [ ] **Step 8: Pint**

Run: `vendor/bin/pint --dirty --format agent`

---

### Task 3: DocumentClassifier

Cheap forced-tool call (Haiku) that suggests the target after upload. Failure never blocks — callers fall back to `desconhecido`.

**Files:**
- Create: `app/Domain/AI/Import/DocumentClassifier.php`
- Test: `tests/Feature/AI/Import/DocumentClassifierTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use Anthropic\Messages\TextBlockParam;
use App\Domain\AI\Import\DocumentClassifier;
use App\Domain\AI\Import\Targets\SupplierQuotationTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentClassifierTest extends TestCase
{
    use RefreshDatabase;

    private function classifierReturning(array $blocks): DocumentClassifier
    {
        return new class($blocks) extends DocumentClassifier
        {
            public function __construct(private readonly array $blocks) {}

            protected function callModel(array $content, array $keys, string $hints): object
            {
                return (object) ['content' => $this->blocks];
            }
        };
    }

    public function test_returns_suggestion_from_tool_call(): void
    {
        $classifier = $this->classifierReturning([
            (object) [
                'type' => 'tool_use',
                'name' => 'classificar_documento',
                'input' => ['tipo' => 'supplier_quotation', 'confianca' => 'alta', 'motivo' => 'Preços de fornecedor com MOQ.'],
            ],
        ]);

        $result = $classifier->classify(
            [TextBlockParam::with('doc...')],
            ['supplier_quotation' => new SupplierQuotationTarget],
        );

        $this->assertSame('supplier_quotation', $result['tipo']);
        $this->assertSame('alta', $result['confianca']);
    }

    public function test_unknown_type_from_model_is_normalized_to_desconhecido(): void
    {
        $classifier = $this->classifierReturning([
            (object) [
                'type' => 'tool_use',
                'name' => 'classificar_documento',
                'input' => ['tipo' => 'invoice', 'confianca' => 'alta'],
            ],
        ]);

        $result = $classifier->classify([TextBlockParam::with('doc...')], ['supplier_quotation' => new SupplierQuotationTarget]);

        $this->assertSame('desconhecido', $result['tipo']);
    }

    public function test_missing_tool_call_falls_back_to_desconhecido(): void
    {
        $classifier = $this->classifierReturning([(object) ['type' => 'text', 'text' => 'hm']]);

        $result = $classifier->classify([TextBlockParam::with('doc...')], ['supplier_quotation' => new SupplierQuotationTarget]);

        $this->assertSame('desconhecido', $result['tipo']);
        $this->assertSame('baixa', $result['confianca']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/AI/Import/DocumentClassifierTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement**

`app/Domain/AI/Import/DocumentClassifier.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use Anthropic\Client;
use Anthropic\Messages\TextBlockParam;
use Anthropic\Messages\Tool;
use Anthropic\Messages\ToolChoiceTool;
use App\Domain\AI\Import\Targets\ImportTarget;

/**
 * Cheap single-call document classification: suggests which import target the
 * uploaded document belongs to. Runs on the lightweight model — the expensive
 * per-target extraction only happens after the user confirms the destination.
 * Best-effort: any uncertainty resolves to 'desconhecido'; never blocks the flow.
 */
class DocumentClassifier
{
    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(apiKey: (string) config('services.anthropic.key'));
    }

    /**
     * @param  list<object>  $documentBlocks
     * @param  array<string,ImportTarget>  $targets  targets the user may import into
     * @return array{tipo:string,confianca:string,motivo:string}
     */
    public function classify(array $documentBlocks, array $targets): array
    {
        $keys = array_keys($targets);
        $hints = implode("\n", array_map(
            fn (ImportTarget $t) => "- {$t->key()}: {$t->classifierHint()}",
            array_values($targets),
        ));

        $content = array_merge(
            [TextBlockParam::with('Classifique o tipo deste documento usando a ferramenta classificar_documento.')],
            $documentBlocks,
        );

        $response = $this->callModel($content, $keys, $hints);

        foreach ($response->content as $block) {
            if (($block->type ?? null) === 'tool_use' && ($block->name ?? null) === 'classificar_documento') {
                $input = (array) $block->input;
                $tipo = (string) ($input['tipo'] ?? 'desconhecido');

                return [
                    'tipo' => in_array($tipo, [...$keys, 'desconhecido'], true) ? $tipo : 'desconhecido',
                    'confianca' => (string) ($input['confianca'] ?? 'baixa'),
                    'motivo' => (string) ($input['motivo'] ?? ''),
                ];
            }
        }

        return ['tipo' => 'desconhecido', 'confianca' => 'baixa', 'motivo' => ''];
    }

    /**
     * Single forced-tool round-trip. Seam for tests (override to avoid the API).
     *
     * @param  list<object>  $content
     * @param  list<string>  $keys
     */
    protected function callModel(array $content, array $keys, string $hints): object
    {
        return $this->client->messages->create(
            maxTokens: 1024,
            messages: [['role' => 'user', 'content' => $content]],
            model: (string) config('services.anthropic.model', 'claude-haiku-4-5-20251001'),
            system: "Você classifica documentos comerciais de uma trading company Brasil–China.\n"
                ."Tipos possíveis:\n{$hints}\n"
                ."Se não tiver certeza razoável, use 'desconhecido'. Responda o motivo no idioma do usuário.",
            tools: [Tool::with(
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'tipo' => ['type' => 'string', 'enum' => [...$keys, 'desconhecido']],
                        'confianca' => ['type' => 'string', 'enum' => ['alta', 'media', 'baixa']],
                        'motivo' => ['type' => 'string', 'description' => 'Justificativa curta.'],
                    ],
                    'required' => ['tipo', 'confianca'],
                ],
                name: 'classificar_documento',
                description: 'Registra o tipo identificado do documento.',
            )],
            toolChoice: ToolChoiceTool::with(name: 'classificar_documento'),
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/AI/Import/DocumentClassifierTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Pint**

Run: `vendor/bin/pint --dirty --format agent`

---

### Task 4: InquiryDraftSchema + ResolveInquiryDraft

**Files:**
- Create: `app/Domain/AI/Import/InquiryDraftSchema.php`
- Create: `app/Domain/AI/Import/ResolveInquiryDraft.php`
- Test: `tests/Feature/AI/Import/ResolveInquiryDraftTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\ResolveInquiryDraft;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveInquiryDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_existing_client_and_product(): void
    {
        $client = Company::factory()->create(['name' => 'DeepFitness Ltda']);
        $product = Product::factory()->create(['reference_code' => 'DF-100']);

        $preview = app(ResolveInquiryDraft::class)->resolve([
            'cliente' => ['nome' => 'DeepFitness', 'currency_code' => 'usd'],
            'itens' => [
                ['part_no' => 'DF-100', 'description' => 'Treadmill', 'quantity' => 3, 'target_price' => 250.5],
                ['description' => 'Unknown thing', 'quantity' => 1],
            ],
        ]);

        $this->assertSame('existente', $preview['cliente']['status']);
        $this->assertSame($client->id, $preview['cliente']['company_id']);
        $this->assertSame('USD', $preview['cabecalho']['currency_code']);

        $this->assertSame('existente', $preview['itens'][0]['status']);
        $this->assertSame($product->id, $preview['itens'][0]['product_id']);
        $this->assertSame(\App\Domain\Infrastructure\Support\Money::toMinor(250.5), $preview['itens'][0]['target_price_minor']);

        $this->assertSame('novo', $preview['itens'][1]['status']);
        $this->assertNull($preview['itens'][1]['product_id']);
        $this->assertNull($preview['itens'][1]['target_price_minor']);

        $this->assertSame(2, $preview['resumo']['total_itens']);
        $this->assertSame(1, $preview['resumo']['produtos_casados']);
    }

    public function test_unknown_client_is_marked_new(): void
    {
        $preview = app(ResolveInquiryDraft::class)->resolve([
            'cliente' => ['nome' => 'Cliente Novo SA'],
            'itens' => [['description' => 'Item', 'quantity' => 2]],
        ]);

        $this->assertSame('novo', $preview['cliente']['status']);
        $this->assertNull($preview['cliente']['company_id']);
        $this->assertSame('Cliente Novo SA', $preview['cliente']['nome']);
        $this->assertSame('USD', $preview['cabecalho']['currency_code']);
    }
}
```

Check factory availability first: `Company::factory()` and `Product::factory()` are already used by `tests/Feature/AI/Import/ResolveSupplierQuotationDraftTest.php` — mirror whatever setup that file does (e.g., required attributes) if the bare `create()` calls above fail.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/AI/Import/ResolveInquiryDraftTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Create InquiryDraftSchema**

`app/Domain/AI/Import/InquiryDraftSchema.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

/**
 * JSON Schema for the structured client-inquiry draft. Shared by the extractor
 * (initial extraction) and the editor (conversational adjustments).
 */
class InquiryDraftSchema
{
    /** @return array<string,mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cliente' => [
                    'type' => 'object',
                    'properties' => [
                        'nome' => ['type' => 'string', 'description' => 'Nome do cliente. Use string vazia se o documento não identificar o cliente.'],
                        'contato' => ['type' => 'string', 'description' => 'Nome da pessoa de contato, se constar.'],
                        'currency_code' => ['type' => 'string', 'description' => 'ISO, ex: USD, BRL.'],
                        'deadline' => ['type' => 'string', 'description' => 'Prazo de resposta YYYY-MM-DD, se constar.'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['nome'],
                ],
                'itens' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'part_no' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'quantity' => ['type' => 'integer'],
                            'unit' => ['type' => 'string'],
                            'target_price' => ['type' => 'number', 'description' => 'Preço-alvo unitário do cliente, quando constar.'],
                            'specifications' => ['type' => 'string'],
                            'notes' => ['type' => 'string'],
                            'source_row' => ['type' => 'integer', 'description' => 'Número da linha de origem na planilha (campo "Linha N:"), quando houver.'],
                        ],
                        'required' => ['description', 'quantity'],
                    ],
                ],
            ],
            'required' => ['cliente', 'itens'],
        ];
    }
}
```

- [ ] **Step 4: Create ResolveInquiryDraft**

`app/Domain/AI/Import/ResolveInquiryDraft.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;

/**
 * Deterministically resolves an extracted inquiry draft into a preview model:
 * matches the client company by name and each product by reference_code/
 * model_number (match-only — inquiry items never create products; unmatched
 * items keep product_id null with the description, mirroring the deterministic
 * Excel import). No writes, no LLM.
 */
class ResolveInquiryDraft
{
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
        $itens = array_map(fn (array $item) => $this->resolveItem($item), $draft['itens'] ?? []);

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
                'total_estimado' => $totalMinor > 0 ? $currency.' '.Money::format($totalMinor) : null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function resolveItem(array $item): array
    {
        $partNo = trim((string) ($item['part_no'] ?? ''));
        $product = $partNo !== ''
            ? Product::where('reference_code', $partNo)->orWhere('model_number', $partNo)->first()
            : null;

        $targetPrice = $item['target_price'] ?? null;

        return [
            // 'existente' = matched an existing product; 'novo' = no match (imports with product_id null).
            'status' => $product ? 'existente' : 'novo',
            'product_id' => $product?->id,
            'part_no' => $partNo !== '' ? $partNo : null,
            'description' => (string) ($item['description'] ?? ''),
            'quantity' => (int) ($item['quantity'] ?? 0),
            'unit' => trim((string) ($item['unit'] ?? '')) ?: 'pcs',
            'target_price_minor' => ($targetPrice !== null && $targetPrice !== '') ? Money::toMinor($targetPrice) : null,
            'specifications' => $item['specifications'] ?? null,
            'notes' => $item['notes'] ?? null,
        ];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/AI/Import/ResolveInquiryDraftTest.php`
Expected: PASS (2 tests)

- [ ] **Step 6: Pint**

Run: `vendor/bin/pint --dirty --format agent`

---

### Task 5: ImportInquiryAction

Transactional write for both modes. Mirrors `ImportSupplierQuotationAction` structure (authorize → transaction → items → attach file → activity log).

**Files:**
- Create: `app/Domain/Inquiries/Actions/ImportInquiryAction.php`
- Test: `tests/Feature/AI/Import/ImportInquiryActionTest.php`
- Modify: `lang/en/assistant.php`, `lang/pt_BR/assistant.php`, `lang/zh_CN/assistant.php` (error/permission strings used by the Action)

- [ ] **Step 1: Add the lang keys the Action needs**

Append to the returned array in `lang/en/assistant.php`:

```php
    // Universal import — inquiry target
    'perm_inquiries_create' => 'You do not have permission to create inquiries.',
    'perm_inquiries_edit' => 'You do not have permission to edit inquiries.',
    'inquiry_not_open' => 'This inquiry is not open (received/quoting) — items cannot be added to it.',
    'client_required' => 'Inform the client to create a new inquiry.',
```

`lang/pt_BR/assistant.php`:

```php
    // Import universal — destino inquiry
    'perm_inquiries_create' => 'Você não tem permissão para criar inquiries.',
    'perm_inquiries_edit' => 'Você não tem permissão para editar inquiries.',
    'inquiry_not_open' => 'Esta inquiry não está aberta (recebida/cotando) — não é possível adicionar itens.',
    'client_required' => 'Informe o cliente para criar uma inquiry nova.',
```

`lang/zh_CN/assistant.php`:

```php
    // 通用导入 — 询价单目标
    'perm_inquiries_create' => '您没有创建询价单的权限。',
    'perm_inquiries_edit' => '您没有编辑询价单的权限。',
    'inquiry_not_open' => '该询价单未处于打开状态（已接收/报价中），无法添加项目。',
    'client_required' => '请填写客户以创建新询价单。',
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Actions\ImportInquiryAction;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ImportInquiryActionTest extends TestCase
{
    use RefreshDatabase;

    private string $tempFile;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['create-inquiries', 'edit-inquiries', 'create-companies'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Storage::fake('local');
        $this->tempFile = tempnam(sys_get_temp_dir(), 'inq').'.xlsx';
        file_put_contents($this->tempFile, 'fake-bytes');
    }

    private function previewNova(array $overrides = []): array
    {
        return array_merge([
            'modo' => 'nova',
            'inquiry_id' => null,
            'cliente' => ['status' => 'novo', 'company_id' => null, 'nome' => 'Cliente Novo SA'],
            'cabecalho' => ['currency_code' => 'USD', 'deadline' => null, 'notes' => null],
            'itens' => [
                ['status' => 'novo', 'product_id' => null, 'part_no' => null, 'description' => 'Widget', 'quantity' => 2, 'unit' => 'pcs', 'target_price_minor' => 5000, 'specifications' => null, 'notes' => null],
            ],
        ], $overrides);
    }

    public function test_new_mode_creates_client_inquiry_and_items(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-inquiries', 'create-companies']);

        $inquiry = app(ImportInquiryAction::class)($this->previewNova(), $user, $this->tempFile);

        $this->assertDatabaseHas('companies', ['name' => 'Cliente Novo SA']);
        $client = Company::where('name', 'Cliente Novo SA')->first();
        $this->assertTrue($client->companyRoles()->where('role', CompanyRole::CLIENT->value)->exists());

        $this->assertSame($client->id, $inquiry->company_id);
        $this->assertSame(InquiryStatus::RECEIVED, $inquiry->status);
        $this->assertSame(1, $inquiry->items()->count());
        $this->assertSame(5000, (int) $inquiry->items()->first()->target_price);

        // Source file attached.
        $this->assertSame(1, $inquiry->documents()->where('type', 'inquiry_source')->count());
    }

    public function test_new_mode_requires_create_inquiries_permission(): void
    {
        $user = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        app(ImportInquiryAction::class)($this->previewNova(), $user, $this->tempFile);
    }

    public function test_new_mode_with_new_client_requires_create_companies(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('create-inquiries');

        $this->expectException(AuthorizationException::class);
        app(ImportInquiryAction::class)($this->previewNova(), $user, $this->tempFile);
    }

    public function test_existing_mode_appends_items_with_continued_sort_order(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('edit-inquiries');

        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::RECEIVED]);
        InquiryItem::create(['inquiry_id' => $inquiry->id, 'description' => 'Old', 'quantity' => 1, 'unit' => 'pcs', 'sort_order' => 4]);

        $result = app(ImportInquiryAction::class)(
            $this->previewNova(['modo' => 'existente', 'inquiry_id' => $inquiry->id]),
            $user,
            $this->tempFile,
        );

        $this->assertSame($inquiry->id, $result->id);
        $this->assertSame(2, $inquiry->items()->count());
        $this->assertSame(5, (int) $inquiry->items()->orderByDesc('sort_order')->first()->sort_order);
    }

    public function test_existing_mode_rejects_closed_inquiry(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('edit-inquiries');
        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::WON]);

        $this->expectException(\InvalidArgumentException::class);
        app(ImportInquiryAction::class)(
            $this->previewNova(['modo' => 'existente', 'inquiry_id' => $inquiry->id]),
            $user,
            $this->tempFile,
        );
    }

    public function test_new_mode_without_client_name_is_rejected(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-inquiries', 'create-companies']);

        $this->expectException(\InvalidArgumentException::class);
        app(ImportInquiryAction::class)(
            $this->previewNova(['cliente' => ['status' => 'novo', 'company_id' => null, 'nome' => '']]),
            $user,
            $this->tempFile,
        );
    }
}
```

Note: `Inquiry::factory()` exists (`Database\Factories\InquiryFactory`, wired via `Inquiry::newFactory()`). If the factory requires a company, it creates one itself — check `database/factories/InquiryFactory.php` if `create()` fails and satisfy required fields.

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/AI/Import/ImportInquiryActionTest.php`
Expected: FAIL — `Class "App\Domain\Inquiries\Actions\ImportInquiryAction" not found`

- [ ] **Step 4: Implement the Action**

`app/Domain/Inquiries/Actions/ImportInquiryAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Inquiries\Actions;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Enums\DocumentSourceType;
use App\Domain\Inquiries\Enums\InquirySource;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Commits a resolved inquiry preview: creates a new Inquiry (finding/creating the
 * client) or appends items to an open existing one, and attaches the source file.
 * Products are only linked, never created — unmatched items keep product_id null,
 * mirroring the deterministic Excel import. Deterministic, transactional,
 * permission-gated — triggered by the user's explicit confirmation, never by the model.
 */
class ImportInquiryAction
{
    /**
     * @param  array<string,mixed>  $preview  output of InquiryTarget::formToConfirmPayload()
     */
    public function __invoke(array $preview, User $user, string $filePath): Inquiry
    {
        $this->authorize($preview, $user);

        return DB::transaction(function () use ($preview, $user, $filePath) {
            $inquiry = ($preview['modo'] ?? 'nova') === 'existente'
                ? $this->existingInquiry($preview)
                : $this->createInquiry($preview, $user);

            $nextOrder = $inquiry->items()->exists()
                ? ((int) $inquiry->items()->max('sort_order')) + 1
                : 0;

            foreach (array_values($preview['itens']) as $index => $item) {
                InquiryItem::create([
                    'inquiry_id' => $inquiry->id,
                    'product_id' => $item['product_id'] ?? null,
                    // `description` is varchar(255) — cap so extracted text never breaks the insert.
                    'description' => $this->cap($item['description'] ?? null, 255),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'unit' => $this->cap(($item['unit'] ?? null) ?: 'pcs', 20),
                    'target_price' => $item['target_price_minor'] ?? null,
                    'specifications' => $item['specifications'] ?? null,
                    'notes' => $item['notes'] ?? null,
                    'sort_order' => $nextOrder + $index,
                ]);
            }

            $this->attachSourceFile($inquiry, $filePath, $user);

            activity('ai-assistant')
                ->causedBy($user)
                ->performedOn($inquiry)
                ->withProperties(['itens' => count($preview['itens']), 'modo' => $preview['modo'] ?? 'nova'])
                ->log('inquiry_imported');

            return $inquiry;
        });
    }

    /**
     * @param  array<string,mixed>  $preview
     */
    private function authorize(array $preview, User $user): void
    {
        if (($preview['modo'] ?? 'nova') === 'existente') {
            if (! $user->can('edit-inquiries')) {
                throw new AuthorizationException(__('assistant.perm_inquiries_edit'));
            }

            return;
        }

        if (! $user->can('create-inquiries')) {
            throw new AuthorizationException(__('assistant.perm_inquiries_create'));
        }

        if (($preview['cliente']['status'] ?? null) === 'novo' && ! $user->can('create-companies')) {
            throw new AuthorizationException(__('assistant.perm_companies'));
        }
    }

    /**
     * @param  array<string,mixed>  $preview
     */
    private function existingInquiry(array $preview): Inquiry
    {
        $inquiry = Inquiry::findOrFail((int) ($preview['inquiry_id'] ?? 0));

        if (! in_array($inquiry->status, [InquiryStatus::RECEIVED, InquiryStatus::QUOTING], true)) {
            throw new \InvalidArgumentException(__('assistant.inquiry_not_open'));
        }

        return $inquiry;
    }

    /**
     * @param  array<string,mixed>  $preview
     */
    private function createInquiry(array $preview, User $user): Inquiry
    {
        $client = $this->resolveClient($preview['cliente'] ?? []);

        return Inquiry::create([
            'company_id' => $client->id,
            'status' => InquiryStatus::RECEIVED,
            'source' => InquirySource::OTHER,
            'currency_code' => $this->cap(strtoupper((string) ($preview['cabecalho']['currency_code'] ?? 'USD')), 10),
            'deadline' => $preview['cabecalho']['deadline'] ?? null,
            'notes' => $preview['cabecalho']['notes'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    /**
     * @param  array<string,mixed>  $cliente
     */
    private function resolveClient(array $cliente): Company
    {
        if (! empty($cliente['company_id'])) {
            $company = Company::findOrFail($cliente['company_id']);
        } else {
            $name = trim((string) ($cliente['nome'] ?? ''));
            if ($name === '') {
                throw new \InvalidArgumentException(__('assistant.client_required'));
            }
            $company = Company::create(['name' => $this->cap($name, 255)]);
        }

        if (! $company->companyRoles()->where('role', CompanyRole::CLIENT->value)->exists()) {
            $company->companyRoles()->create(['role' => CompanyRole::CLIENT->value]);
        }

        return $company;
    }

    /** Cap a string to a column max length (null-safe). */
    private function cap(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }

    private function attachSourceFile(Inquiry $inquiry, string $filePath, User $user): void
    {
        $disk = 'local';
        $name = basename($filePath);
        $stored = "inquiries/{$inquiry->id}/{$name}";
        Storage::disk($disk)->put($stored, (string) file_get_contents($filePath));

        $inquiry->documents()->create([
            'type' => 'inquiry_source',
            'name' => $name,
            'disk' => $disk,
            'path' => $stored,
            'version' => 1,
            'source' => DocumentSourceType::UPLOADED,
            'mime_type' => Storage::disk($disk)->mimeType($stored),
            'size' => Storage::disk($disk)->size($stored),
            'checksum' => hash_file('sha256', Storage::disk($disk)->path($stored)),
            'created_by' => $user->id,
        ]);
    }
}
```

`documents.type` is a plain string column (the SQ action stores `'supplier_quotation_source'` the same way), so `'inquiry_source'` needs no enum change. Verify with `grep -n "'type'" database/migrations/*create_documents*` if in doubt.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/AI/Import/ImportInquiryActionTest.php`
Expected: PASS (6 tests)

- [ ] **Step 6: Pint**

Run: `vendor/bin/pint --dirty --format agent`

---

### Task 6: InquiryTarget + register it

**Files:**
- Create: `app/Domain/AI/Import/Targets/InquiryTarget.php`
- Modify: `app/Domain/AI/Import/Targets/ImportTargetRegistry.php` (add to the constructor list)
- Modify: `lang/en/assistant.php`, `lang/pt_BR/assistant.php`, `lang/zh_CN/assistant.php` (target labels)
- Test: `tests/Feature/AI/Import/InquiryTargetTest.php`

- [ ] **Step 1: Add the target-label lang keys**

`lang/en/assistant.php`:

```php
    'target_supplier_quotation' => 'Supplier Quotation',
    'target_inquiry' => 'Inquiry (client request)',
```

`lang/pt_BR/assistant.php`:

```php
    'target_supplier_quotation' => 'Cotação de Fornecedor',
    'target_inquiry' => 'Inquiry (pedido de cliente)',
```

`lang/zh_CN/assistant.php`:

```php
    'target_supplier_quotation' => '供应商报价单',
    'target_inquiry' => '询价单（客户需求）',
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/AI/Import/InquiryTargetTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\Targets\ImportTargetRegistry;
use App\Domain\AI\Import\Targets\InquiryTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InquiryTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_in_registry(): void
    {
        $this->assertInstanceOf(InquiryTarget::class, (new ImportTargetRegistry)->get('inquiry'));
    }

    public function test_metadata(): void
    {
        $target = new InquiryTarget;

        $this->assertSame('inquiry', $target->key());
        $this->assertSame('registrar_inquiry', $target->extractionToolName());
        $this->assertSame('atualizar_inquiry', $target->editToolName());
        $this->assertFalse($target->supportsImages());
        $this->assertArrayHasKey('cliente', $target->extractionSchema()['properties']);
    }

    public function test_authorize_accepts_create_or_edit_inquiries(): void
    {
        foreach (['create-inquiries', 'edit-inquiries'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $target = new InquiryTarget;

        $this->assertFalse($target->authorize(User::factory()->create()));

        $creator = User::factory()->create();
        $creator->givePermissionTo('create-inquiries');
        $this->assertTrue($target->authorize($creator));

        $editor = User::factory()->create();
        $editor->givePermissionTo('edit-inquiries');
        $this->assertTrue($target->authorize($editor));
    }

    public function test_build_form_and_confirm_payload_round_trip(): void
    {
        $target = new InquiryTarget;

        $preview = [
            'cliente' => ['status' => 'novo', 'company_id' => null, 'nome' => 'DeepFitness', 'contato' => null],
            'cabecalho' => ['currency_code' => 'USD', 'deadline' => '2026-08-01', 'notes' => null],
            'itens' => [[
                'status' => 'novo', 'product_id' => null, 'part_no' => 'DF-1',
                'description' => 'Treadmill', 'quantity' => 3, 'unit' => 'pcs',
                'target_price_minor' => \App\Domain\Infrastructure\Support\Money::toMinor(250.5),
                'specifications' => null, 'notes' => null,
            ]],
            'resumo' => ['total_itens' => 1, 'produtos_casados' => 0, 'produtos_sem_match' => 1, 'total_estimado' => null],
        ];

        $form = $target->buildForm($preview, []);
        $this->assertSame('nova', $form['modo']);
        $this->assertNull($form['inquiry_id']);
        $this->assertSame('DeepFitness', $form['cliente']['nome']);
        $this->assertSame(250.5, $form['itens'][0]['target_price']);

        $form['modo'] = 'existente';
        $form['inquiry_id'] = 7;
        $payload = $target->formToConfirmPayload($form, []);

        $this->assertSame('existente', $payload['preview']['modo']);
        $this->assertSame(7, $payload['preview']['inquiry_id']);
        $this->assertSame(\App\Domain\Infrastructure\Support\Money::toMinor(250.5), $payload['preview']['itens'][0]['target_price_minor']);
        $this->assertSame([], $payload['images']);
    }

    public function test_item_without_target_price_round_trips_as_null(): void
    {
        $target = new InquiryTarget;

        $form = $target->buildForm([
            'cliente' => ['status' => 'novo', 'company_id' => null, 'nome' => 'X', 'contato' => null],
            'cabecalho' => ['currency_code' => 'USD', 'deadline' => null, 'notes' => null],
            'itens' => [[
                'status' => 'novo', 'product_id' => null, 'part_no' => null,
                'description' => 'Item', 'quantity' => 1, 'unit' => 'pcs',
                'target_price_minor' => null, 'specifications' => null, 'notes' => null,
            ]],
        ], []);

        $this->assertNull($form['itens'][0]['target_price']);
        $payload = $target->formToConfirmPayload($form, []);
        $this->assertNull($payload['preview']['itens'][0]['target_price_minor']);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/AI/Import/InquiryTargetTest.php`
Expected: FAIL — class not found

- [ ] **Step 4: Implement InquiryTarget**

`app/Domain/AI/Import/Targets/InquiryTarget.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\AI\Import\Targets;

use App\Domain\AI\Import\InquiryDraftSchema;
use App\Domain\AI\Import\ResolveInquiryDraft;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Inquiries\Actions\ImportInquiryAction;
use App\Models\User;

class InquiryTarget implements ImportTarget
{
    public function key(): string
    {
        return 'inquiry';
    }

    public function label(): string
    {
        return __('assistant.target_inquiry');
    }

    public function classifierHint(): string
    {
        return 'Pedido de cotação ENVIADO POR UM CLIENTE (inquiry/RFQ): lista de produtos que o '
            .'cliente quer comprar, com quantidades, às vezes preço-alvo e prazo de resposta. '
            .'Sem preços oferecidos por fornecedor, sem MOQ/lead time de fábrica.';
    }

    public function extractionToolName(): string
    {
        return 'registrar_inquiry';
    }

    public function extractionSchema(): array
    {
        return InquiryDraftSchema::schema();
    }

    public function extractionUserPrompt(): string
    {
        return 'Extraia o pedido de cotação (inquiry) deste cliente do documento a seguir. '
            .'Use a ferramenta registrar_inquiry. Não invente dados ausentes — omita campos que não constam.';
    }

    public function extractionSystemPrompt(): string
    {
        return 'Você extrai pedidos de cotação (inquiries) de clientes a partir de planilhas e PDFs '
            .'para uma trading company Brasil–China. Valores na moeda do documento, como números decimais. '
            .'Datas em YYYY-MM-DD. Cada item deve ter description e quantity; capture part_no, unit, '
            .'target_price (preço-alvo do cliente), specifications e notes quando constarem. '
            .'Se o documento não identificar o cliente, use string vazia em cliente.nome.';
    }

    public function editToolName(): string
    {
        return 'atualizar_inquiry';
    }

    public function editSystemPrompt(): string
    {
        return <<<'PROMPT'
        You edit an already-extracted client inquiry (request for quotation) for a Brazil–China
        trading company, based on the user's instruction. The data is being reviewed before import.

        Rules:
        - Return the FULL updated inquiry (keep unchanged fields as-is). You may add, remove or
          merge items and change client fields when asked.
        - Keep prices in the document currency as decimal numbers; target_price is the client's
          target unit price.
        - If the user only asks a QUESTION about the inquiry, answer it in `reply` and return the
          draft unchanged.
        - Write `reply` as a short message in :language. Never invent data.
        PROMPT;
    }

    public function resolve(array $draft): array
    {
        return app(ResolveInquiryDraft::class)->resolve($draft);
    }

    public function supportsImages(): bool
    {
        return false;
    }

    public function authorize(User $user): bool
    {
        return $user->can('create-inquiries') || $user->can('edit-inquiries');
    }

    public function buildForm(array $preview, array $itemPhoto): array
    {
        $itens = [];
        foreach (array_values($preview['itens']) as $it) {
            $minor = $it['target_price_minor'] ?? null;
            $itens[] = [
                'part_no' => $it['part_no'] ?? null,
                'description' => $it['description'] ?? '',
                'quantity' => $it['quantity'] ?? 0,
                'unit' => $it['unit'] ?? 'pcs',
                'target_price' => $minor !== null ? round(((int) $minor) / Money::SCALE, 2) : null,
                'specifications' => $it['specifications'] ?? null,
                'notes' => $it['notes'] ?? null,
                'status' => $it['status'] ?? 'novo',
                'product_id' => $it['product_id'] ?? null,
            ];
        }

        return [
            'modo' => 'nova',
            'inquiry_id' => null,
            'cliente' => [
                'nome' => $preview['cliente']['nome'] ?? '',
                'status' => $preview['cliente']['status'] ?? 'novo',
                'company_id' => $preview['cliente']['company_id'] ?? null,
            ],
            'cabecalho' => $preview['cabecalho'] ?? [],
            'itens' => $itens,
        ];
    }

    public function formToConfirmPayload(array $form, array $imagePool): array
    {
        $itens = [];
        foreach (array_values($form['itens'] ?? []) as $it) {
            $price = $it['target_price'] ?? null;
            $itens[] = [
                'status' => $it['status'] ?? 'novo',
                'product_id' => $it['product_id'] ?? null,
                'part_no' => filled($it['part_no'] ?? null) ? trim((string) $it['part_no']) : null,
                'description' => (string) ($it['description'] ?? ''),
                'quantity' => (int) ($it['quantity'] ?? 0),
                'unit' => $it['unit'] ?? null,
                'target_price_minor' => ($price !== null && $price !== '') ? Money::toMinor($price) : null,
                'specifications' => $it['specifications'] ?? null,
                'notes' => $it['notes'] ?? null,
            ];
        }

        $c = $form['cliente'] ?? [];

        return [
            'preview' => [
                'modo' => $form['modo'] ?? 'nova',
                'inquiry_id' => $form['inquiry_id'] !== null ? (int) $form['inquiry_id'] : null,
                'cliente' => ['status' => $c['status'] ?? 'novo', 'company_id' => $c['company_id'] ?? null, 'nome' => $c['nome'] ?? ''],
                'cabecalho' => $form['cabecalho'] ?? [],
                'itens' => $itens,
            ],
            'images' => [],
        ];
    }

    public function confirm(array $preview, User $user, string $filePath, array $images): array
    {
        $inquiry = app(ImportInquiryAction::class)($preview, $user, $filePath);

        return ['reference' => (string) $inquiry->reference, 'count' => count($preview['itens'])];
    }
}
```

- [ ] **Step 5: Register in the registry**

In `ImportTargetRegistry::__construct()`, change the list to:

```php
foreach ([new SupplierQuotationTarget, new InquiryTarget] as $target) {
```

(and remove the "Task 6 adds..." comment).

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/AI/Import/InquiryTargetTest.php`
Expected: PASS (5 tests)

- [ ] **Step 7: Pint**

Run: `vendor/bin/pint --dirty --format agent`

---

### Task 7: Generalize the page trait + blade (chooser, per-target review, lang)

The big wiring task. The trait becomes `HandlesDocumentImport` with a target state machine: upload → classify → user confirms target → extract → review → confirm. The review card moves into per-target blade partials.

**Files:**
- Create: `app/Filament/Pages/Concerns/HandlesDocumentImport.php` (content = rewritten `HandlesSupplierQuotationImport.php`)
- Delete: `app/Filament/Pages/Concerns/HandlesSupplierQuotationImport.php`
- Modify: `app/Filament/Pages/Assistant.php` (trait swap)
- Create: `resources/views/filament/pages/assistant/review-supplier_quotation.blade.php`
- Create: `resources/views/filament/pages/assistant/review-inquiry.blade.php`
- Modify: `resources/views/filament/pages/assistant.blade.php`
- Modify: `lang/en/assistant.php`, `lang/pt_BR/assistant.php`, `lang/zh_CN/assistant.php`
- Modify: `tests/Feature/AI/Import/AssistantImportFlowTest.php` (new chooser step)
- Test: `tests/Feature/AI/Import/AssistantInquiryImportFlowTest.php`

- [ ] **Step 1: Add the remaining lang keys (all three locales)**

`lang/en/assistant.php`:

```php
    // Universal import — target chooser
    'suggest_target' => 'This document looks like: :label — :reason',
    'suggest_unknown' => "I couldn't identify the document type automatically.",
    'choose_target' => 'Choose the import destination:',
    'import_as' => 'Import as :label',
    'extracted_generic' => 'Data extracted. Review below and confirm the import.',
    'imported_generic' => ':reference imported with :count item(s).',

    // Universal import — inquiry review card
    'mode_new_inquiry' => 'Create new inquiry',
    'mode_existing_inquiry' => 'Add to existing inquiry',
    'client' => 'Client',
    'deadline' => 'Deadline',
    'inquiry_label' => 'Inquiry',
    'select_inquiry' => 'Select the inquiry…',
    'import_locked_inquiry' => 'Upload a file to import items into inquiry :reference.',
    'summary_inquiry_counts' => ':total items — :matched matched to products, :unmatched without match (imported with description only).',
    'col_target_price' => 'Target price',
```

`lang/pt_BR/assistant.php`:

```php
    // Import universal — escolha do destino
    'suggest_target' => 'Este documento parece ser: :label — :reason',
    'suggest_unknown' => 'Não consegui identificar o tipo do documento automaticamente.',
    'choose_target' => 'Escolha o destino da importação:',
    'import_as' => 'Importar como :label',
    'extracted_generic' => 'Dados extraídos. Revise abaixo e confirme a importação.',
    'imported_generic' => ':reference importada com :count item(ns).',

    // Import universal — revisão de inquiry
    'mode_new_inquiry' => 'Criar inquiry nova',
    'mode_existing_inquiry' => 'Adicionar a inquiry existente',
    'client' => 'Cliente',
    'deadline' => 'Prazo',
    'inquiry_label' => 'Inquiry',
    'select_inquiry' => 'Selecione a inquiry…',
    'import_locked_inquiry' => 'Envie um arquivo para importar itens na inquiry :reference.',
    'summary_inquiry_counts' => ':total itens — :matched casados com produtos, :unmatched sem match (importados só com a descrição).',
    'col_target_price' => 'Preço-alvo',
```

`lang/zh_CN/assistant.php`:

```php
    // 通用导入 — 目标选择
    'suggest_target' => '该文档看起来是：:label — :reason',
    'suggest_unknown' => '无法自动识别文档类型。',
    'choose_target' => '请选择导入目标：',
    'import_as' => '导入为 :label',
    'extracted_generic' => '数据已提取。请在下方检查并确认导入。',
    'imported_generic' => ':reference 已导入 :count 个项目。',

    // 通用导入 — 询价单审核
    'mode_new_inquiry' => '创建新询价单',
    'mode_existing_inquiry' => '添加到现有询价单',
    'client' => '客户',
    'deadline' => '截止日期',
    'inquiry_label' => '询价单',
    'select_inquiry' => '选择询价单…',
    'import_locked_inquiry' => '上传文件以将项目导入询价单 :reference。',
    'summary_inquiry_counts' => '共 :total 项 — :matched 项已匹配产品，:unmatched 项未匹配（仅按描述导入）。',
    'col_target_price' => '目标价',
```

Also update the dropzone copy in all three locales (`dropzone_rest`): en `'a document here (supplier quotation, client inquiry…), or click to select'`; pt_BR `'um documento aqui (cotação de fornecedor, pedido de cliente…), ou clique para selecionar'`; zh_CN `'将文档拖到此处（供应商报价、客户询价…），或点击选择'`.

- [ ] **Step 2: Write the failing tests**

Update `tests/Feature/AI/Import/AssistantImportFlowTest.php` — the SQ flow now has a chooser step. In `test_upload_then_confirm_creates_records()` (and analogously in the other two tests), bind a classifier fake in `setUp()` and add the choose call:

```php
// in setUp(), after Storage::fake('local'):
$this->app->bind(\App\Domain\AI\Import\DocumentClassifier::class, fn () => new class extends \App\Domain\AI\Import\DocumentClassifier
{
    public function __construct() {}

    public function classify(array $documentBlocks, array $targets): array
    {
        return ['tipo' => 'supplier_quotation', 'confianca' => 'alta', 'motivo' => 'teste'];
    }
});
```

and in each test, the Livewire chain gains one call between `submitImport` and the preview assertion:

```php
Livewire::test(Assistant::class)
    ->set('upload', $file)
    ->call('submitImport')
    ->assertSet('importSuggestion.tipo', 'supplier_quotation')
    ->call('chooseImportTarget', 'supplier_quotation')
    ->assertSet('importPreview.fornecedor.nome', 'Flow Supplier')
    ->call('confirmImport');
```

Also grant `edit-inquiries`/`create-inquiries`? Not needed for SQ tests. But `chooseImportTarget` authorizes via target: SQ target needs `create-supplier-quotations` — `test_chat_message_edits_the_active_preview` currently only grants `view-assistant`; add `create-supplier-quotations` to that test's permissions (create it in setUp already).

New file `tests/Feature/AI/Import/AssistantInquiryImportFlowTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\DocumentClassifier;
use App\Domain\AI\Import\DraftExtractor;
use App\Domain\AI\Import\Targets\ImportTarget;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Filament\Pages\Assistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AssistantInquiryImportFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['view-assistant', 'create-inquiries', 'edit-inquiries', 'create-companies'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Storage::fake('local');
        \Filament\Facades\Filament::setCurrentPanel('admin');

        $this->app->bind(DocumentClassifier::class, fn () => new class extends DocumentClassifier
        {
            public function __construct() {}

            public function classify(array $documentBlocks, array $targets): array
            {
                return ['tipo' => 'inquiry', 'confianca' => 'alta', 'motivo' => 'lista de compra'];
            }
        });

        $this->app->bind(DraftExtractor::class, fn () => new class extends DraftExtractor
        {
            public function __construct() {}

            public function extract(ImportTarget $target, array $documentBlocks): array
            {
                return [
                    'cliente' => ['nome' => 'DeepFitness', 'currency_code' => 'USD'],
                    'itens' => [
                        ['part_no' => 'DF-1', 'description' => 'Treadmill', 'quantity' => 3, 'target_price' => 250.0],
                    ],
                ];
            }
        });
    }

    private function fakeUpload(): UploadedFile
    {
        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Part', 'Qty'], ['DF-1', 3]]);
        $tmp = tempnam(sys_get_temp_dir(), 'inq').'.xlsx';
        (new Xlsx($ss))->save($tmp);

        return UploadedFile::fake()->createWithContent('pedido.xlsx', (string) file_get_contents($tmp));
    }

    public function test_suggestion_then_choose_inquiry_then_confirm_creates_inquiry(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-inquiries', 'create-companies']);
        $this->actingAs($user);

        Livewire::test(Assistant::class)
            ->set('upload', $this->fakeUpload())
            ->call('submitImport')
            ->assertSet('importSuggestion.tipo', 'inquiry')
            ->assertSet('form', null)
            ->call('chooseImportTarget', 'inquiry')
            ->assertSet('importTargetKey', 'inquiry')
            ->assertSet('form.modo', 'nova')
            ->assertSet('form.cliente.nome', 'DeepFitness')
            ->call('confirmImport');

        $this->assertSame(1, Inquiry::count());
        $this->assertDatabaseHas('companies', ['name' => 'DeepFitness']);
        $this->assertDatabaseHas('inquiry_items', ['description' => 'Treadmill', 'quantity' => 3]);
    }

    public function test_existing_mode_appends_to_selected_inquiry(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'edit-inquiries']);
        $this->actingAs($user);

        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::RECEIVED]);

        Livewire::test(Assistant::class)
            ->set('upload', $this->fakeUpload())
            ->call('submitImport')
            ->call('chooseImportTarget', 'inquiry')
            ->set('form.modo', 'existente')
            ->set('form.inquiry_id', $inquiry->id)
            ->call('confirmImport');

        $this->assertSame(1, Inquiry::count());
        $this->assertSame(1, $inquiry->items()->count());
    }

    public function test_choosing_unauthorized_target_is_rejected(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('view-assistant'); // no inquiry permissions
        $this->actingAs($user);

        Livewire::test(Assistant::class)
            ->set('upload', $this->fakeUpload())
            ->call('submitImport')
            ->call('chooseImportTarget', 'inquiry')
            ->assertSet('importTargetKey', null)
            ->assertSet('form', null);
    }

    public function test_cancel_resets_everything(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-inquiries', 'create-companies']);
        $this->actingAs($user);

        Livewire::test(Assistant::class)
            ->set('upload', $this->fakeUpload())
            ->call('submitImport')
            ->call('chooseImportTarget', 'inquiry')
            ->call('cancelImport')
            ->assertSet('form', null)
            ->assertSet('importTargetKey', null)
            ->assertSet('importSuggestion', null)
            ->assertSet('importFilePath', null);

        $this->assertSame(0, Inquiry::count());
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/AI/Import/AssistantInquiryImportFlowTest.php`
Expected: FAIL — no `chooseImportTarget` method / `importSuggestion` property

- [ ] **Step 4: Create HandlesDocumentImport (replaces the old trait)**

Create `app/Filament/Pages/Concerns/HandlesDocumentImport.php`. It keeps these members **unchanged** from `HandlesSupplierQuotationImport`: `$upload`, `$importPreview`, `$importDraft`, `$importImagePool`, `$importFilePath`, `$form`, `buildImagePool()`, `poolAsImagesRaw()`, `importImageThumb()`, `setItemPhoto()`, `importCategoryOptions()`, `clearImportFile()`. The changed/new parts in full:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use Anthropic\Core\Exceptions\AnthropicException;
use App\Domain\AI\Import\DocumentClassifier;
use App\Domain\AI\Import\DocumentExtractor;
use App\Domain\AI\Import\DocumentImageExtractor;
use App\Domain\AI\Import\DraftEditor;
use App\Domain\AI\Import\DraftExtractor;
use App\Domain\AI\Import\Exceptions\ExtractionFailedException;
use App\Domain\AI\Import\Exceptions\UnsupportedDocumentException;
use App\Domain\AI\Import\Targets\ImportTarget;
use App\Domain\AI\Import\Targets\ImportTargetRegistry;
use App\Domain\Inquiries\Models\Inquiry;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;

/**
 * Universal document import on the assistant page: upload → classify (AI suggests
 * the destination) → user confirms the target → extract → editable review →
 * confirm. The write happens only on confirmImport() (button) through the target's
 * Action, never via the chat loop.
 */
trait HandlesDocumentImport
{
    /** Temporary uploaded file (Livewire). */
    public $upload = null;

    /** Confirmed import target key (null = not chosen yet). */
    #[Locked]
    public ?string $importTargetKey = null;

    /** Classifier suggestion awaiting user confirmation: {tipo, confianca, motivo}. */
    #[Locked]
    public ?array $importSuggestion = null;

    /** When set (entry from an Inquiry page), the import is locked to that inquiry. */
    #[Locked]
    public ?int $importLockedInquiryId = null;

    /** Resolved preview snapshot (read-only, for chat-edit re-resolution). */
    #[Locked]
    public ?array $importPreview = null;

    /** Raw extracted draft (pre-resolution), kept so the chat can edit it. */
    #[Locked]
    public ?array $importDraft = null;

    /** Ordered pool of extracted images: list<array{id:int,path:string}>. Server-controlled. */
    #[Locked]
    public array $importImagePool = [];

    /** Absolute path of the stored source file, kept until confirm/cancel. */
    #[Locked]
    public ?string $importFilePath = null;

    /** Editable review form (NOT locked) — the source of truth for Confirm. */
    public ?array $form = null;

    public function submitImport(): void
    {
        if ($this->upload === null) {
            return;
        }

        $this->validate([
            'upload' => 'required|file|mimes:xlsx,xls,pdf|max:20480',
        ]);

        $ext = strtolower($this->upload->getClientOriginalExtension());
        $stored = $this->upload->storeAs('ai-imports', uniqid('imp_').'.'.$ext, 'local');
        $this->importFilePath = Storage::disk('local')->path($stored);

        try {
            $blocks = app(DocumentExtractor::class)->toContentBlocks($this->importFilePath);

            // Entry-point lock (e.g. "?import=inquiry&inquiry_id=N"): skip classification.
            if ($this->importTargetKey !== null) {
                $target = $this->resolveTarget($this->importTargetKey);
                if ($target !== null) {
                    $this->runExtraction($target, $blocks);

                    return;
                }
            }

            $this->importSuggestion = $this->classify($blocks);

            $suggested = $this->resolveTarget($this->importSuggestion['tipo']);
            $this->messages[] = [
                'role' => 'assistant',
                'text' => $suggested !== null
                    ? __('assistant.suggest_target', ['label' => $suggested->label(), 'reason' => $this->importSuggestion['motivo']])
                    : __('assistant.suggest_unknown'),
            ];
        } catch (UnsupportedDocumentException|ExtractionFailedException $e) {
            $this->cancelImport();
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.process_failed', ['error' => $e->getMessage()])];
        } catch (AnthropicException $e) {
            report($e);
            $this->cancelImport();
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.connection_failed')];
        } catch (\Throwable $e) {
            report($e);
            $this->cancelImport();
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.process_error')];
        } finally {
            $this->upload = null;
            $this->dispatch('assistant-updated');
        }
    }

    /** User confirmed (or switched) the destination — run the expensive extraction. */
    public function chooseImportTarget(string $key): void
    {
        if ($this->importFilePath === null) {
            return;
        }

        $target = $this->resolveTarget($key);
        if ($target === null) {
            return;
        }

        // Switching targets after an extraction discards the previous draft.
        $this->importDraft = null;
        $this->importPreview = null;
        $this->form = null;
        $this->importImagePool = [];

        try {
            $blocks = app(DocumentExtractor::class)->toContentBlocks($this->importFilePath);
            $this->runExtraction($target, $blocks);
        } catch (UnsupportedDocumentException|ExtractionFailedException $e) {
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.process_failed', ['error' => $e->getMessage()])];
        } catch (AnthropicException $e) {
            report($e);
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.connection_failed')];
        } catch (\Throwable $e) {
            report($e);
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.process_error')];
        }

        $this->dispatch('assistant-updated');
    }

    /**
     * Best-effort classification; failures fall back to 'desconhecido' and the
     * manual chooser — never block the import.
     *
     * @param  list<object>  $blocks
     * @return array{tipo:string,confianca:string,motivo:string}
     */
    private function classify(array $blocks): array
    {
        try {
            return app(DocumentClassifier::class)->classify(
                $blocks,
                app(ImportTargetRegistry::class)->allFor(auth()->user()),
            );
        } catch (\Throwable $e) {
            report($e);

            return ['tipo' => 'desconhecido', 'confianca' => 'baixa', 'motivo' => ''];
        }
    }

    /** @param  list<object>  $blocks */
    private function runExtraction(ImportTarget $target, array $blocks): void
    {
        $draft = app(DraftExtractor::class)->extract($target, $blocks);

        $imagesRaw = $target->supportsImages()
            ? app(DocumentImageExtractor::class)->extract($this->importFilePath)
            : ['by_row' => [], 'ordered' => []];

        [$pool, $itemPhoto] = $this->buildImagePool($draft, $imagesRaw);
        $this->importTargetKey = $target->key();
        $this->importSuggestion = null;
        $this->importDraft = $draft;
        $this->importImagePool = $pool;
        $this->importPreview = $target->resolve($draft);
        $this->form = $target->buildForm($this->importPreview, $itemPhoto);

        if ($this->importLockedInquiryId !== null && array_key_exists('modo', $this->form)) {
            $this->form['modo'] = 'existente';
            $this->form['inquiry_id'] = $this->importLockedInquiryId;
        }

        $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.extracted_generic')];
    }

    /** Registry lookup gated by the target's own authorize(). */
    private function resolveTarget(?string $key): ?ImportTarget
    {
        if ($key === null) {
            return null;
        }

        $target = app(ImportTargetRegistry::class)->get($key);

        return ($target !== null && $target->authorize(auth()->user())) ? $target : null;
    }

    /**
     * Chooser options for the blade: key => label, only targets the user may use.
     *
     * @return array<string,string>
     */
    public function importTargetOptions(): array
    {
        return array_map(
            fn (ImportTarget $t) => $t->label(),
            app(ImportTargetRegistry::class)->allFor(auth()->user()),
        );
    }

    /**
     * Open inquiries for the "add to existing" select: id => "REF — client".
     *
     * @return array<int,string>
     */
    public function openInquiryOptions(): array
    {
        return Inquiry::query()
            ->open()
            ->with('company')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Inquiry $i) => [$i->id => $i->reference.' — '.($i->company?->name ?? '')])
            ->all();
    }

    /**
     * Chat-driven editing of the active import draft (in-memory only; still gated
     * by the Confirm button).
     */
    public function editImport(string $instruction): void
    {
        $target = $this->resolveTarget($this->importTargetKey);
        if ($this->form === null || $target === null) {
            return;
        }

        // User selections that live outside the draft survive the rebuild.
        $keepModo = $this->form['modo'] ?? null;
        $keepInquiryId = $this->form['inquiry_id'] ?? null;

        try {
            $result = app(DraftEditor::class)->edit($target, $this->importDraft, $instruction);

            $this->importDraft = $result['draft'];
            $this->importPreview = $target->resolve($result['draft']);
            [, $itemPhoto] = $this->buildImagePool($result['draft'], $this->poolAsImagesRaw());
            $this->form = $target->buildForm($this->importPreview, $itemPhoto);

            if ($keepModo !== null && array_key_exists('modo', $this->form)) {
                $this->form['modo'] = $keepModo;
                $this->form['inquiry_id'] = $keepInquiryId;
            }

            $reply = trim($result['reply']) !== '' ? $result['reply'] : __('assistant.edit_done');
            $this->messages[] = ['role' => 'assistant', 'text' => $reply];
        } catch (AnthropicException $e) {
            report($e);
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.connection_failed')];
        } catch (\Throwable $e) {
            report($e);
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.process_error')];
        }

        $this->dispatch('assistant-updated');
    }

    public function confirmImport(): void
    {
        $target = $this->resolveTarget($this->importTargetKey);
        if ($this->form === null || $this->importFilePath === null || $target === null) {
            return;
        }

        $base = Storage::disk('local')->path('ai-imports');
        $base = realpath($base) ?: $base;
        $real = realpath($this->importFilePath);
        if ($real === false || ! str_starts_with($real, $base)) {
            Notification::make()->title(__('assistant.invalid_file'))->danger()->send();
            $this->cancelImport();

            return;
        }

        try {
            ['preview' => $preview, 'images' => $images] = $target->formToConfirmPayload($this->form, $this->importImagePool);
            $result = $target->confirm($preview, auth()->user(), $this->importFilePath, $images);

            Notification::make()->title(__('assistant.imported_title', ['reference' => $result['reference']]))->success()->send();
            $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.imported_generic', ['reference' => $result['reference'], 'count' => $result['count']])];
        } catch (\Illuminate\Auth\Access\AuthorizationException|\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return; // keep the review open so the user can fix and retry
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title(__('assistant.import_failed'))->danger()->send();

            return;
        }

        $this->clearImportFile();
        $this->form = null;
        $this->importPreview = null;
        $this->importDraft = null;
        $this->importSuggestion = null;
        if ($this->importLockedInquiryId === null) {
            $this->importTargetKey = null;
        }
        $this->dispatch('assistant-updated');
    }

    public function cancelImport(): void
    {
        $this->clearImportFile();
        $this->importPreview = null;
        $this->importDraft = null;
        $this->importImagePool = [];
        $this->form = null;
        $this->upload = null;
        $this->importSuggestion = null;
        // Entry-point lock survives cancel so the next upload still goes to the inquiry.
        if ($this->importLockedInquiryId === null) {
            $this->importTargetKey = null;
        }
    }

    // buildImagePool(), poolAsImagesRaw(), importImageThumb(), setItemPhoto(),
    // importCategoryOptions(), clearImportFile() — copied UNCHANGED (by method
    // name) from HandlesSupplierQuotationImport.php.
}
```

Copy the six unchanged methods (`buildImagePool`, `poolAsImagesRaw`, `importImageThumb`, `setItemPhoto`, `importCategoryOptions`, `clearImportFile`) verbatim from the old trait into the marked spot, then **delete** `app/Filament/Pages/Concerns/HandlesSupplierQuotationImport.php`.

Behavior notes baked into the code above (do not "fix" them):
- `confirmImport()` no longer clears the file in `finally` — on authorization/validation failure the review stays open for retry (spec: "Falha na transação → arquivo mantido para retry").
- `cancelImport()`/`confirmImport()` keep `importTargetKey` when locked via entry point.

- [ ] **Step 5: Swap the trait in the Assistant page**

In `app/Filament/Pages/Assistant.php`, replace `use App\Filament\Pages\Concerns\HandlesSupplierQuotationImport;` with `use App\Filament\Pages\Concerns\HandlesDocumentImport;` (both the import and the `use ...;` statement inside the class). The `send()` gate (`if ($this->importPreview !== null) { $this->editImport($text); return; }`) stays as is.

- [ ] **Step 6: Split the blade into partials + add the chooser**

1. Create `resources/views/filament/pages/assistant/review-supplier_quotation.blade.php` with the exact content of `resources/views/filament/pages/assistant.blade.php` **lines 35–144** (from the `@php($previewCurrency = ...)` line through the closing `</div>` of the buttons block — i.e., the inside of the current `@if ($form)` card, including the outer bordered `<div ... x-data="{ gallery: null }">`). Unchanged content, it already references `$form`, `$importImagePool`, `$this->...` which resolve in the included partial's Livewire context.

2. Create `resources/views/filament/pages/assistant/review-inquiry.blade.php`:

```blade
<div class="rounded-xl border border-primary-300 bg-primary-50 p-4 text-sm dark:border-primary-700 dark:bg-primary-950/40">
    <p class="font-semibold">{{ __('assistant.preview_title') }}</p>
    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('assistant.review_hint') }}</p>

    {{-- Resumo --}}
    @php($casados = collect($form['itens'])->where('status', 'existente')->count())
    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
        {{ __('assistant.summary_inquiry_counts', ['total' => count($form['itens']), 'matched' => $casados, 'unmatched' => count($form['itens']) - $casados]) }}
    </p>

    {{-- Modo: nova vs existente --}}
    @if ($importLockedInquiryId === null)
        <div class="mt-2 flex gap-4 text-xs">
            <label class="flex items-center gap-1">
                <input type="radio" value="nova" wire:model.live="form.modo" />
                {{ __('assistant.mode_new_inquiry') }}
            </label>
            <label class="flex items-center gap-1">
                <input type="radio" value="existente" wire:model.live="form.modo" />
                {{ __('assistant.mode_existing_inquiry') }}
            </label>
        </div>
    @endif

    @if (($form['modo'] ?? 'nova') === 'existente')
        <div class="mt-2">
            <label class="text-xs">{{ __('assistant.inquiry_label') }}
                @if ($importLockedInquiryId !== null)
                    <input type="text" value="{{ $this->openInquiryOptions()[$importLockedInquiryId] ?? ('#'.$importLockedInquiryId) }}" disabled
                           class="mt-0.5 block w-full rounded border-gray-300 bg-gray-100 text-sm dark:bg-gray-800 dark:border-white/10" />
                @else
                    <select wire:model="form.inquiry_id" class="mt-0.5 block w-full rounded border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10">
                        <option value="">{{ __('assistant.select_inquiry') }}</option>
                        @foreach ($this->openInquiryOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                @endif
            </label>
        </div>
    @else
        {{-- Cabeçalho da inquiry nova --}}
        <div class="mt-2 grid grid-cols-2 gap-2 md:grid-cols-4">
            <label class="col-span-2 text-xs">
                {{ __('assistant.client') }}
                <span class="ml-1 rounded px-1.5 py-0.5 text-[10px] {{ ($form['cliente']['status'] ?? '') === 'novo' ? 'bg-amber-200 text-amber-900' : 'bg-green-200 text-green-900' }}">
                    {{ ($form['cliente']['status'] ?? '') === 'novo' ? __('assistant.status_new') : __('assistant.status_existing') }}
                </span>
                <input type="text" wire:model="form.cliente.nome" class="mt-0.5 block w-full rounded border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10" />
            </label>
            <label class="text-xs">{{ __('assistant.currency') }}
                <input type="text" wire:model="form.cabecalho.currency_code" class="mt-0.5 block w-full rounded border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10" />
            </label>
            <label class="text-xs">{{ __('assistant.deadline') }}
                <input type="date" wire:model="form.cabecalho.deadline" class="mt-0.5 block w-full rounded border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10" />
            </label>
        </div>
    @endif

    {{-- Itens editáveis --}}
    <div class="mt-3 max-h-72 overflow-y-auto rounded border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <table class="w-full text-left text-xs">
            <thead class="sticky top-0 bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="px-2 py-1">{{ __('assistant.col.part_no') }}</th>
                    <th class="px-2 py-1">{{ __('assistant.col.description') }}</th>
                    <th class="px-2 py-1 text-right">{{ __('assistant.col.qty') }}</th>
                    <th class="px-2 py-1">{{ __('assistant.col.unit') }}</th>
                    <th class="px-2 py-1 text-right">{{ __('assistant.col_target_price') }}</th>
                    <th class="px-2 py-1">{{ __('assistant.col.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($form['itens'] as $i => $item)
                    <tr class="border-t border-gray-100 align-top dark:border-white/5" wire:key="inq-item-{{ $i }}">
                        <td class="px-2 py-1"><input type="text" wire:model="form.itens.{{ $i }}.part_no" class="w-24 rounded border-gray-300 text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                        <td class="px-2 py-1"><input type="text" wire:model="form.itens.{{ $i }}.description" class="w-full min-w-[12rem] rounded border-gray-300 text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                        <td class="px-2 py-1 text-right"><input type="number" wire:model="form.itens.{{ $i }}.quantity" class="w-16 rounded border-gray-300 text-right text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                        <td class="px-2 py-1"><input type="text" wire:model="form.itens.{{ $i }}.unit" class="w-16 rounded border-gray-300 text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                        <td class="px-2 py-1 text-right"><input type="number" step="0.01" wire:model="form.itens.{{ $i }}.target_price" class="w-24 rounded border-gray-300 text-right text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                        <td class="px-2 py-1">
                            <span class="rounded px-1.5 py-0.5 text-[10px] {{ ($item['status'] ?? '') === 'novo' ? 'bg-amber-200 text-amber-900' : 'bg-green-200 text-green-900' }}">
                                {{ ($item['status'] ?? '') === 'novo' ? __('assistant.status_new') : __('assistant.status_existing') }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-3 flex gap-2">
        <x-filament::button wire:click="confirmImport" wire:loading.attr="disabled" wire:target="confirmImport" color="primary" size="sm">
            {{ __('assistant.confirm_import') }}
        </x-filament::button>
        <x-filament::button wire:click="cancelImport" color="gray" size="sm">
            {{ __('assistant.cancel') }}
        </x-filament::button>
    </div>
</div>
```

3. In `resources/views/filament/pages/assistant.blade.php`, replace the whole `@if ($form) ... @else ... @endif` block (currently lines 33–199) with:

```blade
{{-- Import universal: revisão / escolha de destino / dropzone --}}
@if ($form)
    @include('filament.pages.assistant.review-'.$importTargetKey)
@elseif ($importSuggestion !== null && $importFilePath !== null)
    {{-- Escolha do destino após a classificação --}}
    <div class="rounded-xl border border-primary-300 bg-primary-50 p-4 text-sm dark:border-primary-700 dark:bg-primary-950/40">
        <p class="font-semibold">{{ __('assistant.choose_target') }}</p>
        <div class="mt-2 flex flex-wrap gap-2" wire:loading.remove wire:target="chooseImportTarget">
            @foreach ($this->importTargetOptions() as $key => $label)
                <x-filament::button
                    wire:click="chooseImportTarget('{{ $key }}')"
                    :color="$key === ($importSuggestion['tipo'] ?? null) ? 'primary' : 'gray'"
                    size="sm"
                >
                    {{ __('assistant.import_as', ['label' => $label]) }}
                </x-filament::button>
            @endforeach
            <x-filament::button wire:click="cancelImport" color="danger" size="sm" outlined>
                {{ __('assistant.cancel') }}
            </x-filament::button>
        </div>
        <div wire:loading.flex wire:target="chooseImportTarget" class="mt-2 items-center gap-2 text-sm text-gray-500">
            <x-filament::loading-indicator class="h-4 w-4" />
            <span>{{ __('assistant.processing') }}</span>
        </div>
    </div>
@else
    {{-- Dropzone (conteúdo existente, inalterado) --}}
    ...keep the existing dropzone div and @error('upload') block exactly as they are today (lines 147–198)...
@endif
```

(The `...keep...` line means: retain the current markup — it is not modified, only re-nested under the new `@else`.)

- [ ] **Step 7: Run the AI import suite**

Run: `php artisan test --compact tests/Feature/AI/Import/`
Expected: PASS — including the updated `AssistantImportFlowTest` and new `AssistantInquiryImportFlowTest`

- [ ] **Step 8: Pint**

Run: `vendor/bin/pint --dirty --format agent`

---

### Task 8: Entry point — Assistant mount() + "Importar com IA" button on Inquiry items

**Files:**
- Modify: `app/Filament/Pages/Assistant.php` (add `mount()`)
- Modify: `app/Filament/Resources/Inquiries/RelationManagers/ItemsRelationManager.php` (header action)
- Modify: `lang/en/assistant.php`, `lang/pt_BR/assistant.php`, `lang/zh_CN/assistant.php` (button label)
- Test: extend `tests/Feature/AI/Import/AssistantInquiryImportFlowTest.php`

- [ ] **Step 1: Add lang keys**

en: `'import_with_ai' => 'Import with AI',` · pt_BR: `'import_with_ai' => 'Importar com IA',` · zh_CN: `'import_with_ai' => 'AI 导入',`

- [ ] **Step 2: Write the failing test**

Add to `AssistantInquiryImportFlowTest`:

```php
    public function test_query_param_locks_target_to_existing_inquiry(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'edit-inquiries']);
        $this->actingAs($user);

        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::RECEIVED]);

        Livewire::withQueryParams(['import' => 'inquiry', 'inquiry_id' => $inquiry->id])
            ->test(Assistant::class)
            ->assertSet('importTargetKey', 'inquiry')
            ->assertSet('importLockedInquiryId', $inquiry->id)
            ->set('upload', $this->fakeUpload())
            ->call('submitImport')
            // Locked target: no chooser, extraction ran directly, mode pre-locked.
            ->assertSet('importSuggestion', null)
            ->assertSet('form.modo', 'existente')
            ->assertSet('form.inquiry_id', $inquiry->id)
            ->call('confirmImport');

        $this->assertSame(1, $inquiry->items()->count());
    }

    public function test_query_param_is_ignored_without_permission_or_closed_inquiry(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('view-assistant');
        $this->actingAs($user);

        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::RECEIVED]);

        Livewire::withQueryParams(['import' => 'inquiry', 'inquiry_id' => $inquiry->id])
            ->test(Assistant::class)
            ->assertSet('importTargetKey', null)
            ->assertSet('importLockedInquiryId', null);
    }
```

Run: `php artisan test --compact --filter=test_query_param tests/Feature/AI/Import/AssistantInquiryImportFlowTest.php`
Expected: FAIL (no mount handling)

- [ ] **Step 3: Add mount() to the Assistant page**

In `app/Filament/Pages/Assistant.php`, add imports:

```php
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
```

and the method:

```php
    public function mount(): void
    {
        // Entry point from an Inquiry page: lock the import to that inquiry.
        if (request()->query('import') !== 'inquiry') {
            return;
        }

        $inquiry = Inquiry::find((int) request()->query('inquiry_id'));

        if ($inquiry === null
            || ! (auth()->user()?->can('edit-inquiries') ?? false)
            || ! in_array($inquiry->status, [InquiryStatus::RECEIVED, InquiryStatus::QUOTING], true)) {
            return; // invalid/unauthorized param → normal assistant flow
        }

        $this->importTargetKey = 'inquiry';
        $this->importLockedInquiryId = $inquiry->id;
        $this->messages[] = ['role' => 'assistant', 'text' => __('assistant.import_locked_inquiry', ['reference' => $inquiry->reference])];
    }
```

If Livewire's `Locked` attribute rejects the assignment during mount (it should not — `#[Locked]` only blocks *client-side* updates), no change is needed; verify by running the test.

- [ ] **Step 4: Add the header action**

In `app/Filament/Resources/Inquiries/RelationManagers/ItemsRelationManager.php`, add to the `->headerActions([...])` array after `PasteItemsFromSpreadsheetAction::forInquiryItems(),`:

```php
                \Filament\Actions\Action::make('importWithAi')
                    ->label(__('assistant.import_with_ai'))
                    ->icon('heroicon-o-sparkles')
                    ->color('gray')
                    ->visible(fn () => (auth()->user()?->can('edit-inquiries') ?? false)
                        && (auth()->user()?->can('view-assistant') ?? false))
                    ->url(fn () => \App\Filament\Pages\Assistant::getUrl([
                        'import' => 'inquiry',
                        'inquiry_id' => $this->getOwnerRecord()->getKey(),
                    ])),
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact tests/Feature/AI/Import/AssistantInquiryImportFlowTest.php`
Expected: PASS (all 6 tests)

- [ ] **Step 6: Pint**

Run: `vendor/bin/pint --dirty --format agent`

---

### Task 9: Full regression

- [ ] **Step 1: Grep for leftovers**

Run: `grep -rn "HandlesSupplierQuotationImport\|SupplierQuotationExtractor\|EditSupplierQuotationDraft\|importCategoryNames" app/ tests/ resources/`
Expected: no matches (or only `importCategoryOptions`, which is a different name).

- [ ] **Step 2: Run the full AI + Inquiries test surface**

Run: `php artisan test --compact tests/Feature/AI/`
Expected: PASS

Run: `php artisan test --compact --filter=Inquiry`
Expected: PASS (pre-existing inquiry tests unaffected)

- [ ] **Step 3: Full suite**

Run: `composer test`
Expected: PASS. If unrelated pre-existing failures appear, report them to Gui without fixing.

- [ ] **Step 4: Final pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: clean

- [ ] **Step 5: Manual smoke checklist (report to Gui, do not commit)**

- Assistant page: upload a supplier-quotation xlsx → suggestion card appears → confirm → SQ review identical to before → import works.
- Assistant page: upload a client order PDF → suggestion "Inquiry" → review with modo nova → confirm creates Inquiry + items + attached file.
- Inquiry page → Items → "Importar com IA" → Assistant opens locked to that inquiry → upload → items appended.
