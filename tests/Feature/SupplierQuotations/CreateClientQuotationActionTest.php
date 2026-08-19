<?php

namespace Tests\Feature\SupplierQuotations;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\CompanyRoleAssignment;
use App\Domain\CRM\Models\Contact;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\Quotations\Actions\CreateQuotationFromSupplierQuotationAction;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\SupplierQuotations\Pages\ViewSupplierQuotation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class CreateClientQuotationActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

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

    private function buildSq(
        bool $withPricedItem = true,
        SupplierQuotationStatus $status = SupplierQuotationStatus::RECEIVED,
    ): SupplierQuotation {
        $client = Company::factory()->create();
        // O select de cliente do modal filtra por papel (Company::withRole).
        CompanyRoleAssignment::create([
            'company_id' => $client->id,
            'role' => CompanyRole::CLIENT,
        ]);
        $inquiry = Inquiry::factory()->create([
            'company_id' => $client->id,
            'currency_code' => 'USD',
        ]);
        $product = Product::factory()->create();
        InquiryItem::create([
            'inquiry_id' => $inquiry->id,
            'product_id' => $product->id,
            'quantity' => 100,
            'sort_order' => 0,
        ]);

        $sq = SupplierQuotation::create([
            'inquiry_id' => $inquiry->id,
            'company_id' => Company::factory()->create()->id,
            'currency_code' => 'USD',
            'status' => $status,
        ]);

        SupplierQuotationItem::create([
            'supplier_quotation_id' => $sq->id,
            'product_id' => $product->id,
            'quantity' => 100,
            'unit_cost' => $withPricedItem ? 100000 : 0,
        ]);

        return $sq;
    }

    /**
     * SQ cuja própria inquiry (cliente A) não tem cotação nenhuma, mas que também
     * gerou, numa rodada anterior, uma SEGUNDA inquiry — para o cliente B — com uma
     * Quotation já enviada (version 1). É o cenário exato do bug reportado: o toggle
     * "nova versão" precisa reagir a QUAL cliente está selecionado no modal, não à
     * inquiry original da SQ.
     *
     * @return array{0: SupplierQuotation, 1: Company, 2: Company, 3: Quotation}
     */
    private function buildLockedScenarioForDifferentClient(): array
    {
        $sq = $this->buildSq();
        $clientA = $sq->inquiry->company;

        $clientB = Company::factory()->create();
        CompanyRoleAssignment::create([
            'company_id' => $clientB->id,
            'role' => CompanyRole::CLIENT,
        ]);

        $inquiryB = Inquiry::factory()->create([
            'company_id' => $clientB->id,
            'currency_code' => 'USD',
            'source_supplier_quotation_id' => $sq->id,
        ]);

        $lockedQuotation = Quotation::create([
            'inquiry_id' => $inquiryB->id,
            'company_id' => $clientB->id,
            'status' => QuotationStatus::SENT,
            'version' => 1,
            'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED,
            'commission_rate' => 10,
            'validity_days' => 30,
        ]);

        return [$sq, $clientA, $clientB, $lockedQuotation];
    }

    public function test_modal_prefills_client_and_creates_quotation(): void
    {
        $sq = $this->buildSq();
        $inquiry = $sq->inquiry;

        // mountAction() + callAction() no MESMO teste do componente colide: com a action
        // já montada, callAction() tenta resolvê-la como filha de si mesma
        // (ActionNotResolvableException). Verificamos o prefill num componente Livewire
        // fresco e disparamos a criação em outro — callAction() já mount+call+unmount
        // internamente, sem precisar de mountAction() antes.
        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->mountAction('createClientQuotation')
            ->assertActionDataSet([
                'company_id' => $inquiry->company_id,
                'currency_code' => 'USD',
                // O Select de commission_type é enum-backed: fillForm() grava a string,
                // mas o componente hidrata de volta para o caso do enum ao ler o estado
                // (mesmo comportamento observado em Select::options(EnumClass::class)).
                'commission_type' => CommissionType::EMBEDDED,
                'commission_rate' => 10,
                'validity_days' => 30,
            ]);

        $test = Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->callAction('createClientQuotation', [
                'company_id' => $inquiry->company_id,
                'currency_code' => 'USD',
                'commission_type' => CommissionType::EMBEDDED->value,
                'commission_rate' => 10,
                'validity_days' => 30,
                'show_suppliers' => false,
            ]);

        $quotation = Quotation::where('inquiry_id', $inquiry->id)->first();
        $this->assertNotNull($quotation);
        $test->assertRedirect(QuotationResource::getUrl('edit', ['record' => $quotation]));
        $this->assertSame(1, $quotation->items()->count());
        $this->assertSame(110000, $quotation->items()->first()->unit_price);
        $this->assertSame(30, $quotation->validity_days);
        $this->assertSame(SupplierQuotationStatus::UNDER_ANALYSIS, $sq->fresh()->status);
    }

    public function test_action_is_hidden_when_no_item_is_priced(): void
    {
        $sq = $this->buildSq(withPricedItem: false);

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->assertActionHidden('createClientQuotation');
    }

    /**
     * O allow-list de status aceitos como fonte é o mesmo consultado pela action de
     * domínio (CreateQuotationFromSupplierQuotationAction::canBeSource()) — este teste
     * pinça os dois extremos: SELECTED/REJECTED continuam entrando na lista, REQUESTED
     * continua fora dela. Excluir SELECTED ou REJECTED da lista real deixaria este
     * teste vermelho, ao contrário do que acontecia quando a lista vivia duplicada
     * só na trait.
     */
    public function test_action_visible_for_selected_and_rejected_hidden_for_requested(): void
    {
        $selected = $this->buildSq(status: SupplierQuotationStatus::SELECTED);
        Livewire::test(ViewSupplierQuotation::class, ['record' => $selected->getKey()])
            ->assertActionVisible('createClientQuotation');

        $rejected = $this->buildSq(status: SupplierQuotationStatus::REJECTED);
        Livewire::test(ViewSupplierQuotation::class, ['record' => $rejected->getKey()])
            ->assertActionVisible('createClientQuotation');

        $requested = $this->buildSq(status: SupplierQuotationStatus::REQUESTED);
        Livewire::test(ViewSupplierQuotation::class, ['record' => $requested->getKey()])
            ->assertActionHidden('createClientQuotation');
    }

    /**
     * Regressão do bug: force_new_version precisa refletir o lock do cliente
     * SELECIONADO no modal (company_id), não o da inquiry original da SQ.
     * Falha contra o código anterior ao fix, que lia sempre $this->record->inquiry
     * sem depender de $get('company_id') — o toggle ficava preso a "oculto".
     *
     * A inquiry PRÓPRIA da SQ (cliente A) também recebe uma cotação travada aqui —
     * de propósito: sem isto, o "hidden" do cliente A passaria mesmo que o atalho
     * de reaproveitar a inquiry própria (findExistingInquiry, ramo 1) fosse apagado
     * inteiro, porque a inquiry de A não tem source_supplier_quotation_id e o ramo
     * 2 (busca por esse campo) também não a encontraria — as duas ramificações
     * dariam "não travado" pelo motivo errado. Com A também travada, o teste
     * distingue de fato os dois ramos: A precisa do ramo 1, B precisa do ramo 2.
     */
    public function test_force_new_version_toggle_reacts_to_selected_client(): void
    {
        [$sq, , $clientB] = $this->buildLockedScenarioForDifferentClient();

        Quotation::create([
            'inquiry_id' => $sq->inquiry->id,
            'company_id' => $sq->inquiry->company_id,
            'status' => QuotationStatus::SENT,
            'version' => 1,
            'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED,
            'commission_rate' => 10,
            'validity_days' => 30,
        ]);

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->mountAction('createClientQuotation')
            // Prefill aponta para o cliente A (dono da inquiry PRÓPRIA da SQ), que
            // agora também tem uma cotação travada — o toggle precisa aparecer via
            // o ramo 1 de findExistingInquiry (reaproveita a inquiry própria).
            ->assertSchemaComponentVisible('force_new_version')
            // Troca para o cliente B, dono da inquiry travada encontrada via o ramo
            // 2 (source_supplier_quotation_id) — o toggle continua visível,
            // reativamente, sem remontar a action.
            ->set('mountedActions.0.data.company_id', $clientB->id)
            ->assertSchemaComponentVisible('force_new_version');
    }

    /**
     * Cliente B tem uma inquiry travada, mas ela NÃO foi gerada por esta SQ (sem
     * source_supplier_quotation_id) — é só uma inquiry qualquer, de outro fluxo.
     * O toggle precisa continuar oculto: matar o mutante que apaga o filtro
     * `->where('source_supplier_quotation_id', ...)` de findExistingInquiry(), que
     * faria a inquiry errada (mas do cliente certo) vazar como se fosse a fonte.
     */
    public function test_force_new_version_toggle_ignores_unrelated_locked_inquiry_of_same_client(): void
    {
        $sq = $this->buildSq();

        $clientB = Company::factory()->create();
        CompanyRoleAssignment::create([
            'company_id' => $clientB->id,
            'role' => CompanyRole::CLIENT,
        ]);

        $unrelatedInquiry = Inquiry::factory()->create([
            'company_id' => $clientB->id,
            'currency_code' => 'USD',
        ]);

        Quotation::create([
            'inquiry_id' => $unrelatedInquiry->id,
            'company_id' => $clientB->id,
            'status' => QuotationStatus::SENT,
            'version' => 1,
            'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED,
            'commission_rate' => 10,
            'validity_days' => 30,
        ]);

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->mountAction('createClientQuotation')
            ->set('mountedActions.0.data.company_id', $clientB->id)
            ->assertSchemaComponentHidden('force_new_version');
    }

    /**
     * A inquiry travada do cliente B tem v1 SENT (travada) + v2 DRAFT (não travada,
     * já é o resultado normal de um "force_new_version" anterior). O toggle precisa
     * ficar oculto — reabrir o modal depois de gerar a v2 não pode convidar o
     * trader a criar uma v3 sem necessidade. Mata o mutante que troca
     * `->latest('version')` por `->oldest('version')` em clientQuotationIsLocked().
     */
    public function test_force_new_version_toggle_hidden_when_latest_version_is_draft(): void
    {
        [$sq, , $clientB] = $this->buildLockedScenarioForDifferentClient();

        $inquiryB = Inquiry::query()
            ->where('company_id', $clientB->id)
            ->where('source_supplier_quotation_id', $sq->id)
            ->firstOrFail();

        Quotation::create([
            'inquiry_id' => $inquiryB->id,
            'company_id' => $clientB->id,
            'status' => QuotationStatus::DRAFT,
            'version' => 2,
            'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED,
            'commission_rate' => 10,
            'validity_days' => 30,
        ]);

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->mountAction('createClientQuotation')
            ->set('mountedActions.0.data.company_id', $clientB->id)
            ->assertSchemaComponentHidden('force_new_version');
    }

    /**
     * Dois clientes (B e C) têm, cada um, uma inquiry gerada por ESTA MESMA SQ — só a
     * de B está travada. O toggle precisa acompanhar qual cliente está selecionado.
     * Mata o mutante que apaga `->where('company_id', $companyId)` de
     * findExistingInquiry(): sem esse filtro, a busca por
     * source_supplier_quotation_id sozinha devolveria sempre a MESMA inquiry (a
     * mais recente, de C) não importa qual cliente esteja selecionado.
     */
    public function test_force_new_version_toggle_tracks_correct_client_among_two_source_linked(): void
    {
        [$sq, , $clientB] = $this->buildLockedScenarioForDifferentClient();

        $clientC = Company::factory()->create();
        CompanyRoleAssignment::create([
            'company_id' => $clientC->id,
            'role' => CompanyRole::CLIENT,
        ]);

        $inquiryC = Inquiry::factory()->create([
            'company_id' => $clientC->id,
            'currency_code' => 'USD',
            'source_supplier_quotation_id' => $sq->id,
        ]);

        Quotation::create([
            'inquiry_id' => $inquiryC->id,
            'company_id' => $clientC->id,
            'status' => QuotationStatus::DRAFT,
            'version' => 1,
            'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED,
            'commission_rate' => 10,
            'validity_days' => 30,
        ]);

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->mountAction('createClientQuotation')
            ->set('mountedActions.0.data.company_id', $clientB->id)
            ->assertSchemaComponentVisible('force_new_version')
            ->set('mountedActions.0.data.company_id', $clientC->id)
            ->assertSchemaComponentHidden('force_new_version');
    }

    public function test_locked_client_quotation_blocks_creation_without_force_new_version(): void
    {
        [$sq, , $clientB, $lockedQuotation] = $this->buildLockedScenarioForDifferentClient();

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->callAction('createClientQuotation', [
                'company_id' => $clientB->id,
                'currency_code' => 'USD',
                'commission_type' => CommissionType::EMBEDDED->value,
                'commission_rate' => 10,
                'validity_days' => 30,
                'show_suppliers' => false,
                'force_new_version' => false,
            ]);

        $this->assertSame(
            1,
            Quotation::where('inquiry_id', $lockedQuotation->inquiry_id)->count(),
            'No new version should have been created without force_new_version.'
        );
    }

    /**
     * QuotationLockedException::getMessage() é redigida para logs, em inglês, citando
     * o parâmetro forceNewVersion — não para o trader ler. O corpo do toast precisa
     * usar a mensagem traduzida (que nomeia a saída real: marcar "Criar nova versão"),
     * nunca a mensagem crua da exceção.
     */
    public function test_locked_client_quotation_shows_translated_actionable_body(): void
    {
        [$sq, , $clientB, $lockedQuotation] = $this->buildLockedScenarioForDifferentClient();

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->callAction('createClientQuotation', [
                'company_id' => $clientB->id,
                'currency_code' => 'USD',
                'commission_type' => CommissionType::EMBEDDED->value,
                'commission_rate' => 10,
                'validity_days' => 30,
                'show_suppliers' => false,
                'force_new_version' => false,
            ]);

        $notified = collect(session('filament.notifications', []))->last();
        $this->assertNotNull($notified, 'A notification should have been sent.');

        $this->assertSame(
            __('messages.quotation_locked_needs_new_version', ['reference' => $lockedQuotation->reference]),
            $notified['body'],
        );
        $this->assertStringNotContainsString('forceNewVersion', $notified['body']);
        $this->assertStringNotContainsString('cannot be recomputed', $notified['body']);
    }

    public function test_locked_client_quotation_creates_new_version_with_force_new_version(): void
    {
        [$sq, , $clientB, $lockedQuotation] = $this->buildLockedScenarioForDifferentClient();

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->callAction('createClientQuotation', [
                'company_id' => $clientB->id,
                'currency_code' => 'USD',
                'commission_type' => CommissionType::EMBEDDED->value,
                'commission_rate' => 10,
                'validity_days' => 30,
                'show_suppliers' => false,
                'force_new_version' => true,
            ]);

        $versions = Quotation::where('inquiry_id', $lockedQuotation->inquiry_id)
            ->orderBy('version')
            ->pluck('version')
            ->all();

        $this->assertSame([1, 2], $versions);
    }

    /**
     * Sem $action->halt(), Filament considera a action bem-sucedida e desmonta o
     * modal mesmo quando o catch tratou uma falha — o trader perderia os dez campos
     * já preenchidos por um erro corrigível (ex.: marcar force_new_version).
     */
    public function test_locked_quotation_error_halts_action_keeping_modal_open(): void
    {
        [$sq, , $clientB] = $this->buildLockedScenarioForDifferentClient();

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->callAction('createClientQuotation', [
                'company_id' => $clientB->id,
                'currency_code' => 'USD',
                'commission_type' => CommissionType::EMBEDDED->value,
                'commission_rate' => 10,
                'validity_days' => 30,
                'show_suppliers' => false,
                'force_new_version' => false,
            ])
            ->assertActionHalted('createClientQuotation');
    }

    /**
     * Todo campo Select do modal (company_id, currency_code, commission_type, ...)
     * já valida server-side contra suas próprias options — um valor fora da lista
     * nunca chega à action(), fica preso na validação do schema. Para exercitar o
     * catch (\Throwable) genérico da action (não o de QuotationLockedException),
     * troca a action de domínio por um dublê que falha por um motivo que nenhum
     * Select consegue barrar (ex.: uma falha de infraestrutura).
     */
    public function test_generic_domain_error_halts_action_keeping_modal_open(): void
    {
        $sq = $this->buildSq();
        $inquiry = $sq->inquiry;

        $this->mock(CreateQuotationFromSupplierQuotationAction::class, function ($mock): void {
            $mock->shouldReceive('execute')->andThrow(new \RuntimeException('Falha simulada de infraestrutura.'));
        });

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->callAction('createClientQuotation', [
                'company_id' => $inquiry->company_id,
                'currency_code' => 'USD',
                'commission_type' => CommissionType::EMBEDDED->value,
                'commission_rate' => 10,
                'validity_days' => 30,
                'show_suppliers' => false,
            ])
            ->assertActionHalted('createClientQuotation');

        $this->assertNull(Quotation::where('inquiry_id', $inquiry->id)->first());

        // Mesmo tratamento do catch (QuotationLockedException): a mensagem real da
        // exceção (aqui, "Falha simulada de infraestrutura.") vai só para o log —
        // o toast usa um texto genérico e traduzido, nunca detalhe de infra.
        $notified = collect(session('filament.notifications', []))->last();
        $this->assertNotNull($notified, 'A notification should have been sent.');
        $this->assertSame(__('messages.error_creating_quotation_body'), $notified['body']);
        $this->assertStringNotContainsString('Falha simulada de infraestrutura', $notified['body']);
    }

    public function test_contact_options_are_ordered_by_name(): void
    {
        $sq = $this->buildSq();
        $inquiry = $sq->inquiry;

        Contact::factory()->create(['company_id' => $inquiry->company_id, 'name' => 'Zeta Souza']);
        Contact::factory()->create(['company_id' => $inquiry->company_id, 'name' => 'Alfa Lima']);

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->mountAction('createClientQuotation')
            ->assertSchemaComponentExists(
                'contact_id',
                checkComponentUsing: fn ($component) => array_values($component->getOptions()) === ['Alfa Lima', 'Zeta Souza'],
            );
    }

    public function test_commission_rate_label_has_no_duplicate_percent_sign(): void
    {
        $sq = $this->buildSq();

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->mountAction('createClientQuotation')
            ->assertSchemaComponentExists(
                'commission_rate',
                checkComponentUsing: fn ($component) => ! str_contains($component->getLabel(), '(%)'),
            );
    }

    /**
     * helperText() não expõe um getter simples — vira um componente Text aninhado
     * num child schema "below_content". Extrai o texto de lá em vez de confiar em
     * assertSee() no HTML renderizado (o snapshot completo da página não bateu com
     * o texto esperado de forma confiável neste teste, mesmo com o texto presente).
     */
    private function helperTextOf($component): ?string
    {
        $schema = $component->getChildSchema('below_content');

        if (! $schema) {
            return null;
        }

        foreach ($schema->getComponents() as $child) {
            if ($child instanceof \Filament\Schemas\Components\Text) {
                return (string) $child->getContent();
            }
        }

        return null;
    }

    public function test_show_suppliers_has_anonymization_helper_text(): void
    {
        $sq = $this->buildSq();

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->mountAction('createClientQuotation')
            ->assertSchemaComponentExists(
                'show_suppliers',
                checkComponentUsing: fn ($component) => $this->helperTextOf($component)
                    === __('forms.helpers.show_suppliers_or_anonymize_help'),
            );
    }

    public function test_force_new_version_has_dedicated_label_and_helper_text(): void
    {
        [$sq, , $clientB] = $this->buildLockedScenarioForDifferentClient();

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->mountAction('createClientQuotation')
            ->set('mountedActions.0.data.company_id', $clientB->id)
            ->assertSchemaComponentExists('force_new_version', checkComponentUsing: function ($component) {
                return $component->getLabel() === __('forms.labels.force_new_version')
                    && $this->helperTextOf($component) === __('forms.helpers.force_new_version_help');
            });
    }

    public function test_optional_selects_have_placeholders(): void
    {
        $sq = $this->buildSq();

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->mountAction('createClientQuotation')
            ->assertSchemaComponentExists(
                'contact_id',
                checkComponentUsing: fn ($c) => $c->getPlaceholder() === __('forms.placeholders.select_contact'),
            )
            ->assertSchemaComponentExists(
                'incoterm',
                checkComponentUsing: fn ($c) => $c->getPlaceholder() === __('forms.placeholders.select_incoterm'),
            )
            ->assertSchemaComponentExists(
                'payment_term_id',
                checkComponentUsing: fn ($c) => $c->getPlaceholder() === __('forms.placeholders.select_payment_term'),
            );
    }
}
