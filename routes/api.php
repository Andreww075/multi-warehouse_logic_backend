<?php

use App\Http\Controllers\InventoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// F1 · RF-01/RF-02: autenticación Sanctum obligatoria + correlación RF-18.
Route::post('/v1/inventories/deduct', [InventoryController::class, 'deduct'])
    ->middleware(['auth:sanctum', 'correlation']);
