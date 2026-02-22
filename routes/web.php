<?php

use App\Http\Controllers\DocumentVersionDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/documents/versions/{version}/download', DocumentVersionDownloadController::class)
    ->name('document-version.download')
    ->middleware(['auth', 'signed']);
