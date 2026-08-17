<?php

use App\Http\Controllers\Api\PrintCatalogController;
use App\Http\Controllers\Api\PrintJobSubmissionController;
use App\Http\Controllers\LabelPreviewController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('print', 'print')->name('print.station');
    Route::get('print/catalog', PrintCatalogController::class)->name('print.catalog')->middleware('throttle:60,1');
    Route::post('print/jobs', PrintJobSubmissionController::class)->name('print.jobs.store')->middleware('throttle:print-submissions');
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::middleware('admin')->group(function (): void {
        Route::view('admin', 'admin.dashboard')->name('admin.dashboard');
        Route::livewire('admin/printers', 'pages::admin.printers')->name('admin.printers');
    });
    Route::post('label-template-versions/{labelTemplateVersion}/preview', LabelPreviewController::class)
        ->name('label-template-versions.preview');
});

require __DIR__.'/settings.php';
