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

    // 1. Delete orphan allocations (payments that were deleted)
    $orphans = \App\Domain\Financial\Models\PaymentAllocation::whereNotIn('payment_id', function ($q) {
        $q->select('id')->from('payments');
    })->get();

    foreach ($orphans as $orphan) {
        $output .= "DELETED orphan allocation #{$orphan->id} (payment_id: {$orphan->payment_id})\n";
        $orphan->delete();
    }
    if ($orphans->isEmpty()) {
        $output .= "No orphan allocations found\n";
    }

    // 2. Recalculate status for item #476 (10% order date)
    $item = \App\Domain\Financial\Models\PaymentScheduleItem::find(476);
    if ($item && $item->status->value !== 'waived') {
        $item->refresh();
        $newStatus = $item->is_paid_in_full ? 'paid' : 'due';
        $item->update(['status' => $newStatus]);
        $output .= "\nItem #476: paid={$item->paid_amount} remaining={$item->remaining_amount} -> status={$newStatus}\n";
    }

    return response($output, 200, ['Content-Type' => 'text/plain']);
});
