<?php

use App\Http\Controllers\AiController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SensorController;

Route::post('/sensor', [SensorController::class, 'store']);
Route::get('/sensor/latest', [SensorController::class, 'latest']);
Route::get('/sensor/history', [SensorController::class, 'history']);

Route::prefix('ai')->group(function () {
    Route::get('/insights', [AiController::class, 'getInsights']);
    Route::get('/predict', [AiController::class, 'getPrediction']);
    Route::get('/chat', [AiController::class, 'chat']);
});
