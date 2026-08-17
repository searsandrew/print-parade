<?php

use App\Http\Controllers\Api\PrintBridgeController;
use App\Http\Controllers\Api\PrintCatalogController;
use App\Http\Controllers\Api\PrintJobSubmissionController;
use App\Http\Middleware\AuthenticatePrintBridge;
use Illuminate\Support\Facades\Route;

Route::prefix('bridge')->middleware(AuthenticatePrintBridge::class)->group(function (): void {
    Route::post('heartbeat', [PrintBridgeController::class, 'heartbeat']);
    Route::post('jobs/claim', [PrintBridgeController::class, 'claim']);
    Route::post('jobs/{printJob}/complete', [PrintBridgeController::class, 'complete']);
    Route::post('jobs/{printJob}/fail', [PrintBridgeController::class, 'fail']);
});

Route::post('print-jobs', PrintJobSubmissionController::class)
    ->middleware('throttle:print-submissions');

Route::get('print-catalog', PrintCatalogController::class)
    ->middleware('throttle:60,1');
