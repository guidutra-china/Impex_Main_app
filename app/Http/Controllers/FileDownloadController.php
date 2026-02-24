<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileDownloadController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $path = $request->query('path');

        if (! $path || ! Storage::disk('local')->exists($path)) {
            abort(404, 'File not found.');
        }

        $filename = basename($path);

        return Storage::disk('local')->download($path, $filename);
    }
}
