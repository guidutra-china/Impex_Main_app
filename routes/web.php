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
