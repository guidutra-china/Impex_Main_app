<?php

namespace App\Http\Controllers;

use App\Domain\Infrastructure\Models\DocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentVersionDownloadController extends Controller
{
    public function __invoke(Request $request, DocumentVersion $version): StreamedResponse
    {
        $document = $version->document;

        if (! $document) {
            abort(404, 'Parent document not found.');
        }

        $disk = $document->disk ?? 'local';

        if (! Storage::disk($disk)->exists($version->path)) {
            abort(404, 'File not found on disk.');
        }

        return Storage::disk($disk)->download($version->path, $document->versionDownloadFilename($version));
    }
}
