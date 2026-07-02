<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\Targets\ImportTargetRegistry;
use App\Domain\AI\Import\Targets\SupplierQuotationTarget;
use App\Domain\Infrastructure\Support\Money;
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

    public function test_build_form_maps_contact_category_specs_and_photo_index(): void
    {
        $target = new SupplierQuotationTarget;

        $preview = [
            'fornecedor' => ['nome' => 'ACME', 'status' => 'novo', 'company_id' => null],
            'fornecedor_dados' => ['phone' => '+86 1', 'email' => 'a@b.com'],
            'cabecalho' => ['currency_code' => 'USD', 'incoterm' => 'FOB', 'lead_time_days' => null, 'moq' => null, 'valid_until' => null, 'supplier_reference' => null, 'notes' => null],
            'itens' => [[
                'status' => 'novo', 'product_id' => null, 'part_no' => 'P-1', 'description' => 'Widget',
                'quantity' => 2, 'unit' => 'pcs', 'unit_cost_minor' => Money::toMinor(12.50),
                'category_id' => 7, 'specifications' => 'spec', 'moq' => null, 'lead_time_days' => null, 'notes' => null,
            ]],
        ];

        $form = $target->buildForm($preview, [0 => 3]);

        $this->assertSame('ACME', $form['fornecedor']['nome']);
        $this->assertSame('+86 1', $form['fornecedor']['phone']);
        $this->assertSame(12.5, $form['itens'][0]['unit_price']);
        $this->assertSame(7, $form['itens'][0]['category_id']);
        $this->assertSame(3, $form['itens'][0]['photo_index']);
        $this->assertSame('spec', $form['itens'][0]['specifications']);
    }

    public function test_confirm_payload_converts_back_and_maps_photo_by_part_no(): void
    {
        $target = new SupplierQuotationTarget;

        $form = [
            'fornecedor' => ['nome' => 'ACME', 'status' => 'novo', 'company_id' => null, 'phone' => '+86 1'],
            'cabecalho' => ['currency_code' => 'USD', 'incoterm' => 'FOB'],
            'itens' => [
                [
                    'status' => 'novo', 'product_id' => null, 'part_no' => 'P-1', 'description' => 'Widget',
                    'quantity' => 2, 'unit' => 'pcs', 'unit_price' => 12.50, 'category_id' => 7,
                    'specifications' => 'spec', 'moq' => null, 'lead_time_days' => null, 'notes' => null, 'photo_index' => 1,
                ],
                [
                    'status' => 'novo', 'product_id' => null, 'part_no' => 'P-2', 'description' => 'No photo',
                    'quantity' => 1, 'unit' => 'pcs', 'unit_price' => 1.00, 'category_id' => null,
                    'specifications' => null, 'moq' => null, 'lead_time_days' => null, 'notes' => null, 'photo_index' => null,
                ],
            ],
        ];
        $pool = [['id' => 0, 'path' => '/tmp/a.png'], ['id' => 1, 'path' => '/tmp/b.png']];

        $payload = $target->formToConfirmPayload($form, $pool);

        $this->assertSame(Money::toMinor(12.50), $payload['preview']['itens'][0]['unit_cost_minor']);
        $this->assertSame(7, $payload['preview']['itens'][0]['category_id']);
        $this->assertSame('+86 1', $payload['preview']['fornecedor_dados']['phone']);
        // photo_index 1 -> pool[1].path, keyed by part_no; the null photo_index item contributes nothing.
        $this->assertSame(['P-1' => '/tmp/b.png'], $payload['images']);
    }

    public function test_confirm_payload_derives_status_from_ids_ignoring_tampered_values(): void
    {
        $target = new SupplierQuotationTarget;

        // The form is client-editable — a tampered 'existente' with no id must come
        // back as 'novo' so the Action's authorize() gates cannot be bypassed.
        $form = [
            'fornecedor' => ['nome' => 'ACME', 'status' => 'existente', 'company_id' => null],
            'cabecalho' => ['currency_code' => 'USD'],
            'itens' => [
                [
                    'status' => 'existente', 'product_id' => null, 'part_no' => 'P-1',
                    'description' => 'Widget', 'quantity' => 1, 'unit' => 'pcs', 'unit_price' => 1.00,
                    'category_id' => null, 'specifications' => null, 'moq' => null,
                    'lead_time_days' => null, 'notes' => null, 'photo_index' => null,
                ],
                [
                    'status' => 'novo', 'product_id' => 42, 'part_no' => 'P-2',
                    'description' => 'Linked', 'quantity' => 1, 'unit' => 'pcs', 'unit_price' => 2.00,
                    'category_id' => null, 'specifications' => null, 'moq' => null,
                    'lead_time_days' => null, 'notes' => null, 'photo_index' => null,
                ],
            ],
        ];

        $payload = $target->formToConfirmPayload($form, []);

        $this->assertSame('novo', $payload['preview']['itens'][0]['status']);
        $this->assertSame('existente', $payload['preview']['itens'][1]['status']);
        $this->assertSame('novo', $payload['preview']['fornecedor']['status']);
    }
}
