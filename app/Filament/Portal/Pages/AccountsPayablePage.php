<?php

namespace App\Filament\Portal\Pages;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\DataTransferObjects\AccountsPayableReport;
use App\Domain\Financial\Queries\AccountsPayableQuery;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

class AccountsPayablePage extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 55;

    protected static ?string $slug = 'accounts-payable';

    protected string $view = 'filament.portal.pages.accounts-payable';

    public string $preset = '30';

    public ?string $customFrom = null;

    public ?string $customTo = null;

    public bool $includeOverdue = true;

    public bool $includePaid = false;

    public bool $includeFreight = true;

    public bool $includeCommission = true;

    protected ?Company $resolvedCompany = null;

    public static function getNavigationLabel(): string
    {
        return __('accounts_payable.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('accounts_payable.title');
    }

    public function mount(): void
    {
        $this->resolveCompany(); // 403 early if user has no company
    }

    public function getReport(): AccountsPayableReport
    {
        $company = $this->resolveCompany();
        [$from, $to] = $this->resolveDateRange();

        return (new AccountsPayableQuery())->run(
            companyId: $company->id,
            dateFrom: $from,
            dateTo: $to,
            includePaid: $this->includePaid,
            includeOverdue: $this->includeOverdue,
            includeFreight: $this->includeFreight,
            includeCommission: $this->includeCommission,
        );
    }

    protected function resolveCompany(): Company
    {
        if ($this->resolvedCompany !== null) {
            return $this->resolvedCompany;
        }

        $user = auth()->user();
        abort_unless($user && $user->company_id, 403);

        return $this->resolvedCompany = Company::findOrFail($user->company_id);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    protected function resolveDateRange(): array
    {
        $today = CarbonImmutable::now()->startOfDay();

        return match ($this->preset) {
            '7' => [$today, $today->addDays(7)->endOfDay()],
            '90' => [$today, $today->addDays(90)->endOfDay()],
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()],
            'next_month' => [$today->addMonth()->startOfMonth(), $today->addMonth()->endOfMonth()],
            'custom' => [
                $this->customFrom ? CarbonImmutable::parse($this->customFrom)->startOfDay() : $today,
                $this->customTo ? CarbonImmutable::parse($this->customTo)->endOfDay() : $today->addDays(30)->endOfDay(),
            ],
            default => [$today, $today->addDays(30)->endOfDay()], // '30'
        };
    }
}
