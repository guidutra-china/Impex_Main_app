<?php

use App\Http\Controllers\DocumentVersionDownloadController;
use App\Http\Controllers\FileDownloadController;
use App\Http\Controllers\Messaging\DocumentPreviewController;
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

// Inline streaming of message attachments (PDF viewer in-browser etc.).
// Signed URL is minted by MessagingCenter::previewUrlFor and expires in 5 min;
// the controller additionally verifies the authenticated user is a participant.
Route::get('/messaging/documents/{document}/preview', [DocumentPreviewController::class, 'stream'])
    ->name('messaging.documents.preview')
    ->middleware(['auth', 'signed']);

// Fair mobile PWA. The service worker and manifest are served via Laravel
// (not as static files under public/fair-mobile/) so that nginx does not
// shadow the SPA route with a directory-listing 403. The SW lives at
// /fair-mobile/sw.js to get its default scope of /fair-mobile/, which is
// what lets it intercept the SPA's navigations and asset fetches.
Route::get('/fair-mobile/sw.js', function () {
    return response()->file(resource_path('pwa/sw.js'), [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Service-Worker-Allowed' => '/fair-mobile/',
    ]);
})->name('fair-mobile.sw');

Route::get('/fair-mobile/manifest.json', function () {
    return response()->file(resource_path('pwa/manifest.json'), [
        'Content-Type' => 'application/manifest+json; charset=utf-8',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('fair-mobile.manifest');

// SPA shell — catch-all so client-side routing works.
Route::get('/fair-mobile/{any?}', function () {
    return view('fair-mobile.app', \App\Domain\Settings\Support\CompanyBranding::forView());
})->where('any', '.*')->name('fair-mobile');

// Trips mobile PWA. Same serving strategy as fair-mobile: the service worker
// and manifest are served via Laravel (not as static files under
// public/trips-mobile/) so nginx does not shadow the SPA route. The SW lives at
// /trips-mobile/sw.js to get its default scope of /trips-mobile/.
Route::get('/trips-mobile/sw.js', function () {
    return response()->file(resource_path('pwa/trips/sw.js'), [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Service-Worker-Allowed' => '/trips-mobile/',
    ]);
})->name('trips-mobile.sw');

Route::get('/trips-mobile/manifest.json', function () {
    return response()->file(resource_path('pwa/trips/manifest.json'), [
        'Content-Type' => 'application/manifest+json; charset=utf-8',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('trips-mobile.manifest');

// SPA shell — catch-all so client-side routing works. Company branding is
// injected so the logo shows at the top of the app.
Route::get('/trips-mobile/{any?}', function () {
    return view('trips-mobile.app', \App\Domain\Settings\Support\CompanyBranding::forView());
})->where('any', '.*')->name('trips-mobile');
