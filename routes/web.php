<?php

use App\Http\Controllers\DocumentVersionDownloadController;
use App\Http\Controllers\FileDownloadController;
use App\Http\Controllers\PortalDocumentDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/documents/versions/{version}/download', DocumentVersionDownloadController::class)
    ->name('document-version.download')
    ->middleware(['auth', 'signed']);

Route::get('/files/download', FileDownloadController::class)
    ->name('file.download')
    ->middleware(['auth', 'signed']);

Route::get('/portal/documents/{document}/download', PortalDocumentDownloadController::class)
    ->name('portal.documents.download')
    ->middleware(['auth']);

// TEMPORARY DEBUG ROUTE - REMOVE AFTER USE
Route::get('/debug/pi-schedule/{ref}/{token}', function (string $ref, string $token) {
    if ($token !== 'impex2026debug') {
        abort(403);
    }

    $pi = \App\Domain\ProformaInvoices\Models\ProformaInvoice::where('reference', 'like', "%{$ref}%")->first();
    if (! $pi) {
        return response("PI not found for ref: {$ref}", 404);
    }

    $output = "PI: {$pi->reference} (ID: {$pi->id}) | Total: {$pi->total}\n";
    $output .= str_repeat('=', 100) . "\n\n";

    $items = \App\Domain\Financial\Models\PaymentScheduleItem::where('payable_type', get_class($pi))
        ->where('payable_id', $pi->id)
        ->orderBy('sort_order')
        ->get();

    foreach ($items as $item) {
        $shipmentTag = $item->shipment_id ? "[shipment_id:{$item->shipment_id}]" : "[BASE]";
        $stageTag = $item->payment_term_stage_id ? "stage_id:{$item->payment_term_stage_id}" : "no_stage";
        $amountMajor = number_format($item->amount / 10000, 2, '.', ',');
        $paidMajor = number_format($item->paid_amount / 10000, 2, '.', ',');
        $remainMajor = number_format($item->remaining_amount / 10000, 2, '.', ',');

        $output .= "Item #{$item->id} {$shipmentTag} ({$stageTag})\n";
        $output .= "  Label: {$item->label}\n";
        $output .= "  {$item->percentage}% | Amount: {$amountMajor} | Paid: {$paidMajor} | Remaining: {$remainMajor} | Status: {$item->status->value}\n";

        $allocs = $item->allocations()->with('payment')->get();
        if ($allocs->isNotEmpty()) {
            foreach ($allocs as $a) {
                $paymentStatus = $a->payment ? $a->payment->status->value : 'PAYMENT_DELETED';
                $allocMajor = number_format($a->allocated_amount / 10000, 2, '.', ',');
                $output .= "    Alloc #{$a->id} -> payment #{$a->payment_id} ({$paymentStatus}) = {$allocMajor}\n";
            }
        }
        $output .= "\n";
    }

    return response($output, 200, ['Content-Type' => 'text/plain']);
});

