<?php

namespace App\Filament\Widgets;

use App\Domain\SupplierAudits\Enums\AuditResult;
use App\Domain\SupplierAudits\Enums\AuditStatus;
use App\Domain\SupplierAudits\Models\SupplierAudit;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SupplierAuditStatsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        $scheduled = SupplierAudit::where('status', AuditStatus::SCHEDULED)->count();

        $inProgress = SupplierAudit::where('status', AuditStatus::IN_PROGRESS)->count();

        $completedThisMonth = SupplierAudit::where('status', AuditStatus::COMPLETED)
            ->whereMonth('conducted_date', now()->month)
            ->whereYear('conducted_date', now()->year)
            ->count();

        $averageScore = SupplierAudit::whereNotNull('total_score')
            ->whereIn('status', [AuditStatus::COMPLETED, AuditStatus::REVIEWED])
            ->avg('total_score');

        $overdue = SupplierAudit::where('status', AuditStatus::SCHEDULED)
            ->where('scheduled_date', '<', now())
            ->count();

        $rejectedCount = SupplierAudit::where('result', AuditResult::REJECTED)
            ->whereYear('conducted_date', now()->year)
            ->count();

        return [
            Stat::make('Scheduled Audits', $scheduled + $inProgress)
                ->description($inProgress > 0 ? "{$inProgress} in progress" : 'Pending audits')
                ->color($scheduled + $inProgress > 0 ? 'info' : 'gray')
                ->icon('heroicon-o-clipboard-document-check'),

            Stat::make('Completed This Month', $completedThisMonth)
                ->description('Audits completed')
                ->color($completedThisMonth > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-check-circle'),

            Stat::make('Average Score', $averageScore ? number_format($averageScore, 2) . '/5.00' : '—')
                ->description('Across all audits')
                ->color(match (true) {
                    $averageScore === null => 'gray',
                    $averageScore >= 4.0 => 'success',
                    $averageScore >= 3.0 => 'warning',
                    default => 'danger',
                })
                ->icon('heroicon-o-chart-bar'),

            Stat::make('Overdue', $overdue)
                ->description('Past scheduled date')
                ->color($overdue > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-exclamation-triangle'),

            Stat::make('Rejected (YTD)', $rejectedCount)
                ->description('Failed audits this year')
                ->color($rejectedCount > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-x-circle'),
        ];
    }
}
