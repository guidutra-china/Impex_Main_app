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
Route::get('/debug/pi-check-00019/{token}', function (string $token) {
    if ($token !== 'impex2026debug') {
        abort(403);
    }

    $pi = \App\Domain\ProformaInvoices\Models\ProformaInvoice::where('reference', 'like', '%00019%')->first();
    if (! $pi) {
        return response('PI not found', 404);
    }

    $output = "PI: {$pi->reference} (ID: {$pi->id})\n\n";

    $items = \App\Domain\Financial\Models\PaymentScheduleItem::where('payable_type', get_class($pi))
        ->where('payable_id', $pi->id)
        ->get();

    foreach ($items as $item) {
        $output .= "Item #{$item->id} | {$item->label} | {$item->percentage}% | amount: {$item->amount} | status: {$item->status->value} | paid: {$item->paid_amount} | remaining: {$item->remaining_amount}\n";

        $allocs = $item->allocations()->with('payment')->get();
        foreach ($allocs as $a) {
            $paymentStatus = $a->payment ? $a->payment->status->value : 'PAYMENT_MISSING';
            $output .= "  -> Alloc #{$a->id} | payment #{$a->payment_id} | payment_status: {$paymentStatus} | alloc_amount: {$a->allocated_amount} | in_doc_currency: {$a->allocated_amount_in_document_currency}\n";
        }
        $output .= "\n";
    }

    return response($output, 200, ['Content-Type' => 'text/plain']);
});

// TEMPORARY FIX ROUTE - REMOVE AFTER USE
Route::get('/debug/pi-fix-00019/{token}', function (string $token) {
    if ($token !== 'impex2026debug') {
        abort(403);
    }

    $output = "";

    // 1. Delete orphan allocation #41 (payment #11 was deleted)
    $orphan = \App\Domain\Financial\Models\PaymentAllocation::find(41);
    if ($orphan) {
        $orphan->delete();
        $output .= "DELETED orphan allocation #41 (payment_id: 11)\n";
    } else {
        $output .= "Allocation #41 not found (already cleaned)\n";
    }

    // 2. Fix schedule item #476 — remaining is 30 (0.30 cents rounding diff)
    $item = \App\Domain\Financial\Models\PaymentScheduleItem::find(476);
    if ($item) {
        $item->refresh();
        $paid = $item->paid_amount;
        $remaining = $item->remaining_amount;
        $output .= "\nItem #476 recalculated: paid={$paid} remaining={$remaining}\n";

        if ($remaining <= 100) { // less than 1.00 in minor units — rounding tolerance
            $item->update(['status' => 'paid']);
            $output .= "STATUS UPDATED to PAID (rounding tolerance: {$remaining})\n";
        } else {
            $output .= "Remaining too large to auto-fix: {$remaining}\n";
        }
    }

    return response($output, 200, ['Content-Type' => 'text/plain']);
});
