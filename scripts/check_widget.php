<?php

/**
 * Diagnostic: simulates the Upcoming Payments widget query for each company.
 * Run: php /home/forge/app.impex.ltd/current/scripts/check_widget.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\CRM\Models\Company;
use Carbon\Carbon;

$today = Carbon::today();

// Get all companies that have payment schedule items
$companyIds = collect();

// From ProformaInvoice
$piCompanyIds = PaymentScheduleItem::where('is_credit', false)
    ->whereIn('status', [PaymentScheduleStatus::PENDING, PaymentScheduleStatus::DUE, PaymentScheduleStatus::OVERDUE])
    ->where('payable_type', (new ProformaInvoice)->getMorphClass())
    ->with('payable')
    ->get()
    ->pluck('payable.company_id')
    ->filter()
    ->unique();

// From Shipment
$shipCompanyIds = PaymentScheduleItem::where('is_credit', false)
    ->whereIn('status', [PaymentScheduleStatus::PENDING, PaymentScheduleStatus::DUE, PaymentScheduleStatus::OVERDUE])
    ->where('payable_type', (new Shipment)->getMorphClass())
    ->with('payable')
    ->get()
    ->pluck('payable.company_id')
    ->filter()
    ->unique();

$allCompanyIds = $piCompanyIds->merge($shipCompanyIds)->unique();

echo "=== Widget Diagnostic ===\n";
echo "Today: " . $today->format('Y-m-d') . "\n";
echo "Companies with PI/Shipment payment items: " . $allCompanyIds->join(', ') . "\n\n";

// Check morph map
echo "--- Morph Classes ---\n";
echo "ProformaInvoice: " . (new ProformaInvoice)->getMorphClass() . "\n";
echo "Shipment: " . (new Shipment)->getMorphClass() . "\n\n";

foreach ($allCompanyIds as $companyId) {
    $company = Company::find($companyId);
    echo "--- Company #{$companyId}: " . ($company->name ?? 'Unknown') . " ---\n";

    $baseQuery = PaymentScheduleItem::query()
        ->with('payable')
        ->where('is_credit', false)
        ->whereIn('status', [
            PaymentScheduleStatus::PENDING,
            PaymentScheduleStatus::DUE,
            PaymentScheduleStatus::OVERDUE,
        ])
        ->where(function ($query) use ($companyId) {
            $query->whereHasMorph('payable', [ProformaInvoice::class], function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            })->orWhereHasMorph('payable', [Shipment::class], function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        });

    $total = (clone $baseQuery)->count();

    $overdue = (clone $baseQuery)
        ->where(function ($query) use ($today) {
            $query->where('status', PaymentScheduleStatus::OVERDUE)
                ->orWhere(function ($q) use ($today) {
                    $q->whereNotNull('due_date')
                        ->where('due_date', '<', $today)
                        ->whereIn('status', [PaymentScheduleStatus::PENDING, PaymentScheduleStatus::DUE]);
                });
        })
        ->count();

    $noDueDate = (clone $baseQuery)
        ->whereNull('due_date')
        ->whereIn('status', [PaymentScheduleStatus::PENDING, PaymentScheduleStatus::DUE])
        ->count();

    echo "  Total matching items: {$total}\n";
    echo "  Overdue (past due or OVERDUE status): {$overdue}\n";
    echo "  Pending (no due date): {$noDueDate}\n";
    echo "  hasAny: " . ($total > 0 ? 'YES' : 'NO') . "\n\n";
}

echo "Done.\n";
