<?php

namespace App\Filament\Pages;

use App\Domain\CRM\Models\Company;
use BackedEnum;
use Livewire\Attributes\Url;

class AdminStatementPreview extends StatementPreview
{
    protected static BackedEnum|string|null $navigationIcon = null;

    protected static ?string $slug = 'statement';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'company')]
    public ?int $companyId = null;

    public function mount(): void
    {
        if ($this->companyId === null) {
            $this->companyId = (int) request()->query('company');
        }

        abort_unless($this->companyId > 0, 404);

        $this->initializeStatement();
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('statements.title') . ' — ' . $this->resolveCompany()->name;
    }

    protected function resolveCompany(): Company
    {
        return Company::findOrFail($this->companyId);
    }
}
