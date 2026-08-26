<?php

use App\Http\Controllers\Api\PrintCatalogController;
use App\Http\Controllers\Api\PrintJobSubmissionController;
use App\Http\Controllers\Auth\MicrosoftCallbackController;
use App\Http\Controllers\Auth\MicrosoftRedirectController;
use App\Http\Controllers\LabelPreviewController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::middleware(['guest', 'throttle:10,1'])->group(function (): void {
    Route::get('auth/microsoft', MicrosoftRedirectController::class)->name('auth.microsoft.redirect');
    Route::get('auth/microsoft/callback', MicrosoftCallbackController::class)->name('auth.microsoft.callback');
});
Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('print', 'print')->name('print.station');
    Route::get('print/catalog', PrintCatalogController::class)->name('print.catalog')->middleware('throttle:60,1');
    Route::post('print/jobs', PrintJobSubmissionController::class)->name('print.jobs.store')->middleware('throttle:print-submissions');
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::middleware('admin')->group(function (): void {
        Route::view('admin', 'admin.dashboard')->name('admin.dashboard');
        Route::livewire('admin/printers', 'pages::admin.printers')->name('admin.printers');
        Route::livewire('admin/label-stocks', 'pages::admin.label-stocks')->name('admin.label-stocks');
        Route::livewire('admin/label-templates', 'pages::admin.label-templates')->name('admin.label-templates');
        Route::livewire('admin/label-templates/{labelTemplate}/test-print', 'pages::admin.label-test-print')
            ->name('admin.label-template-test-print');
        Route::livewire('admin/label-templates/{labelTemplate}/designer/{labelTemplateVersion?}', 'pages::admin.label-editor')
            ->name('admin.label-template-editor');
        Route::livewire('admin/print-jobs', 'pages::admin.print-jobs')->name('admin.print-jobs');
        Route::livewire('admin/users', 'pages::admin.users')->name('admin.users');
        Route::livewire('admin/employees', 'pages::admin.employees')->name('admin.employees');
    });
    Route::post('label-template-versions/{labelTemplateVersion}/preview', LabelPreviewController::class)
        ->name('label-template-versions.preview');
});

require __DIR__.'/settings.php';
