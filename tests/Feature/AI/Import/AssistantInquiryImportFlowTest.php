<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\DocumentClassifier;
use App\Domain\AI\Import\DraftExtractor;
use App\Domain\AI\Import\Exceptions\ExtractionFailedException;
use App\Domain\AI\Import\Targets\ImportTarget;
use App\Domain\CRM\Models\Company;
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

    public function test_tampered_client_status_cannot_bypass_create_companies_gate(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-inquiries']); // no create-companies
        $this->actingAs($user);

        // The extracted client does not exist, so the resolver leaves company_id null.
        // Flipping the (client-editable) status must not skip the create-companies gate:
        // the payload status is derived server-side from company_id.
        Livewire::test(Assistant::class)
            ->set('upload', $this->fakeUpload())
            ->call('submitImport')
            ->call('chooseImportTarget', 'inquiry')
            ->set('form.cliente.status', 'existente')
            ->call('confirmImport');

        $this->assertSame(0, Inquiry::count());
        $this->assertSame(0, Company::count());
    }

    public function test_failed_extraction_keeps_suggestion_and_target_unset(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-inquiries', 'create-companies']);
        $this->actingAs($user);

        // Override the setUp binding: extraction blows up after a good classification.
        $this->app->bind(DraftExtractor::class, fn () => new class extends DraftExtractor
        {
            public function __construct() {}

            public function extract(ImportTarget $target, array $documentBlocks): array
            {
                throw new ExtractionFailedException('boom');
            }
        });

        Livewire::test(Assistant::class)
            ->set('upload', $this->fakeUpload())
            ->call('submitImport')
            ->assertSet('importSuggestion.tipo', 'inquiry')
            ->call('chooseImportTarget', 'inquiry')
            ->assertSet('importTargetKey', null) // never half-set on failure
            ->assertSet('importSuggestion.tipo', 'inquiry') // chooser survives for retry
            ->assertSet('form', null);
    }

    public function test_double_confirm_is_a_noop(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-inquiries', 'create-companies']);
        $this->actingAs($user);

        Livewire::test(Assistant::class)
            ->set('upload', $this->fakeUpload())
            ->call('submitImport')
            ->call('chooseImportTarget', 'inquiry')
            ->call('confirmImport')
            ->call('confirmImport');

        $this->assertSame(1, Inquiry::count());
    }

    public function test_query_param_locks_inquiry_but_still_offers_target_choice(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'edit-inquiries']);
        $this->actingAs($user);

        $inquiry = Inquiry::factory()->create(['status' => InquiryStatus::RECEIVED]);

        Livewire::withQueryParams(['import' => 'inquiry', 'inquiry_id' => $inquiry->id])
            ->test(Assistant::class)
            // A inquiry é contexto, não decisão de destino: o classificador roda.
            ->assertSet('importTargetKey', null)
            ->assertSet('importLockedInquiryId', $inquiry->id)
            ->set('upload', $this->fakeUpload())
            ->call('submitImport')
            ->assertSet('importSuggestion.tipo', 'inquiry')
            ->call('chooseImportTarget', 'inquiry')
            // Escolhido o destino inquiry, o modo trava na inquiry de origem.
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

    public function test_reopen_target_chooser_returns_to_chooser(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'create-inquiries', 'create-companies']);
        $this->actingAs($user);

        Livewire::test(Assistant::class)
            ->set('upload', $this->fakeUpload())
            ->call('submitImport')
            ->call('chooseImportTarget', 'inquiry')
            ->assertSet('form.modo', 'nova')
            ->call('reopenTargetChooser')
            ->assertSet('form', null)
            ->assertSet('importTargetKey', null)
            ->assertSet('importSuggestion.tipo', 'desconhecido')
            ->assertSet('importFilePath', fn ($path) => $path !== null)
            ->call('chooseImportTarget', 'inquiry')
            ->assertSet('form.modo', 'nova');
    }

    public function test_inquiry_search_filters_options(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['view-assistant', 'edit-inquiries']);
        $this->actingAs($user);

        $alphaCompany = Company::factory()->create(['name' => 'Alpha Trading']);
        $betaCompany = Company::factory()->create(['name' => 'Beta Imports']);
        $alphaInquiry = Inquiry::factory()->create(['status' => InquiryStatus::RECEIVED, 'company_id' => $alphaCompany->id]);
        Inquiry::factory()->create(['status' => InquiryStatus::RECEIVED, 'company_id' => $betaCompany->id]);

        $livewire = Livewire::test(Assistant::class)->set('inquirySearch', 'Alpha');

        $options = $livewire->instance()->openInquiryOptions();

        $this->assertArrayHasKey($alphaInquiry->id, $options);
        $this->assertCount(1, $options);
    }
}
