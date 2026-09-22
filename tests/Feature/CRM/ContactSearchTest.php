<?php

namespace Tests\Feature\CRM;

use App\Domain\CRM\Enums\ContactFunction;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\Contact;
use App\Filament\Resources\CRM\Contacts\Pages\ListContacts;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Livewire\GlobalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Contato só era pesquisável dentro da aba da empresa dele. Agora a busca
 * global (Ctrl+K) encontra a empresa pelo contato, e existe uma lista geral
 * de Contatos no CRM, só consulta.
 */
class ContactSearchTest extends TestCase
{
    use RefreshDatabase;

    private Company $impex;

    private Contact $zhang;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);

        $this->impex = Company::factory()->create(['name' => 'Impex Electronic']);
        $this->zhang = Contact::create([
            'company_id' => $this->impex->id,
            'name' => 'Zhang Wei',
            'email' => 'zhang.wei@example.cn',
            'phone' => '+86 138 0000 1234',
            'wechat' => 'zhangwei88',
            'function' => ContactFunction::SALES,
            'is_primary' => true,
        ]);

        $other = Company::factory()->create(['name' => 'Other Trading']);
        Contact::create(['company_id' => $other->id, 'name' => 'Maria Silva', 'email' => 'maria@other.com', 'function' => ContactFunction::FINANCE]);
    }

    public function test_global_search_finds_the_company_by_contact_name_phone_email_or_wechat(): void
    {
        foreach (['Zhang', '138 0000', 'zhang.wei@', 'zhangwei88'] as $term) {
            Livewire::test(GlobalSearch::class)
                ->set('search', $term)
                ->assertSee('Impex Electronic')
                ->assertSee('Zhang Wei')
                ->assertDontSee('Other Trading');
        }
    }

    public function test_contacts_list_searches_across_all_companies(): void
    {
        Livewire::test(ListContacts::class)
            ->assertCanSeeTableRecords([$this->zhang])
            ->searchTable('Maria')
            ->assertCanNotSeeTableRecords([$this->zhang])
            ->searchTable('138 0000')
            ->assertCanSeeTableRecords([$this->zhang])
            ->searchTable('Impex')
            ->assertCanSeeTableRecords([$this->zhang])
            ->searchTable('zhangwei88')
            ->assertCanSeeTableRecords([$this->zhang]);
    }

    public function test_contacts_list_filters_by_department_and_links_to_the_company(): void
    {
        Livewire::test(ListContacts::class)
            ->filterTable('function', ContactFunction::FINANCE->value)
            ->assertCanNotSeeTableRecords([$this->zhang])
            ->resetTableFilters()
            ->assertTableActionHasUrl('open_company', \App\Filament\Resources\CRM\Companies\CompanyResource::getUrl('view', ['record' => $this->impex->id]), $this->zhang);
    }

    public function test_contacts_list_is_read_only(): void
    {
        Livewire::test(ListContacts::class)
            ->assertActionDoesNotExist('create')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');
    }
}
