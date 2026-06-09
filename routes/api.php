<?php

use App\Http\Controllers\Api\Fair\AuthController;
use App\Http\Controllers\Api\Fair\CompanySearchController;
use App\Http\Controllers\Api\Fair\FairCategoryController;
use App\Http\Controllers\Api\Fair\FairCompanyController;
use App\Http\Controllers\Api\Fair\FairSupplierController;
use App\Http\Controllers\Api\Fair\ReferenceDataController;
use App\Http\Controllers\Api\Fair\TradeFairController;
use App\Http\Controllers\Api\Trips\AuthController as TripsAuthController;
use App\Http\Controllers\Api\Trips\CompanySearchController as TripsCompanySearchController;
use App\Http\Controllers\Api\Trips\TripController;
use App\Http\Controllers\Api\Trips\TripReferenceController;
use App\Http\Middleware\EnsureUserIsInternal;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

Route::prefix('fair/v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', EnsureUserIsInternal::class, SetLocale::class])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::get('trade-fairs/active', [TradeFairController::class, 'active']);
        Route::get('trade-fairs/{tradeFair}/companies', [FairCompanyController::class, 'index']);
        Route::get('reference-data', [ReferenceDataController::class, 'index']);
        Route::post('categories', [FairCategoryController::class, 'store']);
        Route::post('companies/search', [CompanySearchController::class, 'search']);
        Route::post('fair-suppliers', [FairSupplierController::class, 'store']);
    });
});

Route::prefix('trips/v1')->group(function () {
    Route::post('auth/login', [TripsAuthController::class, 'login']);

    Route::middleware(['auth:sanctum', EnsureUserIsInternal::class, SetLocale::class])->group(function () {
        Route::post('auth/logout', [TripsAuthController::class, 'logout']);
        Route::get('auth/me', [TripsAuthController::class, 'me']);

        Route::get('reference-data', [TripReferenceController::class, 'index']);
        Route::post('companies/search', [TripsCompanySearchController::class, 'search']);
        Route::get('trips', [TripController::class, 'index']);
        Route::get('trips/recoverable', [TripController::class, 'recoverable']);
        Route::post('trips', [TripController::class, 'store']);
    });
});
