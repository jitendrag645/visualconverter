<?php

use App\Http\Controllers\Api\ConversionController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.token')->prefix('v1')->group(function () {
    Route::post('/convert', [ConversionController::class, 'submit']);
    Route::get('/jobs/{uuid}', [ConversionController::class, 'status']);
});
