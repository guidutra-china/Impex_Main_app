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
