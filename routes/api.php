<?php

use App\Http\Controllers\Api\Fair\AuthController;
use App\Http\Controllers\Api\Fair\CompanySearchController;
use App\Http\Controllers\Api\Fair\FairSupplierController;
use App\Http\Controllers\Api\Fair\ReferenceDataController;
use App\Http\Controllers\Api\Fair\TradeFairController;
use App\Http\Middleware\EnsureUserIsInternal;
use Illuminate\Support\Facades\Route;

Route::prefix('fair/v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', EnsureUserIsInternal::class])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::get('trade-fairs/active', [TradeFairController::class, 'active']);
        Route::get('reference-data', [ReferenceDataController::class, 'index']);
        Route::post('companies/search', [CompanySearchController::class, 'search']);
        Route::post('fair-suppliers', [FairSupplierController::class, 'store']);
    });
});
