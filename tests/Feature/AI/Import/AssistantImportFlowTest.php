<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\DocumentClassifier;
use App\Domain\AI\Import\DraftEditor;
use App\Domain\AI\Import\DraftExtractor;
use App\Domain\AI\Import\ProductMatchSuggester;
use App\Domain\AI\Import\Targets\ImportTarget;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
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

class AssistantImportFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['view-assistant', 'create-supplier-quotations', 'create-companies', 'create-products', 'create-inquiries'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Storage::fake('local');
        \Filament\Facades\Filament::setCurrentPanel('admin');

        $this->app->bind(DocumentClassifier::class, fn () => new class extends DocumentClassifier
        {
            public function __construct() {}

            public function classify(array $documentBlocks, array $targets): array
            {
                return ['tipo' => 'supplier_quotation', 'confianca' => 'alta', 'motivo' => 'teste'];
            }
        });

        $this->app->bind(ProductMatchSuggester::class, fn () => new class extends ProductMatchSuggester
        {
            public function suggest(array $items, array $catalog): array
            {
                return [];
            }
        });
    }

    public function test_upload_then_confirm_creates_records(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations', 'create-companies', 'create-products']);
        $this->actingAs($user);

        // Fake extractor → no API call; returns a canned draft.
        $this->app->bind(DraftExtractor::class, fn () => new class extends DraftExtractor
        {
            public function __construct() {}

            public function extract(ImportTarget $target, array $documentBlocks): array
            {
                return [
                    'fornecedor' => ['nome' => 'Flow Supplier', 'currency_code' => 'USD'],
                    'itens' => [
                        ['part_no' => 'P-1', 'description' => 'Item', 'quantity' => 2, 'unit' => 'pcs', 'unit_price' => 50.0],
                    ],
                ];
            }
        });

        // Real tiny xlsx so DocumentExtractor works, wrapped in a Livewire-friendly fake upload.
        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Part', 'Qty', 'Price'], ['P-1', 2, 50.0]]);
        $tmp = tempnam(sys_get_temp_dir(), 'flow').'.xlsx';
        (new Xlsx($ss))->save($tmp);
        $file = UploadedFile::fake()->createWithContent('cotacao.xlsx', (string) file_get_contents($tmp));

        Livewire::test(Assistant::class)
            ->set('upload', $file)
            ->call('submitImport')
            ->assertSet('importSuggestion.tipo', 'supplier_quotation')
            ->call('chooseImportTarget', 'supplier_quotation')
            ->assertSet('importPreview.fornecedor.nome', 'Flow Supplier')
            ->call('confirmImport');

        $this->assertSame(1, SupplierQuotation::count());
        $this->assertDatabaseHas('companies', ['name' => 'Flow Supplier']);
    }

    public function test_link_search_scopes_to_document_supplier_with_full_catalog_toggle(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations']);
        $this->actingAs($user);

        $supplier = \App\Domain\CRM\Models\Company::factory()->create(['name' => 'Hebei Yangrun Sports']);
        $ours = \App\Domain\Catalog\Models\Product::factory()->create(['name' => 'Dumbbell 5kg']);
        $ours->companies()->attach($supplier->id, ['role' => 'supplier']);
        $foreign = \App\Domain\Catalog\Models\Product::factory()->create(['name' => 'Dumbbell 5kg (outro fabricante)']);

        $component = Livewire::test(Assistant::class);
        $instance = $component->instance();
        // Review de SQ com fornecedor resolvido (o form é o estado que importa aqui).
        $instance->form = ['fornecedor' => ['company_id' => $supplier->id, 'nome' => 'Hebei Yangrun Sports'], 'itens' => []];
        $instance->importProductSearch = 'dumbbell';

        $scoped = $instance->importProductOptions();
        $this->assertArrayHasKey($ours->id, $scoped);
        $this->assertArrayNotHasKey($foreign->id, $scoped, 'products from other manufacturers must be hidden by default');

        $instance->importProductSearchAll = true;
        $all = $instance->importProductOptions();
        $this->assertArrayHasKey($ours->id, $all);
        $this->assertArrayHasKey($foreign->id, $all);

        // Destino inquiry (sem fornecedor no form): catálogo inteiro.
        $instance->importProductSearchAll = false;
        $instance->form = ['itens' => []];
        $noScope = $instance->importProductOptions();
        $this->assertArrayHasKey($foreign->id, $noScope);
    }

    public function test_link_item_product_attaches_existing_product_and_confirm_reuses_it(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations', 'create-companies', 'create-products']);
        $this->actingAs($user);

        $existing = \App\Domain\Catalog\Models\Product::factory()->create(['name' => 'Dumbbell hexagonal 5kg']);

        $this->app->bind(DraftExtractor::class, fn () => new class extends DraftExtractor
        {
            public function __construct() {}

            public function extract(ImportTarget $target, array $documentBlocks): array
            {
                return [
                    'fornecedor' => ['nome' => 'Flow Supplier', 'currency_code' => 'USD'],
                    // Sem part_no e com descrição inferida: não casa automaticamente.
                    'itens' => [['description' => 'Dumbbell emborrachado hexagonal — 5kg', 'quantity' => 10, 'unit_price' => 3.5]],
                ];
            }
        });

        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Desc', 'Qty', 'Price'], ['Dumbbell', 10, 3.5]]);
        $tmp = tempnam(sys_get_temp_dir(), 'flow').'.xlsx';
        (new Xlsx($ss))->save($tmp);
        $file = UploadedFile::fake()->createWithContent('cotacao.xlsx', (string) file_get_contents($tmp));

        Livewire::test(Assistant::class)
            ->set('upload', $file)
            ->call('submitImport')
            ->call('chooseImportTarget', 'supplier_quotation')
            ->assertSet('form.itens.0.product_id', null)
            ->call('linkItemProduct', 0, $existing->id)
            ->assertSet('form.itens.0.product_id', $existing->id)
            ->assertSet('form.itens.0.status', 'existente')
            ->assertSet('form.itens.0.product_name', 'Dumbbell hexagonal 5kg')
            ->assertSet('form.itens.0.match_source', 'manual')
            ->assertDispatched('product-linked')
            ->call('confirmImport');

        // Nenhum produto novo criado: o item usou o existente.
        $this->assertSame(1, \App\Domain\Catalog\Models\Product::count());
        $item = \App\Domain\SupplierQuotations\Models\SupplierQuotationItem::first();
        $this->assertSame($existing->id, $item->product_id);
    }

    public function test_flip_pool_image_mirrors_the_file_vertically(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations']);
        $this->actingAs($user);

        $png = sys_get_temp_dir().'/flip_pool_'.uniqid().'.png';
        $im = imagecreatetruecolor(4, 4);
        imagefilledrectangle($im, 0, 0, 3, 1, imagecolorallocate($im, 255, 0, 0));
        imagefilledrectangle($im, 0, 2, 3, 3, imagecolorallocate($im, 0, 0, 255));
        imagepng($im, $png);
        imagedestroy($im);
        $before = imagecolorat(imagecreatefrompng($png), 0, 0);

        $component = Livewire::test(Assistant::class);
        $instance = $component->instance();
        $instance->importImagePool = [['id' => 0, 'path' => $png, 'page' => 0]];
        $instance->flipPoolImage(0);

        $after = imagecolorat(imagecreatefrompng($png), 0, 0);
        $this->assertNotSame($before, $after);
        @unlink($png);
    }

    public function test_manual_photo_upload_in_review_adds_to_pool_and_assigns_item(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations', 'create-companies', 'create-products']);
        $this->actingAs($user);

        $this->app->bind(DraftExtractor::class, fn () => new class extends DraftExtractor
        {
            public function __construct() {}

            public function extract(ImportTarget $target, array $documentBlocks): array
            {
                return [
                    'fornecedor' => ['nome' => 'Flow Supplier', 'currency_code' => 'USD'],
                    'itens' => [['part_no' => 'P-1', 'description' => 'Item', 'quantity' => 2, 'unit_price' => 50.0]],
                ];
            }
        });

        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Part', 'Qty', 'Price'], ['P-1', 2, 50.0]]);
        $tmp = tempnam(sys_get_temp_dir(), 'flow').'.xlsx';
        (new Xlsx($ss))->save($tmp);
        $file = UploadedFile::fake()->createWithContent('cotacao.xlsx', (string) file_get_contents($tmp));

        $component = Livewire::test(Assistant::class)
            ->set('upload', $file)
            ->call('submitImport')
            ->call('chooseImportTarget', 'supplier_quotation')
            ->assertSet('form.itens.0.photo_index', null)
            ->set('photoUploadTarget', 0)
            ->set('itemPhotoUpload', UploadedFile::fake()->image('foto.png', 200, 200))
            ->assertSet('form.itens.0.photo_index', 0)
            ->assertSet('itemPhotoUpload', null)
            ->assertSet('photoUploadTarget', null)
            ->assertDispatched('photo-uploaded');

        $pool = $component->get('importImagePool');
        $this->assertCount(1, $pool);
        $this->assertFileExists($pool[0]['path']);
        $this->assertStringContainsString('/images/upload_', $pool[0]['path']);
    }

    public function test_combined_chooser_shortcut_extracts_as_sq_with_inquiry_prechecked(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations', 'create-companies', 'create-products', 'create-inquiries']);
        $this->actingAs($user);

        $this->app->bind(DraftExtractor::class, fn () => new class extends DraftExtractor
        {
            public function __construct() {}

            public function extract(ImportTarget $target, array $documentBlocks): array
            {
                return [
                    'fornecedor' => ['nome' => 'Flow Supplier', 'currency_code' => 'USD'],
                    'itens' => [['part_no' => 'P-1', 'description' => 'Item', 'quantity' => 2, 'unit_price' => 50.0]],
                ];
            }
        });

        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Part', 'Qty', 'Price'], ['P-1', 2, 50.0]]);
        $tmp = tempnam(sys_get_temp_dir(), 'flow').'.xlsx';
        (new Xlsx($ss))->save($tmp);
        $file = UploadedFile::fake()->createWithContent('cotacao.xlsx', (string) file_get_contents($tmp));

        Livewire::test(Assistant::class)
            ->set('upload', $file)
            ->call('submitImport')
            ->assertSee(__('assistant.import_as_sq_with_inquiry'))
            ->call('chooseImportTarget', 'supplier_quotation_with_inquiry')
            ->assertSet('importTargetKey', 'supplier_quotation')
            ->assertSet('form.criar_inquiry', true);
    }

    public function test_combined_chooser_shortcut_hidden_and_inert_without_create_inquiries(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations']);
        $this->actingAs($user);

        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Part', 'Qty', 'Price'], ['P-1', 2, 50.0]]);
        $tmp = tempnam(sys_get_temp_dir(), 'flow').'.xlsx';
        (new Xlsx($ss))->save($tmp);
        $file = UploadedFile::fake()->createWithContent('cotacao.xlsx', (string) file_get_contents($tmp));

        Livewire::test(Assistant::class)
            ->set('upload', $file)
            ->call('submitImport')
            ->assertDontSee(__('assistant.import_as_sq_with_inquiry'))
            ->call('chooseImportTarget', 'supplier_quotation_with_inquiry')
            ->assertSet('form', null); // atalho inerte sem a permissão
    }

    public function test_out_of_credits_api_error_shows_billing_message_not_connection(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations']);
        $this->actingAs($user);

        // Extractor lança o erro real da API de saldo esgotado (BadRequest 400).
        $this->app->bind(DraftExtractor::class, fn () => new class extends DraftExtractor
        {
            public function __construct() {}

            public function extract(ImportTarget $target, array $documentBlocks): array
            {
                throw new \Anthropic\Core\Exceptions\APIException(
                    request: new \GuzzleHttp\Psr7\Request('POST', 'https://api.anthropic.com/v1/messages'),
                    message: 'Your credit balance is too low to access the Anthropic API.',
                );
            }
        });

        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Part', 'Qty', 'Price'], ['P-1', 2, 50.0]]);
        $tmp = tempnam(sys_get_temp_dir(), 'flow').'.xlsx';
        (new Xlsx($ss))->save($tmp);
        $file = UploadedFile::fake()->createWithContent('cotacao.xlsx', (string) file_get_contents($tmp));

        Livewire::test(Assistant::class)
            ->set('upload', $file)
            ->call('submitImport')
            ->call('chooseImportTarget', 'supplier_quotation')
            ->assertSee(__('assistant.api_billing'))
            ->assertDontSee(__('assistant.connection_failed'));
    }

    public function test_target_chooser_shows_unavailable_targets_disabled_with_reason(): void
    {
        // Usuário SEM permissões de inquiry: a opção deve aparecer desabilitada
        // com o motivo, em vez de sumir silenciosamente.
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations']);
        $this->actingAs($user);

        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Part', 'Qty', 'Price'], ['P-1', 2, 50.0]]);
        $tmp = tempnam(sys_get_temp_dir(), 'flow').'.xlsx';
        (new Xlsx($ss))->save($tmp);
        $file = UploadedFile::fake()->createWithContent('cotacao.xlsx', (string) file_get_contents($tmp));

        Livewire::test(Assistant::class)
            ->set('upload', $file)
            ->call('submitImport')
            ->assertSet('importSuggestion.tipo', 'supplier_quotation')
            ->assertSeeHtml('disabled')
            ->assertSee(__('assistant.import_as', ['label' => __('assistant.target_inquiry')]))
            ->assertSeeHtml(e(__('assistant.target_unavailable_permission', ['label' => __('assistant.target_inquiry')])));

        $options = Livewire::test(Assistant::class)->instance()->importTargetOptions();
        $this->assertTrue($options['supplier_quotation']['available']);
        $this->assertFalse($options['inquiry']['available']);
    }

    public function test_unlink_item_product_resets_link_so_confirm_creates_a_new_product(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations', 'create-companies', 'create-products']);
        $this->actingAs($user);

        // Produto existente que casa por part_no — mas com nome errado que o usuário quer corrigir.
        $wrong = \App\Domain\Catalog\Models\Product::factory()->create(['name' => 'Nome errado', 'reference_code' => 'P-1']);

        $this->app->bind(DraftExtractor::class, fn () => new class extends DraftExtractor
        {
            public function __construct() {}

            public function extract(ImportTarget $target, array $documentBlocks): array
            {
                return [
                    'fornecedor' => ['nome' => 'Flow Supplier', 'currency_code' => 'USD'],
                    'itens' => [['part_no' => 'P-1', 'description' => 'Item', 'quantity' => 2, 'unit_price' => 50.0]],
                ];
            }
        });

        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Part', 'Qty', 'Price'], ['P-1', 2, 50.0]]);
        $tmp = tempnam(sys_get_temp_dir(), 'flow').'.xlsx';
        (new Xlsx($ss))->save($tmp);
        $file = UploadedFile::fake()->createWithContent('cotacao.xlsx', (string) file_get_contents($tmp));

        Livewire::test(Assistant::class)
            ->set('upload', $file)
            ->call('submitImport')
            ->call('chooseImportTarget', 'supplier_quotation')
            ->assertSet('form.itens.0.product_id', $wrong->id)
            ->assertSet('form.itens.0.product_name', 'Nome errado')
            ->call('unlinkItemProduct', 0)
            ->assertSet('form.itens.0.product_id', null)
            ->assertSet('form.itens.0.status', 'novo')
            ->assertSet('form.itens.0.match_source', null)
            ->set('form.itens.0.description', 'Nome corrigido')
            ->set('form.itens.0.part_no', 'P-1-NEW')
            ->call('confirmImport');

        // O item desvinculado gera um produto NOVO com o texto editado; o antigo fica intocado.
        $this->assertDatabaseHas('products', ['name' => 'Nome corrigido', 'reference_code' => 'P-1-NEW', 'model_number' => 'P-1-NEW']);
        $this->assertDatabaseHas('products', ['id' => $wrong->id, 'name' => 'Nome errado']);
    }

    public function test_chat_message_edits_the_active_preview(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations']);
        $this->actingAs($user);

        // Extractor returns an item with no unit (Resolve defaults it to 'pcs').
        $this->app->bind(DraftExtractor::class, fn () => new class extends DraftExtractor
        {
            public function __construct() {}

            public function extract(ImportTarget $target, array $documentBlocks): array
            {
                return [
                    'fornecedor' => ['nome' => 'Flow Supplier', 'currency_code' => 'USD'],
                    'itens' => [['part_no' => 'P-1', 'description' => 'Item', 'quantity' => 2, 'unit_price' => 50.0]],
                ];
            }
        });

        // Editor flips the unit to 'box' and replies.
        $this->app->bind(DraftEditor::class, fn () => new class extends DraftEditor
        {
            public function __construct() {}

            public function edit(ImportTarget $target, array $draft, string $instruction): array
            {
                $draft['itens'][0]['unit'] = 'box';

                return ['draft' => $draft, 'reply' => 'Units updated.'];
            }
        });

        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Part', 'Qty', 'Price'], ['P-1', 2, 50.0]]);
        $tmp = tempnam(sys_get_temp_dir(), 'flow').'.xlsx';
        (new Xlsx($ss))->save($tmp);
        $file = UploadedFile::fake()->createWithContent('cotacao.xlsx', (string) file_get_contents($tmp));

        Livewire::test(Assistant::class)
            ->set('upload', $file)
            ->call('submitImport')
            ->assertSet('importSuggestion.tipo', 'supplier_quotation')
            ->call('chooseImportTarget', 'supplier_quotation')
            ->assertSet('importPreview.itens.0.unit', 'pcs') // default applied on extract
            ->set('draft', 'mude a unidade para box')
            ->call('send')
            ->assertSet('importPreview.itens.0.unit', 'box') // chat edit re-resolved the preview
            ->assertSee('Units updated.');
    }

    public function test_edited_form_is_used_on_confirm(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-supplier-quotations', 'create-companies', 'create-products']);
        $this->actingAs($user);

        $this->app->bind(DraftExtractor::class, fn () => new class extends DraftExtractor
        {
            public function __construct() {}

            public function extract(ImportTarget $target, array $documentBlocks): array
            {
                return [
                    'fornecedor' => ['nome' => 'Flow Supplier', 'currency_code' => 'USD'],
                    'itens' => [['part_no' => 'P-1', 'description' => 'Item', 'quantity' => 2, 'unit_price' => 50.0]],
                ];
            }
        });

        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Part', 'Qty', 'Price'], ['P-1', 2, 50.0]]);
        $tmp = tempnam(sys_get_temp_dir(), 'flow').'.xlsx';
        (new Xlsx($ss))->save($tmp);
        $file = UploadedFile::fake()->createWithContent('cotacao.xlsx', (string) file_get_contents($tmp));

        Livewire::test(Assistant::class)
            ->set('upload', $file)
            ->call('submitImport')
            ->assertSet('importSuggestion.tipo', 'supplier_quotation')
            ->call('chooseImportTarget', 'supplier_quotation')
            ->assertSet('form.itens.0.description', 'Item')
            ->set('form.itens.0.description', 'Edited Item')
            ->set('form.itens.0.quantity', 5)
            ->call('confirmImport');

        $sq = SupplierQuotation::first();
        $this->assertNotNull($sq);
        $item = $sq->items()->first();
        $this->assertSame('Edited Item', $item->description);
        $this->assertSame(5, (int) $item->quantity);
    }
}
