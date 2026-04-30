<?php

namespace App\Filament\Portal\Pages;

use App\Domain\CRM\Models\Company;
use App\Filament\Pages\FinancialReportPreview;
use BackedEnum;

class FinancialReportPage extends FinancialReportPreview
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $slug = 'financial-report';

    protected static ?int $navigationSort = 65;

    public function mount(): void
    {
        $this->initializeReport();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('portal:view-financial-summary') ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('financial_report.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('financial_report.title');
    }

    protected function availableSections(): array
    {
        return ['proforma_invoices', 'shipments', 'payments', 'debit_notes', 'additional_costs'];
    }

    protected function resolveCompany(): Company
    {
        $user = auth()->user();
        abort_unless($user && $user->company_id, 403);

        return Company::findOrFail($user->company_id);
    }

    protected function reportContext(): string
    {
        return 'client';
    }
}
