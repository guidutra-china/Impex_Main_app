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

// Fair mobile PWA shell. The Alpine SPA handles all client-side routing,
// so any /fair-mobile/* path returns the same Blade view that boots the app.
Route::view('/fair-mobile/{any?}', 'fair-mobile.app')
    ->where('any', '.*')
    ->name('fair-mobile');
