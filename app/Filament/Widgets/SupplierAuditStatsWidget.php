<?php

namespace App\Filament\Widgets;

use App\Domain\SupplierAudits\Enums\AuditResult;
use App\Domain\SupplierAudits\Enums\AuditStatus;
use App\Domain\SupplierAudits\Models\SupplierAudit;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SupplierAuditStatsWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    protected static bool $isLazy = true;

    public static function canView(): bool
    {
        return auth()->user()?->can('view-supplier-audits') ?? false;
    }

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
            Stat::make(__('widgets.audit_stats.scheduled_audits'), $scheduled + $inProgress)
                ->description($inProgress > 0 ? "{$inProgress} " . __('widgets.audit_stats.in_progress') : __('widgets.audit_stats.pending_audits'))
                ->color($scheduled + $inProgress > 0 ? 'info' : 'gray')
                ->icon('heroicon-o-clipboard-document-check'),

            Stat::make(__('widgets.audit_stats.completed_this_month'), $completedThisMonth)
                ->description(__('forms.descriptions.audits_completed'))
                ->color($completedThisMonth > 0 ? 'success' : 'gray')
                ->icon('heroicon-o-check-circle'),

            Stat::make(__('widgets.audit_stats.average_score'), $averageScore ? number_format($averageScore, 2) . '/5.00' : '—')
                ->description(__('forms.descriptions.across_all_audits'))
                ->color(match (true) {
                    $averageScore === null => 'gray',
                    $averageScore >= 4.0 => 'success',
                    $averageScore >= 3.0 => 'warning',
                    default => 'danger',
                })
                ->icon('heroicon-o-chart-bar'),

            Stat::make(__('widgets.audit_stats.overdue'), $overdue)
                ->description(__('forms.descriptions.past_scheduled_date'))
                ->color($overdue > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-exclamation-triangle'),

            Stat::make(__('widgets.audit_stats.rejected_ytd'), $rejectedCount)
                ->description(__('forms.descriptions.failed_audits_this_year'))
                ->color($rejectedCount > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-x-circle'),
        ];
    }
}
