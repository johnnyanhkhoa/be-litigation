<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\InitiateLitCallRequest;
use App\Models\TblLitAsteriskCallLog;
use App\Services\AsteriskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class LitVoiceCallController extends Controller
{
    protected $asteriskService;

    public function __construct(AsteriskService $asteriskService)
    {
        $this->asteriskService = $asteriskService;
    }

    /**
     * POST /api/lit-voice-call/initiate
     * Initiate a call through Asterisk for Litigation
     */
    public function initiateCall(InitiateLitCallRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();

            Log::info('Initiating Litigation call', [
                'case_id' => $validatedData['caseId'],
                'phone_no' => $validatedData['phoneNo'],
                'user_id' => $validatedData['userId']
            ]);

            // Call Asterisk Microservice
            $asteriskResponse = $this->asteriskService->initiateCall(
                phoneExtension: $validatedData['phoneExtension'],
                phoneNo: $validatedData['phoneNo'],
                moduleName: 'litigation', // ← LITIGATION
                caseId: (string) $validatedData['caseId'],
                username: $validatedData['username'],
                userId: $validatedData['userId'],
                company: 'r2o'
            );

            // Check if Asterisk call was successful
            if (!isset($asteriskResponse['status']) || $asteriskResponse['status'] != '1') {
                Log::error('Asterisk call failed', [
                    'response' => $asteriskResponse
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to initiate call',
                    'error' => $asteriskResponse['message'] ?? 'Unknown error from Asterisk service'
                ], 500);
            }

            $apiCallId = $asteriskResponse['data']['api_call_id'] ?? null;

            if (!$apiCallId) {
                Log::error('No api_call_id in Asterisk response', [
                    'response' => $asteriskResponse
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid response from Asterisk service',
                    'error' => 'Missing api_call_id'
                ], 500);
            }

            // Create call log record
            $callLog = TblLitAsteriskCallLog::create([
                'caseId' => $validatedData['caseId'],
                'phoneNo' => $validatedData['phoneNo'],
                'phoneExtension' => $validatedData['phoneExtension'],
                'userId' => $validatedData['userId'],
                'username' => $validatedData['username'],
                'apiCallId' => (string) $apiCallId,
                'callFrom' => 'litigation',
                'company' => 'r2o',
                'createdAt' => now(),
                'createdBy' => $validatedData['userId'],
            ]);

            Log::info('Litigation call log created', [
                'call_log_id' => $callLog->id,
                'api_call_id' => $apiCallId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Call initiated successfully',
                'data' => [
                    'callLogId' => $callLog->id,
                    'apiCallId' => $apiCallId,
                    'callUrl' => $asteriskResponse['data']['call_url'] ?? null,
                    'asteriskResponse' => $asteriskResponse
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to initiate Litigation call', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate call',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * PUT /api/lit-voice-call/logs/{apiCallId}
     * Update call log (manual update if needed)
     */
    public function updateCallLog(Request $request, string $apiCallId): JsonResponse
    {
        try {
            Log::info('Updating Litigation call log', [
                'api_call_id' => $apiCallId
            ]);

            $callLog = TblLitAsteriskCallLog::where('apiCallId', $apiCallId)
                ->whereNull('deletedAt')
                ->first();

            if (!$callLog) {
                return response()->json([
                    'success' => false,
                    'message' => 'Call log not found',
                ], 404);
            }

            $updateData = [];

            if ($request->has('callStatus')) {
                $updateData['callStatus'] = $request->input('callStatus');
            }
            if ($request->has('handleTimeSec')) {
                $updateData['handleTimeSec'] = $request->input('handleTimeSec');
            }
            if ($request->has('talkTimeSec')) {
                $updateData['talkTimeSec'] = $request->input('talkTimeSec');
            }
            if ($request->has('recordFile')) {
                $updateData['recordFile'] = $request->input('recordFile');
            }
            if ($request->has('asteriskCallId')) {
                $updateData['asteriskCallId'] = $request->input('asteriskCallId');
            }
            if ($request->has('outboundCnum')) {
                $updateData['outboundCnum'] = $request->input('outboundCnum');
            }
            if ($request->has('caseDetailId')) {
                $updateData['caseDetailId'] = $request->input('caseDetailId');
            }

            if (!empty($updateData)) {
                $updateData['updatedAt'] = now();
                $updateData['updatedBy'] = $request->input('userId', $callLog->userId);

                $callLog->update($updateData);

                Log::info('Litigation call log updated', [
                    'call_log_id' => $callLog->id,
                    'updated_fields' => array_keys($updateData)
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Call log updated successfully',
                'data' => [
                    'id' => $callLog->id,
                    'apiCallId' => $callLog->apiCallId,
                    'callStatus' => $callLog->callStatus,
                    'handleTimeSec' => $callLog->handleTimeSec,
                    'talkTimeSec' => $callLog->talkTimeSec,
                    'updatedAt' => $callLog->updatedAt?->utc()->format('Y-m-d\TH:i:s\Z'),
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to update Litigation call log', [
                'api_call_id' => $apiCallId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update call log',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * GET /api/lit-voice-call/logs/{apiCallId}
     * Get call log by apiCallId
     */
    public function getCallLog(string $apiCallId): JsonResponse
    {
        try {
            $callLog = TblLitAsteriskCallLog::where('apiCallId', $apiCallId)
                ->whereNull('deletedAt')
                ->first();

            if (!$callLog) {
                return response()->json([
                    'success' => false,
                    'message' => 'Call log not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Call log retrieved successfully',
                'data' => [
                    'id' => $callLog->id,
                    'caseId' => $callLog->caseId,
                    'phoneNo' => $callLog->phoneNo,
                    'phoneExtension' => $callLog->phoneExtension,
                    'userId' => $callLog->userId,
                    'username' => $callLog->username,
                    'apiCallId' => $callLog->apiCallId,
                    'callDate' => $callLog->callDate?->format('Y-m-d'),
                    'calledAt' => $callLog->calledAt?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'handleTimeSec' => $callLog->handleTimeSec,
                    'talkTimeSec' => $callLog->talkTimeSec,
                    'callStatus' => $callLog->callStatus,
                    'recordFile' => $callLog->recordFile,
                    'asteriskCallId' => $callLog->asteriskCallId,
                    'outboundCnum' => $callLog->outboundCnum,
                    'callFrom' => $callLog->callFrom,
                    'caseDetailId' => $callLog->caseDetailId,
                    'createdAt' => $callLog->createdAt?->utc()->format('Y-m-d\TH:i:s\Z'),
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to get Litigation call log', [
                'api_call_id' => $apiCallId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve call log',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
