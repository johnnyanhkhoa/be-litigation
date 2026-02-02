<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\TblLitCycleController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Health check
Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'Litigation API is running',
        'timestamp' => now()
    ]);
});

// Litigation Cycle Management
Route::prefix('lit')->group(function () {
    Route::get('/cycles', [TblLitCycleController::class, 'index']);
    Route::post('/cycles', [TblLitCycleController::class, 'store']);
    Route::get('/cycles/{id}', [TblLitCycleController::class, 'show']);
    Route::put('/cycles/{id}', [TblLitCycleController::class, 'update']);
    Route::patch('/cycles/{id}/activate', [TblLitCycleController::class, 'activate']);
    Route::patch('/cycles/{id}/deactivate', [TblLitCycleController::class, 'deactivate']);
    Route::delete('/cycles/{id}', [TblLitCycleController::class, 'destroy']);
});
