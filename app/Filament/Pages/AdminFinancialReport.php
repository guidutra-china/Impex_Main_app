<?php

namespace App\Filament\Pages;

use App\Domain\CRM\Models\Company;
use App\Filament\Resources\CRM\Companies\CompanyResource;
use BackedEnum;
use Filament\Actions\Action;
use Livewire\Attributes\Url;

class AdminFinancialReport extends FinancialReportPreview
{
    protected static BackedEnum|string|null $navigationIcon = null;

    protected static ?string $slug = 'financial-report';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'company')]
    public ?int $companyId = null;

    public function mount(): void
    {
        if ($this->companyId === null) {
            $this->companyId = (int) request()->query('company');
        }

        abort_unless($this->companyId > 0, 404);

        $this->initializeReport();
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('financial_report.title').' — '.$this->resolveCompany()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('financial_report.actions.back'))
                ->url(CompanyResource::getUrl('view', ['record' => $this->companyId]))
                ->color('gray')
                ->icon('heroicon-o-arrow-left'),
        ];
    }

    protected function resolveCompany(): Company
    {
        return Company::findOrFail($this->companyId);
    }

    protected function reportContext(): string
    {
        return 'admin';
    }

    public function shouldShowExcelExport(): bool
    {
        return true;
    }
}
