<?php

use App\Http\Controllers\Api\PrintBridgeController;
use App\Http\Middleware\AuthenticatePrintBridge;
use Illuminate\Support\Facades\Route;

Route::prefix('bridge')->middleware(AuthenticatePrintBridge::class)->group(function (): void {
    Route::post('heartbeat', [PrintBridgeController::class, 'heartbeat']);
    Route::post('jobs/claim', [PrintBridgeController::class, 'claim']);
    Route::post('jobs/{printJob}/complete', [PrintBridgeController::class, 'complete']);
    Route::post('jobs/{printJob}/fail', [PrintBridgeController::class, 'fail']);
});
