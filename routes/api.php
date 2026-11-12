<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute publik (tanpa token)
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:5,1');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');

/*
|--------------------------------------------------------------------------
| Rute terlindungi (wajib token bearer Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'updateProfil']);
});