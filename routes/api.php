<?php

use App\Http\Controllers\AiController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SensorController;
use App\Http\Controllers\AuthController;


Route::post('/sensor', [SensorController::class, 'store']);
Route::get('/sensor/latest', [SensorController::class, 'latest']);
Route::get('/sensor/history', [SensorController::class, 'history']);
Route::post('/auth/login', [AuthController::class, 'login']);


Route::prefix('ai')->group(function () {
    Route::get('/insights', [AiController::class, 'getInsights']);
    Route::get('/predict', [AiController::class, 'getPrediction']);
    Route::get('/chat', [AiController::class, 'chat']);
});
