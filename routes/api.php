<?php

use App\Http\Controllers\API\TblLitControllerLtoController;
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

// Litigation Users - Eligible for Assignment
Route::get('/lit/users/eligible', [\App\Http\Controllers\API\TblLitUserController::class, 'getEligibleUsers']);

// Controller-LTO Assignment Management
Route::prefix('lit/controller-assignments')->group(function () {
    Route::get('/', [TblLitControllerLtoController::class, 'index']);
    Route::post('/', [TblLitControllerLtoController::class, 'store']);
    Route::get('/{id}', [TblLitControllerLtoController::class, 'show']);
    Route::put('/{id}', [TblLitControllerLtoController::class, 'update']);
    Route::patch('/{id}/toggle-active', [TblLitControllerLtoController::class, 'toggleActive']);
    Route::delete('/{id}', [TblLitControllerLtoController::class, 'destroy']);
});

// Litigation Phone Collections
Route::prefix('lit/phone-collections')->group(function () {
    Route::get('/', [\App\Http\Controllers\API\TblLitPhoneCollectionController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\API\TblLitPhoneCollectionController::class, 'store']);

    // Get payment info
    Route::get('/{litPhoneCollectionId}/payment-info', [\App\Http\Controllers\API\TblLitPhoneCollectionController::class, 'getPaymentInfo']);

    // Get contract details (must be after /payment-info to avoid route conflict)
    Route::get('/{litPhoneCollectionId}/contract', [\App\Http\Controllers\API\TblLitPhoneCollectionController::class, 'getContractDetails']);

    // Mark as completed (THÊM DÒNG NÀY)
    Route::patch('/{litPhoneCollectionId}/complete', [\App\Http\Controllers\API\TblLitPhoneCollectionController::class, 'markAsCompleted']);
});

// Litigation Call Attempts Routes
Route::prefix('lit-attempts')->group(function () {
    // Get all call attempts for a specific litigation phone collection
    Route::get('/{litPhoneCollectionId}', [\App\Http\Controllers\API\TblLitPhoneCollectionDetailController::class, 'getCallAttempts']);
});

// Litigation Phone Collection Detail Routes
Route::prefix('lit-phone-collection-details')->group(function () {
    // Get all active case results (PHẢI ĐẶT TRƯỚC)
    Route::get('/case-results', [\App\Http\Controllers\API\TblLitPhoneCollectionDetailController::class, 'getCaseResults']);

    // Get remarks by contract (PHẢI ĐẶT TRƯỚC)
    Route::get('/contract/{contractId}/remarks', [\App\Http\Controllers\API\TblLitPhoneCollectionDetailController::class, 'getRemarksByContract']);

    // Create new Litigation phone collection detail (ĐẶT SAU)
    Route::post('/', [\App\Http\Controllers\API\TblLitPhoneCollectionDetailController::class, 'store']);
});

// Litigation Voice Call Routes
Route::prefix('lit-voice-call')->group(function () {
    // Initiate call
    Route::post('/initiate', [\App\Http\Controllers\API\LitVoiceCallController::class, 'initiateCall']);

    // Get call log by apiCallId
    Route::get('/logs/{apiCallId}', [\App\Http\Controllers\API\LitVoiceCallController::class, 'getCallLog']);

    // Update call log (manual)
    Route::put('/logs/{apiCallId}', [\App\Http\Controllers\API\LitVoiceCallController::class, 'updateCallLog']);
});

// Litigation Contract Routes
Route::prefix('lit-contracts')->group(function () {
    // Get litigation journals
    Route::get('/{contractId}/litigation-journals', [\App\Http\Controllers\API\TblLitContractController::class, 'getLitigationJournals']);
});
