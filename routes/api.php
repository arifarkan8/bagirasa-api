<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ItemController;
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

Route::get('/items', [ItemController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Rute terlindungi (wajib token bearer Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'updateProfil']);

    Route::get('/items/saya', [ItemController::class, 'milikSaya']);
    Route::post('/items', [ItemController::class, 'store'])
        ->middleware('throttle:20,1');
    Route::post('/items/{item}', [ItemController::class, 'update']);
    Route::delete('/items/{item}', [ItemController::class, 'destroy']);
});

/*
| Detail item bersifat publik, tetapi token (bila dikirim) tetap dibaca
| supaya lokasi detail dan WhatsApp bisa tampil untuk yang berhak.
| Dipasang setelah /items/saya agar kata "saya" tidak dibaca sebagai {item}.
*/
Route::get('/items/{item}', [ItemController::class, 'show'])
    ->whereNumber('item');