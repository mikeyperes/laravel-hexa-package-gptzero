<?php

use hexa_package_gptzero\Http\Controllers\GptZeroController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('gptzero/settings', [GptZeroController::class, 'settings'])->name('gptzero.settings');
    Route::post('gptzero/settings', [GptZeroController::class, 'saveSettings'])->name('gptzero.settings.save');
    Route::post('gptzero/test', [GptZeroController::class, 'testConnection'])->name('gptzero.test');
    Route::get('raw-gptzero', [GptZeroController::class, 'raw'])->name('gptzero.raw');
    Route::post('gptzero/detect', [GptZeroController::class, 'detect'])->name('gptzero.detect');
});
