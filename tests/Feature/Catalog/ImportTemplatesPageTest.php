<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\Import\GenerateProductImportTemplate;
use App\Filament\Actions\FlexibleProductImportAction;
use App\Filament\Pages\ImportTemplates;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ImportTemplatesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
    }

    private function actingWithPermissions(bool $allowed): void
    {
        $user = User::factory()->create();
        Gate::before(fn (User $u, string $ability) => $u->id === $user->id ? $allowed : null);
        $this->actingAs($user);
    }

    public function test_every_example_file_ships_with_the_app(): void
    {
        foreach (ImportTemplates::EXAMPLES as $file) {
            $this->assertFileExists(resource_path('import-templates/'.$file));
        }
    }

    public function test_page_lists_every_import_with_its_download(): void
    {
        $this->actingWithPermissions(true);

        $page = Livewire::test(ImportTemplates::class)->assertOk();

        foreach (array_keys(ImportTemplates::EXAMPLES) as $key) {
            $page->assertSee(__("import_templates.items.{$key}.title"));
        }
    }

    public function test_page_is_forbidden_without_permission(): void
    {
        $this->actingWithPermissions(false);

        Livewire::test(ImportTemplates::class)->assertForbidden();
    }

    public function test_downloads_the_example_file(): void
    {
        $this->actingWithPermissions(true);

        Livewire::test(ImportTemplates::class)
            ->call('downloadExample', 'client_products')
            ->assertFileDownloaded('03_Produtos_Cliente.xlsx');
    }

    public function test_unknown_example_is_not_found(): void
    {
        $this->actingWithPermissions(true);

        Livewire::test(ImportTemplates::class)
            ->call('downloadExample', '../../.env')
            ->assertNotFound();
    }

    public function test_downloads_the_blank_quick_import_template(): void
    {
        $this->actingWithPermissions(true);

        Livewire::test(ImportTemplates::class)
            ->call('downloadBlank', 'supplier_products', true)
            ->assertFileDownloaded('product_quick_import_supplier_and_client.xlsx');
    }

    /**
     * O modelo em branco precisa ser lido pelo Quick Import sem ajuste: aba de
     * dados ativa, cabeçalho na linha 1, até 15 colunas e cada coluna
     * reconhecida pelo mapeamento automático.
     */
    public function test_blank_templates_are_fully_auto_mapped_by_quick_import(): void
    {
        $generator = new GenerateProductImportTemplate;

        foreach (['client', 'supplier'] as $role) {
            foreach ([false, true] as $cross) {
                $spreadsheet = IOFactory::load($generator->quickImport($role, $cross));
                $sheet = $spreadsheet->getActiveSheet();
                $header = array_map('strval', $sheet->toArray(null, true, false, false)[0]);

                $this->assertSame('Products', $sheet->getTitle());
                $this->assertSame($generator->quickImportHeaders($role, $cross), $header);
                $this->assertLessThanOrEqual(15, count($header), "{$role} cross={$cross}");

                $mapped = array_unique(array_values(FlexibleProductImportAction::detectMapping($header, $role)));
                sort($mapped);
                $this->assertSame(
                    array_map('strval', array_keys($header)),
                    $mapped,
                    "{$role} cross={$cross}: toda coluna do modelo deve ser reconhecida",
                );

                $spreadsheet->disconnectWorksheets();
            }
        }
    }
}
