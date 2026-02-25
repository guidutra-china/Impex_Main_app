<?php

namespace App\Domain\SupplierAudits\Services;

use App\Domain\SupplierAudits\Enums\AuditResult;
use App\Domain\SupplierAudits\Models\SupplierAudit;
use App\Models\User;
use Filament\Notifications\Notification;

class AuditNotificationService
{
    public function notifyAuditScheduled(SupplierAudit $audit): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $conductor = $audit->conductor;
        if (!$conductor) {
            return;
        }

        Notification::make()
            ->title('Audit Scheduled')
            ->body("You have been assigned to audit {$audit->company->name} on {$audit->scheduled_date->format('Y-m-d')}.")
            ->icon('heroicon-o-clipboard-document-check')
            ->info()
            ->sendToDatabase($conductor);
    }

    public function notifyAuditCompleted(SupplierAudit $audit): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $admins = User::role('admin')->get();

        $color = match ($audit->result) {
            AuditResult::APPROVED => 'success',
            AuditResult::CONDITIONAL => 'warning',
            AuditResult::REJECTED => 'danger',
            default => 'info',
        };

        foreach ($admins as $admin) {
            Notification::make()
                ->title('Audit Completed')
                ->body("{$audit->company->name} scored {$audit->total_score}/5.00 — {$audit->result->getLabel()}")
                ->icon('heroicon-o-clipboard-document-check')
                ->color($color)
                ->sendToDatabase($admin);
        }
    }

    public function notifyAuditDueSoon(SupplierAudit $audit): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $conductor = $audit->conductor;
        if (!$conductor) {
            return;
        }

        $daysUntil = now()->diffInDays($audit->scheduled_date, false);

        Notification::make()
            ->title('Audit Due Soon')
            ->body("Audit for {$audit->company->name} is due in {$daysUntil} day(s).")
            ->icon('heroicon-o-clock')
            ->warning()
            ->sendToDatabase($conductor);
    }

    protected function isEnabled(): bool
    {
        return config('audit.notifications_enabled', true);
    }
}