// TEMPORARY AUDIT ROUTE - REMOVE AFTER USE
Route::get('/debug/pi-audit-all/{token}', function (string $token) {
    if ($token !== 'impex2026debug') {
        abort(403);
    }

    $pis = \App\Domain\ProformaInvoices\Models\ProformaInvoice::orderBy('reference')->get();
    $piClass = \App\Domain\ProformaInvoices\Models\ProformaInvoice::class;
    $output = "PI SCHEDULE AUDIT - ALL PROFORMA INVOICES\n";
    $output .= str_repeat('=', 120) . "\n\n";

    foreach ($pis as $pi) {
        $items = \App\Domain\Financial\Models\PaymentScheduleItem::where('payable_type', $piClass)
            ->where('payable_id', $pi->id)
            ->get();

        if ($items->isEmpty()) {
            continue;
        }

        $piTotal = $pi->total / 10000;
        $scheduleTotal = $items->where('is_credit', false)->sum('amount') / 10000;
        $schedulePaid = $items->sum(fn ($i) => $i->paid_amount) / 10000;
        $scheduleRemaining = $items->where('is_credit', false)->sum(fn ($i) => $i->remaining_amount) / 10000;

        $issues = [];

        $expectedTotal = $piTotal;
        if (abs($scheduleTotal - $expectedTotal) > 1.00) {
            $issues[] = "TOTAL MISMATCH: schedule={$scheduleTotal} vs expected={$expectedTotal} (diff=" . round($scheduleTotal - $expectedTotal, 2) . ")";
        }

        $stageShipmentCombos = [];
        foreach ($items as $item) {
            if (! $item->payment_term_stage_id) continue;
            $key = "stage:{$item->payment_term_stage_id}_ship:" . ($item->shipment_id ?? 'base');
            $stageShipmentCombos[$key][] = $item;
        }
        foreach ($stageShipmentCombos as $key => $group) {
            if (count($group) > 1) {
                $ids = implode(',', array_map(fn ($i) => '#' . $i->id, $group));
                $issues[] = "DUPLICATE {$key}: items {$ids}";
            }
        }

        $baseStages = $items->whereNull('shipment_id')->whereNotNull('payment_term_stage_id')->pluck('payment_term_stage_id')->all();
        $shipStages = $items->whereNotNull('shipment_id')->whereNotNull('payment_term_stage_id')->pluck('payment_term_stage_id')->unique()->all();
        $overlapping = array_intersect($baseStages, $shipStages);
        foreach ($overlapping as $stageId) {
            $baseItem = $items->whereNull('shipment_id')->where('payment_term_stage_id', $stageId)->first();
            if ($baseItem && ! str_contains($baseItem->label ?? '', '[remaining]')) {
                $issues[] = "OVERLAP stage:{$stageId} has both BASE #{$baseItem->id} and shipment-specific items";
            }
        }

        foreach ($items as $item) {
            if ($item->status->value === 'paid' && $item->remaining_amount > 100) {
                $remainMajor = number_format($item->remaining_amount / 10000, 2);
                $issues[] = "WRONG STATUS #{$item->id}: status=paid but remaining={$remainMajor}";
            }
        }

        foreach ($items as $item) {
            $orphans = $item->allocations()->with('payment')->get()->filter(fn ($a) => ! $a->payment || $a->payment->trashed());
            if ($orphans->isNotEmpty()) {
                $ids = $orphans->pluck('id')->implode(',');
                $issues[] = "ORPHAN ALLOCS on #{$item->id}: alloc IDs {$ids}";
            }
        }

        $status = empty($issues) ? 'OK' : 'ISSUES FOUND';
        $output .= "{$pi->reference} (ID:{$pi->id}) | PI Total: " . number_format($piTotal, 2) . " | Schedule Total: " . number_format($scheduleTotal, 2) . " | Paid: " . number_format($schedulePaid, 2) . " | Remaining: " . number_format($scheduleRemaining, 2) . " | [{$status}]\n";

        if (! empty($issues)) {
            foreach ($issues as $issue) {
                $output .= "  !! {$issue}\n";
            }

            foreach ($items as $item) {
                $tag = $item->shipment_id ? "[ship:{$item->shipment_id}]" : "[BASE]";
                $stg = $item->payment_term_stage_id ? "stg:{$item->payment_term_stage_id}" : "no_stg";
                $amt = number_format($item->amount / 10000, 2);
                $paid = number_format($item->paid_amount / 10000, 2);
                $rem = number_format($item->remaining_amount / 10000, 2);
                $credit = $item->is_credit ? ' [CREDIT]' : '';
                $output .= "    #{$item->id} {$tag} ({$stg}) {$item->label}{$credit} | Amt:{$amt} Paid:{$paid} Rem:{$rem} | {$item->status->value}\n";
            }
            $output .= "\n";
        }
    }

    return response($output, 200, ['Content-Type' => 'text/plain']);
});

// TEMPORARY FIX ROUTE - Regenerate all PI schedules to fix duplicates and statuses
Route::get('/debug/pi-fix-all/{token}', function (string $token) {
    if ($token !== 'impex2026debug') {
        abort(403);
    }

    $output = "PI SCHEDULE FIX - REGENERATING ALL\n";
    $output .= str_repeat('=', 100) . "\n\n";

    $piClass = \App\Domain\ProformaInvoices\Models\ProformaInvoice::class;
    $pis = \App\Domain\ProformaInvoices\Models\ProformaInvoice::orderBy('reference')
        ->whereHas('paymentScheduleItems')
        ->get();
    $action = app(\App\Domain\Financial\Actions\GeneratePaymentScheduleAction::class);

    foreach ($pis as $pi) {
        if (! $pi->payment_term_id) {
            $output .= "{$pi->reference}: SKIPPED (no payment term)\n";
            continue;
        }

        $beforeCount = \App\Domain\Financial\Models\PaymentScheduleItem::where('payable_type', $piClass)
            ->where('payable_id', $pi->id)->count();

        $action->regenerate($pi);

        $afterCount = \App\Domain\Financial\Models\PaymentScheduleItem::where('payable_type', $piClass)
            ->where('payable_id', $pi->id)->count();

        $diff = $afterCount - $beforeCount;
        $diffLabel = $diff > 0 ? "+{$diff}" : (string) $diff;
        $output .= "{$pi->reference}: regenerated ({$beforeCount} -> {$afterCount} items, {$diffLabel})\n";
    }

    return response($output, 200, ['Content-Type' => 'text/plain']);
});
