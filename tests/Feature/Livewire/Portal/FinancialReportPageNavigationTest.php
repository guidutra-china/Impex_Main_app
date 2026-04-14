<?php

namespace Tests\Feature\Livewire\Portal;

use App\Filament\Portal\Pages\FinancialReportPage;
use Tests\TestCase;

class FinancialReportPageNavigationTest extends TestCase
{
    public function test_financial_report_page_is_in_finance_navigation_group(): void
    {
        $this->assertSame(
            __('navigation.groups.finance'),
            FinancialReportPage::getNavigationGroup()
        );
    }
}
