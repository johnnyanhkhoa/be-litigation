<?php

use App\Http\Controllers\API\TblLitControllerLtoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\TblLitCycleController;

// Health check
Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'Litigation API is running',
        'timestamp' => now()
    ]);
});

// Litigation Cycle Management + Analytics
Route::prefix('lit')->group(function () {
    // Cycles
    Route::get('/cycles', [TblLitCycleController::class, 'index']);
    Route::post('/cycles', [TblLitCycleController::class, 'store']);
    Route::get('/cycles/{id}', [TblLitCycleController::class, 'show']);
    Route::put('/cycles/{id}', [TblLitCycleController::class, 'update']);
    Route::patch('/cycles/{id}/activate', [TblLitCycleController::class, 'activate']);
    Route::patch('/cycles/{id}/deactivate', [TblLitCycleController::class, 'deactivate']);
    Route::delete('/cycles/{id}', [TblLitCycleController::class, 'destroy']);

    // ✅ Analytics (ĐẶTT TRƯỚC phone-collections group)
    Route::get('/phone-collections/analytics-summary', [
        \App\Http\Controllers\API\LitAnalyticsController::class,
        'getAnalyticsSummary'
    ]);
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

    Route::get('/{litPhoneCollectionId}/payment-info', [\App\Http\Controllers\API\TblLitPhoneCollectionController::class, 'getPaymentInfo']);
    Route::get('/{litPhoneCollectionId}/contract', [\App\Http\Controllers\API\TblLitPhoneCollectionController::class, 'getContractDetails']);
    Route::patch('/{litPhoneCollectionId}/complete', [\App\Http\Controllers\API\TblLitPhoneCollectionController::class, 'markAsCompleted']);

    // Export reports
    Route::post('/export-daily-call-report', [\App\Http\Controllers\API\TblLitPhoneCollectionController::class, 'exportDailyCallReport']);
    Route::post('/export-call-assign-report', [\App\Http\Controllers\API\TblLitPhoneCollectionController::class, 'exportCallAssignReport']);
});

// Litigation Call Attempts Routes
Route::prefix('lit-attempts')->group(function () {
    Route::get('/{litPhoneCollectionId}', [\App\Http\Controllers\API\TblLitPhoneCollectionDetailController::class, 'getCallAttempts']);
});

// Litigation Phone Collection Detail Routes
Route::prefix('lit-phone-collection-details')->group(function () {
    Route::get('/case-results', [\App\Http\Controllers\API\TblLitPhoneCollectionDetailController::class, 'getCaseResults']);
    Route::get('/contract/{contractId}/remarks', [\App\Http\Controllers\API\TblLitPhoneCollectionDetailController::class, 'getRemarksByContract']);
    Route::post('/', [\App\Http\Controllers\API\TblLitPhoneCollectionDetailController::class, 'store']);
});

// Litigation Voice Call Routes
Route::prefix('lit-voice-call')->group(function () {
    Route::post('/initiate', [\App\Http\Controllers\API\LitVoiceCallController::class, 'initiateCall']);
    Route::get('/logs/{apiCallId}', [\App\Http\Controllers\API\LitVoiceCallController::class, 'getCallLog']);
    Route::put('/logs/{apiCallId}', [\App\Http\Controllers\API\LitVoiceCallController::class, 'updateCallLog']);
});

// Litigation Contract Routes
Route::prefix('lit-contracts')->group(function () {
    Route::get('/{contractId}/litigation-journals', [\App\Http\Controllers\API\TblLitContractController::class, 'getLitigationJournals']);
});

// Litigation Monitoring Routes
Route::prefix('monitoring')->group(function () {
    Route::get('/lit-controller/{authUserId}', [\App\Http\Controllers\API\LitMonitoringController::class, 'monitorSingleLitController']);
    Route::get('/lit-controllers', [\App\Http\Controllers\API\LitMonitoringController::class, 'monitorAllLitControllers']);
});
