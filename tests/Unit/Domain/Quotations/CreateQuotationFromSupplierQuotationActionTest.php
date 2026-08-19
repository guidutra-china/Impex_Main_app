<?php

namespace Tests\Unit\Domain\Quotations;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\Quotations\Actions\CreateQuotationFromSupplierQuotationAction;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Exceptions\QuotationLockedException;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateQuotationFromSupplierQuotationActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $usd = Currency::create([
            'code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars',
            'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true,
        ]);
        $cny = Currency::create([
            'code' => 'CNY', 'name' => 'Chinese Yuan', 'name_plural' => 'Chinese Yuan',
            'symbol' => '¥', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);
        ExchangeRate::create([
            'base_currency_id' => $usd->id, 'target_currency_id' => $cny->id,
            'rate' => 7.0, 'inverse_rate' => 1 / 7.0,
            'date' => today()->subDay()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);
    }

    private function makeAction(): CreateQuotationFromSupplierQuotationAction
    {
        return app(CreateQuotationFromSupplierQuotationAction::class);
    }

    /**
     * Inquiry com 1 item pedido pelo cliente + SQ do fornecedor cotando esse
     * item e oferecendo 1 opção extra que não estava na inquiry.
     *
     * @return array{0: Company, 1: Inquiry, 2: SupplierQuotation, 3: Product, 4: Product}
     */
    private function buildScenario(SupplierQuotationStatus $sqStatus = SupplierQuotationStatus::RECEIVED): array
    {
        $client = Company::factory()->create();
        $supplier = Company::factory()->create();
        $inquiry = Inquiry::factory()->create([
            'company_id' => $client->id,
            'currency_code' => 'USD',
        ]);

        $asked = Product::factory()->create();
        InquiryItem::create([
            'inquiry_id' => $inquiry->id,
            'product_id' => $asked->id,
            'quantity' => 100,
            'sort_order' => 0,
        ]);

        $sq = SupplierQuotation::create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $supplier->id,
            'currency_code' => 'USD',
            'status' => $sqStatus,
            'incoterm' => Incoterm::FOB,
        ]);

        SupplierQuotationItem::create([
            'supplier_quotation_id' => $sq->id,
            'product_id' => $asked->id,
            'quantity' => 100,
            'unit_cost' => 100000,
        ]);

        $extra = Product::factory()->create();
        SupplierQuotationItem::create([
            'supplier_quotation_id' => $sq->id,
            'product_id' => $extra->id,
            'description' => 'Opção extra do fornecedor',
            'quantity' => 20,
            'unit_cost' => 200000,
        ]);

        return [$client, $inquiry, $sq, $asked, $extra];
    }

    public function test_extra_supplier_item_reaches_the_quotation_with_commission(): void
    {
        [$client, $inquiry, $sq, $asked, $extra] = $this->buildScenario();

        $quotation = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
        );

        $this->assertSame(QuotationStatus::DRAFT, $quotation->status);
        $this->assertSame($client->id, $quotation->company_id);
        $this->assertSame($inquiry->id, $quotation->inquiry_id);
        $this->assertSame(2, $quotation->items()->count());

        $extraItem = $quotation->items()->where('product_id', $extra->id)->first();
        $this->assertNotNull($extraItem);
        $this->assertSame(200000, $extraItem->unit_cost);
        // 10% embutidos: 200000 * 1.10
        $this->assertSame(220000, $extraItem->unit_price);
        $this->assertSame($sq->company_id, $extraItem->selected_supplier_id);
    }

    public function test_applies_payment_term_validity_and_incoterm_on_header(): void
    {
        [$client, $inquiry, $sq] = $this->buildScenario();
        $paymentTerm = \App\Domain\Settings\Models\PaymentTerm::create([
            'name' => '30% adiantado / 70% contra cópia do BL',
            'is_active' => true,
        ]);

        $quotation = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
            showSuppliers: true,
            incoterm: Incoterm::FOB,
            paymentTermId: $paymentTerm->id,
            validityDays: 45,
        );

        $this->assertSame($paymentTerm->id, $quotation->payment_term_id);
        $this->assertSame(45, $quotation->validity_days);
        $this->assertSame(today()->addDays(45)->toDateString(), $quotation->valid_until->toDateString());
        $this->assertTrue($quotation->show_suppliers);
        $this->assertSame(Incoterm::FOB, $quotation->incoterm);
    }

    public function test_advances_supplier_quotation_to_under_analysis(): void
    {
        [$client, $inquiry, $sq] = $this->buildScenario();

        $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
        );

        $this->assertSame(SupplierQuotationStatus::UNDER_ANALYSIS, $sq->fresh()->status);
    }

    public function test_locked_quotation_without_force_rolls_everything_back(): void
    {
        [$client, $inquiry, $sq, $asked, $extra] = $this->buildScenario();

        Quotation::create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'status' => QuotationStatus::SENT,
            'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED,
            'commission_rate' => 0,
        ]);

        $itemsBefore = $inquiry->items()->count();

        try {
            $this->makeAction()->execute(
                sq: $sq,
                companyId: $client->id,
                contactId: null,
                currencyCode: 'USD',
                commissionType: CommissionType::EMBEDDED,
                commissionRate: 10,
            );
            $this->fail('Expected QuotationLockedException.');
        } catch (QuotationLockedException $e) {
            // esperado
        }

        // Rollback total: nem o item extra na inquiry, nem a transição da SQ.
        $this->assertSame($itemsBefore, $inquiry->fresh()->items()->count());
        $this->assertSame(SupplierQuotationStatus::RECEIVED, $sq->fresh()->status);
        $this->assertSame(1, Quotation::count());
    }

    public function test_currency_override_converts_costs(): void
    {
        [$client, $inquiry, $sq, $asked] = $this->buildScenario();

        $quotation = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'CNY',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
        );

        $this->assertSame('CNY', $quotation->currency_code);
        $askedItem = $quotation->items()->where('product_id', $asked->id)->first();
        $this->assertSame(700000, $askedItem->unit_price);
        $this->assertSame('USD', $inquiry->fresh()->currency_code);
    }

    public function test_cross_client_creates_separate_inquiry_and_still_prices_from_the_sq(): void
    {
        [$originalClient, $originalInquiry, $sq, $asked, $extra] = $this->buildScenario();
        $newClient = Company::factory()->create();
        $itemsBeforeOriginal = $originalInquiry->items()->count();

        $quotation = $this->makeAction()->execute(
            sq: $sq,
            companyId: $newClient->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
        );

        // Uma nova Inquiry foi criada para o novo cliente, rastreada até a SQ.
        $newInquiry = Inquiry::query()
            ->where('company_id', $newClient->id)
            ->where('source_supplier_quotation_id', $sq->id)
            ->first();
        $this->assertNotNull($newInquiry);

        // A inquiry original do cliente A e o vínculo da SQ com ela ficam intocados.
        $this->assertSame($itemsBeforeOriginal, $originalInquiry->fresh()->items()->count());
        $this->assertSame($originalInquiry->id, $sq->fresh()->inquiry_id);

        // A Quotation pertence ao NOVO cliente/inquiry e carrega os 2 itens da SQ.
        $this->assertSame($newClient->id, $quotation->company_id);
        $this->assertSame($newInquiry->id, $quotation->inquiry_id);
        $this->assertSame(2, $quotation->items()->count());

        $askedItem = $quotation->items()->where('product_id', $asked->id)->first();
        $extraItem = $quotation->items()->where('product_id', $extra->id)->first();
        $this->assertNotNull($askedItem);
        $this->assertNotNull($extraItem);

        // 10% embutidos, mesma moeda (USD): unit_cost * 1.10
        $this->assertSame(100000, $askedItem->unit_cost);
        $this->assertSame(110000, $askedItem->unit_price);
        $this->assertSame(200000, $extraItem->unit_cost);
        $this->assertSame(220000, $extraItem->unit_price);

        // Prova que o preço sobrevive ao pool vazio de $inquiry->supplierQuotations():
        // a única SQ considerada foi a $sq->id empurrada manualmente.
        $this->assertSame($sq->company_id, $askedItem->selected_supplier_id);
        $this->assertSame($sq->company_id, $extraItem->selected_supplier_id);
    }

    public function test_cross_client_second_call_reuses_the_forked_inquiry(): void
    {
        [$originalClient, $originalInquiry, $sq] = $this->buildScenario();
        $newClient = Company::factory()->create();

        $firstQuotation = $this->makeAction()->execute(
            sq: $sq,
            companyId: $newClient->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
        );

        $this->assertSame(1, Inquiry::where('company_id', $newClient->id)->count());

        $secondQuotation = $this->makeAction()->execute(
            sq: $sq->fresh(),
            companyId: $newClient->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
        );

        // Nenhuma segunda Inquiry foi mintada para o mesmo cliente/SQ.
        $this->assertSame(1, Inquiry::where('company_id', $newClient->id)->count());
        $this->assertSame($firstQuotation->inquiry_id, $secondQuotation->inquiry_id);
    }

    public function test_requested_source_sq_is_rejected(): void
    {
        [$client, $inquiry, $sq] = $this->buildScenario(SupplierQuotationStatus::REQUESTED);

        $this->expectException(\InvalidArgumentException::class);

        $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
        );
    }

    public function test_expired_source_sq_is_rejected(): void
    {
        [$client, $inquiry, $sq] = $this->buildScenario(SupplierQuotationStatus::EXPIRED);

        $this->expectException(\InvalidArgumentException::class);

        $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
        );
    }

    public function test_rejected_source_sq_still_works(): void
    {
        // Retomar uma SQ rejeitada para gerar cotação ao cliente é caso de uso real
        // (ex.: o trader decide usar os preços mesmo após rejeitar formalmente).
        [$client, $inquiry, $sq, $asked, $extra] = $this->buildScenario(SupplierQuotationStatus::REJECTED);

        $quotation = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
        );

        $this->assertSame(2, $quotation->items()->count());
        $askedItem = $quotation->items()->where('product_id', $asked->id)->first();
        $this->assertSame(100000, $askedItem->unit_cost);
        $this->assertSame($sq->company_id, $askedItem->selected_supplier_id);

        // Status REJECTED não é RECEIVED: nenhuma transição é tentada.
        $this->assertSame(SupplierQuotationStatus::REJECTED, $sq->fresh()->status);
    }

    public function test_null_validity_days_defaults_to_30_and_sets_valid_until(): void
    {
        [$client, $inquiry, $sq] = $this->buildScenario();

        $quotation = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
        );

        $this->assertSame(30, $quotation->validity_days);
        $this->assertNotNull($quotation->valid_until);
        $this->assertSame(today()->addDays(30)->toDateString(), $quotation->valid_until->toDateString());
    }

    public function test_null_validity_days_on_rerun_resets_a_previously_set_value_to_the_default(): void
    {
        // Pinado deliberadamente: diferente de contactId/incoterm/paymentTermId (nulo
        // preserva o valor gravado), validityDays nulo NÃO preserva — sempre volta para
        // o default de 30. Assimétrico de propósito (ver docblock de execute()), mas sem
        // este teste o próximo leitor pode "corrigir" isso para preservar.
        [$client, $inquiry, $sq] = $this->buildScenario();

        $quotation = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
            validityDays: 45,
        );
        $this->assertSame(45, $quotation->validity_days);

        $quotation2 = $this->makeAction()->execute(
            sq: $sq->fresh(),
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
            // validityDays omitido nesta rodada.
        );

        $this->assertSame($quotation->id, $quotation2->id, 'atualiza no lugar, mesma Quotation');
        $this->assertSame(30, $quotation2->validity_days);
        $this->assertSame(today()->addDays(30)->toDateString(), $quotation2->valid_until->toDateString());
    }

    public function test_unchanged_commission_rate_preserves_manually_edited_item_rate(): void
    {
        [$client, $inquiry, $sq, $asked, $extra] = $this->buildScenario();

        $quotation = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
        );

        $askedItem = $quotation->items()->where('product_id', $asked->id)->first();
        $askedItem->update(['commission_rate' => 40]); // ajuste manual no item

        $quotation2 = $this->makeAction()->execute(
            sq: $sq->fresh(),
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10, // mesma taxa da rodada anterior
        );

        $refreshed = $quotation2->items()->where('product_id', $asked->id)->first();
        $this->assertEqualsWithDelta(40.0, (float) $refreshed->commission_rate, 0.01);
        // 100000 × 1.40 = 140000: o ajuste manual do item se mantém.
        $this->assertSame(140000, $refreshed->unit_price);
    }

    public function test_changed_commission_rate_rewrites_all_item_rates_and_prices(): void
    {
        [$client, $inquiry, $sq, $asked, $extra] = $this->buildScenario();

        $quotation = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
        );

        $askedItem = $quotation->items()->where('product_id', $asked->id)->first();
        $askedItem->update(['commission_rate' => 40]); // será descartado: a taxa do cabeçalho muda

        $quotation2 = $this->makeAction()->execute(
            sq: $sq->fresh(),
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 25, // taxa diferente da rodada anterior (10)
        );

        $refreshedAsked = $quotation2->items()->where('product_id', $asked->id)->first();
        $refreshedExtra = $quotation2->items()->where('product_id', $extra->id)->first();

        $this->assertEqualsWithDelta(25.0, (float) $refreshedAsked->commission_rate, 0.01);
        $this->assertSame(125000, $refreshedAsked->unit_price); // 100000 × 1.25
        $this->assertEqualsWithDelta(25.0, (float) $refreshedExtra->commission_rate, 0.01);
        $this->assertSame(250000, $refreshedExtra->unit_price); // 200000 × 1.25
    }

    public function test_selected_source_sq_creates_quotation_without_transitioning_status(): void
    {
        // Mutante que a revisão pegou: `if (true)` no lugar do guard RECEIVED
        // tentaria RECEIVED->SELECTED->UNDER_ANALYSIS, uma transição inválida
        // (`[selected] → [under_analysis]`), derrubando a cotação inteira.
        [$client, $inquiry, $sq, $asked, $extra] = $this->buildScenario(SupplierQuotationStatus::SELECTED);

        $quotation = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
        );

        $this->assertSame(QuotationStatus::DRAFT, $quotation->status);
        $this->assertSame(2, $quotation->items()->count());
        $this->assertSame(SupplierQuotationStatus::SELECTED, $sq->fresh()->status);
    }

    public function test_three_sq_pool_only_includes_quotable_statuses_and_source_always_wins(): void
    {
        // Mutante que a revisão pegou: `preferredSupplierQuotationId: null` e
        // `QUOTABLE_STATUSES => []` sobrevivem quando só existe uma SQ no pool.
        // Este cenário com 3 SQs distingue os três papéis: origem (sempre vence),
        // sibling quotável mais barato (vira alternativa) e sibling REJECTED
        // (nem eleito, nem alternativa — nem é a origem, nem está em QUOTABLE_STATUSES).
        [$client, $inquiry, $sourceSq, $asked, $extra] = $this->buildScenario();
        $sourceSq->items()->where('product_id', $asked->id)->update(['unit_cost' => 300000]);

        $cheaperSibling = SupplierQuotation::create([
            'inquiry_id' => $inquiry->id,
            'company_id' => Company::factory()->create()->id,
            'currency_code' => 'USD',
            'status' => SupplierQuotationStatus::RECEIVED,
        ]);
        SupplierQuotationItem::create([
            'supplier_quotation_id' => $cheaperSibling->id,
            'product_id' => $asked->id,
            'quantity' => 100,
            'unit_cost' => 100000,
        ]);

        $rejectedCheapestSibling = SupplierQuotation::create([
            'inquiry_id' => $inquiry->id,
            'company_id' => Company::factory()->create()->id,
            'currency_code' => 'USD',
            'status' => SupplierQuotationStatus::REJECTED,
        ]);
        SupplierQuotationItem::create([
            'supplier_quotation_id' => $rejectedCheapestSibling->id,
            'product_id' => $asked->id,
            'quantity' => 100,
            'unit_cost' => 50000,
        ]);

        $quotation = $this->makeAction()->execute(
            sq: $sourceSq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
        );

        $askedItem = $quotation->items()->where('product_id', $asked->id)->first();

        // A SQ de origem vence a eleição mesmo sendo a mais cara das três.
        $this->assertSame(300000, $askedItem->unit_cost);
        $this->assertSame($sourceSq->company_id, $askedItem->selected_supplier_id);

        $alternativeCompanyIds = $askedItem->suppliers()->pluck('company_id')->all();

        // O sibling RECEIVED mais barato fica registrado como alternativa.
        $this->assertContains($cheaperSibling->company_id, $alternativeCompanyIds);

        // O sibling REJECTED (o mais barato dos três) não aparece em lugar nenhum.
        $this->assertNotContains($rejectedCheapestSibling->company_id, $alternativeCompanyIds);
        $this->assertNotSame($rejectedCheapestSibling->company_id, $askedItem->selected_supplier_id);
    }

    public function test_force_new_version_on_locked_quotation_creates_v2_and_snapshots_v1(): void
    {
        [$client, $inquiry, $sq, $asked, $extra] = $this->buildScenario();

        $v1 = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
        );
        $v1->update(['status' => QuotationStatus::SENT]);

        // Primeira chamada já avançou a SQ para UNDER_ANALYSIS; a segunda não deve
        // tentar avançar de novo (o guard só transiciona a partir de RECEIVED).
        $sqAfterFirstCall = $sq->fresh();
        $this->assertSame(SupplierQuotationStatus::UNDER_ANALYSIS, $sqAfterFirstCall->status);

        $v2 = $this->makeAction()->execute(
            sq: $sqAfterFirstCall,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 25,
            validityDays: 60,
            forceNewVersion: true,
        );

        $this->assertNotSame($v1->id, $v2->id, 'new Quotation row');
        $this->assertSame(2, $v2->version);
        $this->assertSame(QuotationStatus::DRAFT, $v2->status);
        $this->assertSame(QuotationStatus::SENT, $v1->fresh()->status, 'v1 stays sent');
        $this->assertDatabaseHas('quotation_versions', [
            'quotation_id' => $v1->id,
            'version' => 1,
        ]);
        $this->assertEqualsWithDelta(25.0, (float) $v2->commission_rate, 0.01);
        $this->assertSame(60, $v2->validity_days);
        $this->assertSame(today()->addDays(60)->toDateString(), $v2->valid_until->toDateString());

        // A SQ já estava UNDER_ANALYSIS antes desta segunda chamada; não muda de novo.
        $this->assertSame(SupplierQuotationStatus::UNDER_ANALYSIS, $sq->fresh()->status);
    }
}
