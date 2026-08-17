<?php

use App\Http\Controllers\LabelPreviewController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::view('print', 'print')->name('print.station');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::post('label-template-versions/{labelTemplateVersion}/preview', LabelPreviewController::class)
        ->name('label-template-versions.preview');
});

require __DIR__.'/settings.php';
