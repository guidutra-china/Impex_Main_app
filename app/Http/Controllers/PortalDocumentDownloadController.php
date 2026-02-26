<?php

namespace App\Http\Controllers;

use App\Domain\Infrastructure\Models\Document;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortalDocumentDownloadController extends Controller
{
    private const ALLOWED_TYPES = [
        'commercial_invoice_pdf',
        'packing_list_pdf',
        'proforma_invoice_pdf',
    ];

    public function __invoke(Request $request, Document $document): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user && $user->can('portal:download-documents'), 403);
        abort_unless(in_array($document->type, self::ALLOWED_TYPES), 403);

        $companyId = $user->company_id;
        abort_unless($companyId, 403);

        $isOwned = $this->documentBelongsToCompany($document, $companyId);
        abort_unless($isOwned, 403);

        abort_unless($document->exists(), 404, 'File not found.');

        return Storage::disk($document->disk)->download(
            $document->path,
            $document->name . '.pdf'
        );
    }

    private function documentBelongsToCompany(Document $document, int $companyId): bool
    {
        if ($document->documentable_type === Shipment::class) {
            return Shipment::where('id', $document->documentable_id)
                ->where('company_id', $companyId)
                ->exists();
        }

        if ($document->documentable_type === ProformaInvoice::class) {
            return ProformaInvoice::where('id', $document->documentable_id)
                ->where('company_id', $companyId)
                ->exists();
        }

        return false;
    }
}
