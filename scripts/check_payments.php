<?php

/**
 * Diagnostic script for Upcoming Payments widget.
 * Run via Forge Commands: php /home/forge/YOUR-DOMAIN/scripts/check_payments.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Logistics\Models\Shipment;
use Carbon\Carbon;

$today = Carbon::today();
$endOfWeek = $today->copy()->addDays(7);
$endOfMonth = $today->copy()->addDays(30);

echo "=== Payment Schedule Diagnostic ===\n";
echo "Today: " . $today->format('Y-m-d') . "\n";
echo "Week end: " . $endOfWeek->format('Y-m-d') . "\n\n";

// All non-credit pending items
$allPending = PaymentScheduleItem::where('is_credit', false)
    ->whereIn('status', [
        PaymentScheduleStatus::PENDING,
        PaymentScheduleStatus::DUE,
        PaymentScheduleStatus::OVERDUE,
    ])
    ->with('payable')
    ->orderBy('due_date')
    ->get();

echo "Total pending/due/overdue items (not credit): " . $allPending->count() . "\n\n";

echo "--- All Items ---\n";
echo str_pad('Type', 18) . str_pad('Payable#', 10) . str_pad('Company', 10) . str_pad('Due Date', 14) . str_pad('Status', 12) . "Amount\n";
echo str_repeat('-', 80) . "\n";

foreach ($allPending as $item) {
    $payable = $item->payable;
    $type = class_basename($item->payable_type);
    $companyId = $payable->company_id ?? 'N/A';
    $dueDate = $item->due_date ? $item->due_date->format('Y-m-d') : 'NO DATE';
    $status = $item->status->value;
    $amount = $item->remaining_amount ?? $item->amount ?? 0;

    echo str_pad($type, 18)
        . str_pad('#' . $item->payable_id, 10)
        . str_pad($companyId, 10)
        . str_pad($dueDate, 14)
        . str_pad($status, 12)
        . $amount . "\n";
}

echo "\n--- By Company ---\n";
$byCompany = $allPending->groupBy(fn ($i) => $i->payable?->company_id ?? 'N/A');
foreach ($byCompany as $companyId => $items) {
    $company = \App\Domain\CRM\Models\Company::find($companyId);
    $name = $company ? $company->name : 'Unknown';
    echo "Company #{$companyId} ({$name}): {$items->count()} items\n";

    $overdue = $items->filter(fn ($i) => $i->due_date && $i->due_date->lt($today));
    $thisWeek = $items->filter(fn ($i) => $i->due_date && $i->due_date->between($today, $endOfWeek));
    $noDue = $items->filter(fn ($i) => ! $i->due_date);

    echo "  Overdue (past due): {$overdue->count()}\n";
    echo "  Due this week: {$thisWeek->count()}\n";
    echo "  No due date: {$noDue->count()}\n";
}

echo "\n--- Payable Types ---\n";
$byType = $allPending->groupBy('payable_type');
foreach ($byType as $type => $items) {
    echo class_basename($type) . ": " . $items->count() . "\n";
}

echo "\nDone.\n";
