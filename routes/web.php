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

// TEMPORARY FIX ROUTE - REMOVE AFTER USE
Route::get('/debug/fix-pi19-duplicates/{token}', function (string $token) {
    if ($token !== 'impex2026debug') {
        abort(403);
    }

    $output = "";

    // Remove duplicate shipment-specific items where the base item is already paid
    $pi = \App\Domain\ProformaInvoices\Models\ProformaInvoice::where('reference', 'like', '%00019%')->first();
    if (! $pi) {
        return response('PI not found', 404);
    }

    // Find base items (no shipment_id) that are paid or have allocations
    $paidBaseStageIds = \App\Domain\Financial\Models\PaymentScheduleItem::where('payable_type', get_class($pi))
        ->where('payable_id', $pi->id)
        ->whereNull('shipment_id')
        ->whereNull('source_type')
        ->where(function ($q) {
            $q->whereIn('status', ['paid', 'waived'])
              ->orWhereHas('allocations');
        })
        ->pluck('payment_term_stage_id')
        ->filter()
        ->all();

    $output .= "Paid/allocated base stage IDs: " . implode(', ', $paidBaseStageIds) . "\n\n";

    // Find shipment-specific duplicates for those stages that have no allocations
    $duplicates = \App\Domain\Financial\Models\PaymentScheduleItem::where('payable_type', get_class($pi))
        ->where('payable_id', $pi->id)
        ->whereNotNull('shipment_id')
        ->whereIn('payment_term_stage_id', $paidBaseStageIds)
        ->whereDoesntHave('allocations')
        ->whereNotIn('status', ['paid', 'waived'])
        ->get();

    foreach ($duplicates as $dup) {
        $output .= "DELETED duplicate #{$dup->id} | {$dup->label} | status: {$dup->status->value}\n";
        $dup->delete();
    }

    if ($duplicates->isEmpty()) {
        $output .= "No duplicates found\n";
    }

    // Show final state
    $output .= "\n--- Final Schedule ---\n";
    $items = \App\Domain\Financial\Models\PaymentScheduleItem::where('payable_type', get_class($pi))
        ->where('payable_id', $pi->id)
        ->get();
    foreach ($items as $item) {
        $output .= "Item #{$item->id} | {$item->label} | {$item->percentage}% | amount: {$item->amount} | status: {$item->status->value}\n";
    }

    return response($output, 200, ['Content-Type' => 'text/plain']);
});
