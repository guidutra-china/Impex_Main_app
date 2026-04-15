<?php

namespace App\Filament\Portal\Pages;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Filament\Pages\StatementPreview;
use BackedEnum;

class StatementsPage extends StatementPreview
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 60;

    public function mount(): void
    {
        $this->initializeStatement();
    }

    public static function getNavigationLabel(): string
    {
        return __('statements.title');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('statements.title');
    }

    protected function availableSections(): array
    {
        return ['inquiries', 'quotations', 'proforma_invoices', 'shipments'];
    }

    protected function resolveCompany(): Company
    {
        $user = auth()->user();
        abort_unless($user && $user->company_id, 403);

        return Company::findOrFail($user->company_id);
    }

    protected function resolveRole(): ?CompanyRole
    {
        return CompanyRole::CLIENT;
    }
}
