<?php

namespace Tests\Feature\CRM;

use App\Domain\CRM\Models\Company;
use App\Filament\Resources\CRM\Companies\Pages\ListCompanies;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ícone de copiar na lista de Empresas: leva os dados cadastrais inteiros para
 * a área de transferência, para colar em documentos externos.
 */
class CompanyClipboardCopyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);
    }

    public function test_summary_has_identity_lines_then_labelled_contacts(): void
    {
        $company = Company::factory()->create([
            'name' => 'Impex',
            'legal_name' => 'Impex Electronic Co. Ltd',
            'tax_number' => '12345678',
            'phone' => '+852 1234 5678',
            'email' => 'admin@impex.ltd',
            'website' => 'https://impex.ltd',
            'address_street' => 'Witty Comm Bldg',
            'address_number' => '1A-1L',
            'address_city' => 'Mongkok',
            'address_state' => 'KL',
            'address_zip' => '999077',
            'address_country' => 'Hong Kong',
        ]);

        \App\Domain\CRM\Models\Contact::create([
            'company_id' => $company->id,
            'name' => 'Secundário',
            'phone' => '+852 0000 0000',
            'is_primary' => false,
        ]);
        \App\Domain\CRM\Models\Contact::create([
            'company_id' => $company->id,
            'name' => 'Caroline Wu',
            'phone' => '+86 138 0000 1111',
            'is_primary' => true,
        ]);

        $lines = explode("\n", $company->fresh()->clipboardSummary());

        $this->assertSame('Impex Electronic Co. Ltd', $lines[0]);
        $this->assertSame('Impex', $lines[1], 'nome fantasia entra quando difere da razão social');
        $this->assertStringContainsString('Mongkok', $lines[2]);
        $this->assertStringContainsString('999077', $lines[2], 'o CEP faz parte do endereço completo');
        $this->assertStringContainsString('Hong Kong', $lines[2]);

        $rest = implode("\n", array_slice($lines, 3));
        $this->assertStringContainsString('12345678', $rest);
        $this->assertStringContainsString('+852 1234 5678', $rest);
        $this->assertStringContainsString('admin@impex.ltd', $rest);
        $this->assertStringContainsString('https://impex.ltd', $rest);

        // Contato principal vence o secundário e sai com nome e telefone.
        $this->assertStringContainsString('Caroline Wu — +86 138 0000 1111', $rest);
        $this->assertStringNotContainsString('Secundário', $rest);
    }

    public function test_falls_back_to_any_contact_when_none_is_flagged_primary(): void
    {
        $company = Company::factory()->create(['name' => 'Sem Principal', 'legal_name' => null]);

        \App\Domain\CRM\Models\Contact::create([
            'company_id' => $company->id,
            'name' => 'Único Contato',
            'phone' => '+55 41 3333-4444',
            'is_primary' => false,
        ]);

        $this->assertStringContainsString(
            'Único Contato — +55 41 3333-4444',
            $company->fresh()->clipboardSummary(),
        );
    }

    public function test_contact_without_phone_falls_back_to_whatsapp(): void
    {
        $company = Company::factory()->create(['legal_name' => null]);

        \App\Domain\CRM\Models\Contact::create([
            'company_id' => $company->id,
            'name' => 'Só WhatsApp',
            'phone' => null,
            'whatsapp' => '+86 555 000',
            'is_primary' => true,
        ]);

        $this->assertStringContainsString('Só WhatsApp — +86 555 000', $company->fresh()->clipboardSummary());
    }

    public function test_summary_skips_empty_fields_and_does_not_repeat_the_name(): void
    {
        $company = Company::factory()->create([
            'name' => 'Só Nome',
            'legal_name' => null,
            'tax_number' => null,
            'phone' => null,
            'email' => null,
            'website' => null,
            'address_street' => null,
            'address_number' => null,
            'address_complement' => null,
            'address_city' => null,
            'address_state' => null,
            'address_zip' => null,
            'address_country' => null,
        ]);

        $this->assertSame('Só Nome', $company->clipboardSummary());
    }

    public function test_list_renders_the_copy_icon_and_the_full_payload(): void
    {
        $company = Company::factory()->create([
            'name' => 'Copiável SA',
            'legal_name' => 'Copiável Comércio Ltda',
            'tax_number' => '99887766',
            'address_city' => 'Curitiba',
            'address_zip' => '80000-000',
        ]);

        $html = Livewire::test(ListCompanies::class)
            ->assertCanRenderTableColumn('copy_company_data')
            ->html();

        $cell = substr($html, (int) strpos($html, 'fi-copyable'), 2000);

        // Sem texto na célula o ícone ainda precisa sair — é a única coisa
        // visível da coluna, e um estado realmente vazio o suprimiria.
        $this->assertStringContainsString('<svg', $cell, 'o ícone de copiar precisa renderizar');
        $this->assertStringContainsString('clipboard.writeText', $cell);

        // O payload copiado carrega os dados cadastrais, não só o nome.
        // Acentos saem escapados por Js::from(); o clipboard recebe o texto real.
        foreach (['99887766', 'Curitiba', '80000-000', 'Tax ID'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $cell,
                "o texto copiado deve conter {$needle}",
            );
        }
    }
}
