<?php

namespace Tests\Feature\SupplierQuotations;

use App\Domain\AI\Import\DraftExtractor;
use App\Domain\AI\Import\Targets\ImportTarget;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use App\Filament\Resources\SupplierQuotations\Pages\ViewSupplierQuotation;
use App\Filament\Resources\SupplierQuotations\RelationManagers\ItemsRelationManager;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportSupplierResponseFlowTest extends TestCase
{
    use RefreshDatabase;

    private SupplierQuotation $sq;

    /** @var list<SupplierQuotationItem> */
    private array $items;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        $this->sq = SupplierQuotation::factory()->create(['currency_code' => 'USD']);
        $this->items = [];
        foreach ([1, 2] as $i) {
            $this->items[] = SupplierQuotationItem::factory()->create([
                'supplier_quotation_id' => $this->sq->id,
                'quantity' => 10,
                'unit_cost' => 0,
                'sort_order' => $i,
            ]);
        }

        $this->app->bind(DraftExtractor::class, fn () => new class extends DraftExtractor
        {
            public function __construct() {}

            public function extract(ImportTarget $target, array $documentBlocks): array
            {
                return [
                    'cabecalho' => ['currency_code' => 'USD', 'supplier_reference' => 'PI-0088'],
                    'itens' => [['linha' => 2, 'descricao_documento' => 'chain 2', 'unit_price' => 3.5]],
                    'extras' => [['descricao' => 'Sample fee', 'valor' => 50]],
                ];
            }
        });
    }

    public function test_upload_review_and_apply_the_supplier_response(): void
    {
        $component = Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $this->sq,
            'pageClass' => ViewSupplierQuotation::class,
        ])
            ->callTableAction('importSupplierResponse', data: ['file' => $this->xlsx()])
            ->assertHasNoActionErrors()
            ->assertActionMounted('reviewSupplierResponse')
            ->assertMountedActionModalSee('Sample fee')
            ->assertSet('mountedActions.0.data.cabecalho.supplier_reference', 'PI-0088');

        // Linhas do repeater têm chaves UUID; a ordem é a das linhas da SQ.
        $rows = $component->get('mountedActions.0.data.linhas');
        $keys = array_keys($rows);
        $this->assertSame([null, 3.5], array_column($rows, 'unit_cost'));
        $this->assertSame([$this->items[0]->id, $this->items[1]->id], array_column($rows, 'sq_item_id'));

        // Nada gravado antes da revisão.
        $this->assertSame(0, $this->items[1]->fresh()->unit_cost);
        $this->assertSame(0, $this->sq->documents()->count());

        $component
            ->set("mountedActions.0.data.linhas.{$keys[0]}.unit_cost", '1.2')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(Money::toMinor(1.2), $this->items[0]->fresh()->unit_cost);
        $this->assertSame(Money::toMinor(3.5), $this->items[1]->fresh()->unit_cost);

        $sq = $this->sq->fresh();
        $this->assertSame(SupplierQuotationStatus::RECEIVED, $sq->status);
        $this->assertSame('PI-0088', $sq->supplier_reference);
        $this->assertSame(['supplier_response', 'resposta.xlsx'], [$sq->documents()->sole()->type, $sq->documents()->sole()->name]);
        // O arquivo temporário da importação não fica para trás.
        $this->assertSame([], Storage::disk('local')->files('ai-imports', recursive: true));
    }

    public function test_button_is_hidden_once_the_quotation_is_selected(): void
    {
        $this->sq->update(['status' => SupplierQuotationStatus::SELECTED]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $this->sq,
            'pageClass' => ViewSupplierQuotation::class,
        ])->assertTableActionHidden('importSupplierResponse');
    }

    private function xlsx(): UploadedFile
    {
        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Item', 'Price'], ['chain 2', 3.5]]);
        $tmp = tempnam(sys_get_temp_dir(), 'resp').'.xlsx';
        (new Xlsx($ss))->save($tmp);

        return UploadedFile::fake()->createWithContent('resposta.xlsx', (string) file_get_contents($tmp));
    }
}
